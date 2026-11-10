<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Control de acceso a las rutas.
 *
 * Aquí se resuelve el requisito de que los códigos QR funcionen desde fuera de
 * la aplicación. Alguien apunta la cámara a un carnet o al pliego de la puerta:
 * el teléfono abre la URL sin ningún contexto previo. Si no hay sesión, esta
 * clase no responde «no autorizado» —que dejaría a la persona en un callejón
 * sin salida delante de la puerta del evento—, sino que envía al acceso que
 * corresponde recordando a dónde iba, y al terminar la lleva allí.
 *
 * Hay dos accesos distintos y por eso hay dos guardias:
 *   · «asistente»  → acceso por correo con código de un solo uso.
 *   · «admin»      → acceso del equipo, con contraseña y segundo factor.
 *
 * Y uno más que acepta cualquiera de los dos: «acreditar». Lo pasan el equipo
 * con rol operador y las personas con perfil Staff, que entran por la puerta del
 * asistente pero pueden ver los carnets del evento y sellar ingresos.
 */
final class Guardia
{
    public static function exigir(?string $guardia, Peticion $peticion): void
    {
        if ($guardia === null || $guardia === '') {
            return;
        }

        [$tipo, $exigencia] = array_pad(explode(':', $guardia, 2), 2, null);

        match ($tipo) {
            'asistente' => self::asistente($peticion),
            'admin'     => self::admin($peticion, $exigencia),
            'acreditar' => self::acreditar($peticion),
            'instalar'  => self::instalador(),
            default     => null,
        };
    }

    /* =====================================================================
       Acreditación: el equipo, o una persona con perfil Staff
       ===================================================================== */

    /**
     * Pantallas de la puerta: ver los carnets, imprimirlos y sellar ingresos.
     *
     * Entra el equipo con rol operador y entra el Staff. Son dos sesiones
     * distintas y por eso hay que decidir a cuál de las dos puertas mandar a
     * quien llegue sin ninguna: se manda a la del asistente, que es la de la
     * mayoría —el staff de un evento son voluntarios con su carnet, no gente
     * con cuenta del equipo— y desde ahí se puede llegar a la otra.
     */
    private static function acreditar(Peticion $peticion): void
    {
        // Antes que nada: si la base se quedó atrás del código, estas pantallas
        // consultan columnas que todavía no existen y responden 500. En la
        // puerta de un evento, con fila detrás, un 500 sin explicación es lo
        // peor que puede pasar. Se dice qué falta y quién lo arregla.
        [$atrasada, $motivo] = \App\Esquema::revisionPendiente();
        if ($atrasada) {
            Respuesta::error(503, 'Falta actualizar la base de datos',
                $motivo . ' Mientras no se actualice, esta pantalla no puede funcionar: '
                . 'consulta columnas que todavía no existen. Quien administra la plataforma lo '
                . 'resuelve desde el panel, con el botón «Actualizar la base de datos».');
        }

        // Con sesión del equipo se le aplica el guardia del equipo completo, no
        // una comprobación de rol a secas. Ahí viven el segundo factor
        // pendiente, la contraseña que puso otra persona y la cuenta
        // suspendida, y cada uno sabe a qué pantalla mandar a quien llega: un
        // 403 genérico dejaría a un operador recién creado sin entender que lo
        // único que le falta es cambiar su contraseña.
        if (self::usuarioActual() !== null) {
            self::admin($peticion, 'operador');
            return;
        }

        if (self::staffActual() !== null) {
            return;
        }

        // Identificado como asistente pero sin el perfil: no es un problema de
        // identificarse, así que mandarlo otra vez al acceso lo dejaría dando
        // vueltas sin entender por qué.
        if (self::personaActual() !== null) {
            Respuesta::error(403, 'No tienes permiso',
                'Esta sección es para el equipo organizador y para quienes tienen el perfil '
                . 'Staff. Si crees que te corresponde, pídeselo a la administración del evento.');
        }

        self::pedirIdentificacion($peticion, '/entrar');
    }

    /** La persona con sesión abierta, si su perfil es Staff. */
    public static function staffActual(): ?array
    {
        $persona = self::personaActual();

        // Con el registro a medias no se acredita a nadie: la pantalla enseña
        // cédulas, y quien no ha dado ni la suya no ha terminado de entrar.
        if ($persona === null
            || !\App\Modelos\Persona::esStaff($persona)
            || !\App\Modelos\Persona::registroCompleto($persona)) {
            return null;
        }
        return $persona;
    }

    /** ¿Puede ver los carnets y sellar ingresos? Para las vistas y las rutas. */
    public static function puedeAcreditar(): bool
    {
        if (self::staffActual() !== null) {
            return true;
        }
        $usuario = self::equipoOperativo();
        return $usuario !== null && self::tieneRol($usuario, 'operador');
    }

    /**
     * Quién está acreditando, para firmar el sello y contar sus escaneos.
     *
     * El equipo manda sobre el staff cuando las dos sesiones están abiertas en
     * el mismo navegador: pasa en la puerta, con el teléfono de un organizador
     * que además está registrado como asistente, y el sello tiene que quedar a
     * nombre de la cuenta con más responsabilidad.
     *
     * @return array{tipo:string, id:int, nombre:string}|null
     */
    public static function acreditador(): ?array
    {
        $usuario = self::equipoOperativo();
        if ($usuario !== null && self::tieneRol($usuario, 'operador')) {
            return [
                'tipo'   => 'equipo',
                'id'     => (int) $usuario['id'],
                'nombre' => (string) $usuario['nombre'],
                'puesto' => (string) ($usuario['puesto'] ?? ''),
            ];
        }

        $staff = self::staffActual();
        if ($staff !== null) {
            return [
                'tipo'   => 'staff',
                'id'     => (int) $staff['id'],
                'nombre' => (string) $staff['nombre'],
                'puesto' => (string) ($staff['entidad'] ?? ''),
            ];
        }
        return null;
    }

    /* =====================================================================
       Asistente
       ===================================================================== */

    private static function asistente(Peticion $peticion): void
    {
        $persona = self::personaActual();

        if ($persona === null) {
            // Tiene sesión, pero en otro evento, y llegó a una dirección sin
            // evento —un marcador viejo, la dirección escrita a mano—: se la
            // lleva a la misma pantalla de su evento en vez de pedirle que se
            // identifique otra vez.
            $otra = self::$personaDeOtroEvento;
            if ($otra !== null && $peticion->slugEvento() === '' && !App::eventoFijado() && !$peticion->esPost()) {
                $suyo = \App\Modelos\Evento::porId((int) $otra['evento_id']);
                if ($suyo !== null) {
                    $consulta = $_SERVER['QUERY_STRING'] ?? '';
                    Respuesta::redirigirAbsoluto(Url::deEvento($suyo, $peticion->ruta())
                        . ($consulta !== '' ? '?' . $consulta : ''));
                }
            }
            self::pedirIdentificacion($peticion, '/entrar');
        }

        // Tiene acceso pero no ha llenado el formulario: creó su cuenta con
        // correo y contraseña desde la pantalla de ingreso. Todo lo que hay
        // detrás de este guardia necesita al menos su nombre y su documento
        // —el carnet, la ficha de acreditación, el intercambio de contacto—,
        // así que se le lleva a terminar en vez de enseñarle pantallas vacías.
        if (!\App\Modelos\Persona::registroCompleto($persona)) {
            if ($peticion->esAjax()) {
                Respuesta::json(['error' => 'registro', 'ir' => Url::a('/registro')], 409);
            }
            Respuesta::redirigir('/registro',
                'Completa tu nombre y tu identificación y te emitimos el carnet.', 'warn');
        }
    }

    /** Devuelve la persona con sesión abierta, o null. */
    public static function personaActual(): ?array
    {
        $sesion = Sesion::actual('asistente');

        // Sin sesión, pero puede que este teléfono ya haya entrado antes.
        //
        // Es el caso que más se da en la puerta: la persona se preregistró
        // desde el navegador y ahora abre el enlace desde el correo o desde
        // WhatsApp, que usan su propio almacén de cookies, o simplemente
        // pasaron los treinta días de la sesión. Sin esto acababa en la
        // pantalla de «identifícate» con el carnet ya emitido.
        if (!$sesion) {
            $personaId = Dispositivo::restaurar();
            if ($personaId === null) {
                return null;
            }
            $sesion = ['sujeto_id' => $personaId];
        }

        $persona = Bd::fila('SELECT * FROM {persona} WHERE id = ?', [$sesion['sujeto_id']]);
        if (!$persona) {
            // La persona ya no existe: la sesión sobra.
            Sesion::cerrar('asistente');
            return null;
        }

        // La sesión es de su evento y de ningún otro (1.17.0): los eventos
        // funcionan cada uno por su cuenta, con sus propios registros. En las
        // páginas de otro evento, quien entró en este es un visitante más.
        $evento = App::eventoActivo();
        if ($evento !== null && (int) $persona['evento_id'] !== (int) $evento['id']) {
            self::$personaDeOtroEvento = $persona;
            return null;
        }
        return $persona;
    }

    /** La persona con sesión abierta en otro evento, si personaActual() la descartó por eso. */
    private static ?array $personaDeOtroEvento = null;

    /* =====================================================================
       Sin municipio, a «Mis datos»
       ===================================================================== */

    /** La marca, en los datos de la sesión, del día en que ya se le pidió. */
    private const MUNICIPIO_PEDIDO = 'municipio_pedido';

    /**
     * Quien no ha registrado su municipio es llevada a «Mis datos» al entrar
     * (3.19), si el formulario del evento lo pide.
     *
     * Se llama al llegar a su carnet —que es a donde lleva el ingreso, con
     * cualquier método— y a la portada. Una sola vez al día: en la puerta lo
     * que tiene que enseñar es el carnet, y desde «Mis datos» llega a él con un
     * toque sin que esto la vuelva a sacar. La marca va en la sesión, así que
     * cada vez que inicia sesión se le pide otra vez. Un destino concreto —el QR
     * de la jornada, el carnet de otra persona— no pasa por aquí: esa acción va
     * primero, y el registro de su ingreso no puede esperar a un formulario.
     */
    public static function pedirMunicipio(array $persona): void
    {
        if (!\App\Modelos\Persona::registroCompleto($persona)
            || !\App\Modelos\Persona::faltaMunicipio($persona, \App\Modelos\Formulario::delEvento((int) $persona['evento_id']))) {
            return;
        }
        // Sin sesión donde anotarlo no se manda: se la mandaría cada vez.
        $sesion = Sesion::actual('asistente');
        if ($sesion === null || ($sesion['datos'][self::MUNICIPIO_PEDIDO] ?? '') === date('Y-m-d')) {
            return;
        }
        self::municipioPedido();

        // Lo que traía —«Bienvenido de nuevo…»— no se pierde: va delante. Ya se
        // leyó de su cookie al arrancar (App::compartirConVistas).
        $traia = Respuesta::compartidas()['aviso'] ?? null;
        Respuesta::redirigir('/registro#ubicacion', trim((string) ($traia['mensaje'] ?? '') . ' Te falta registrar tu '
            . 'municipio: elígelo aquí y guarda los cambios. Tu carnet sigue en «Mi carnet».'), 'warn');
    }

    /**
     * Anota que hoy ya se le pidió el municipio. También cuando abre o guarda
     * «Mis datos» por su cuenta: ya vio lo que le falta, y llevarla otra vez
     * al mismo formulario solo le estorbaría.
     */
    public static function municipioPedido(): void
    {
        $datos = Sesion::datos('asistente');
        if (($datos[self::MUNICIPIO_PEDIDO] ?? '') !== date('Y-m-d')) {
            Sesion::guardarDatos('asistente', [self::MUNICIPIO_PEDIDO => date('Y-m-d')] + $datos);
        }
    }

    /* =====================================================================
       Equipo organizador
       ===================================================================== */

    private static function admin(Peticion $peticion, ?string $rolExigido): void
    {
        $usuario = self::usuarioActual();

        if ($usuario === null) {
            self::pedirIdentificacion($peticion, '/admin/entrar');
        }

        // Con la contraseña verificada pero el segundo factor pendiente, la
        // sesión existe pero no sirve para nada más que terminar de entrar.
        $datos = Sesion::datos('admin');
        if (!empty($datos['pendiente_2fa'])) {
            Respuesta::redirigir('/admin/verificar');
        }

        // Y si el segundo factor es obligatorio para este rol pero la cuenta
        // todavía no lo tiene puesto, tampoco se pasa de aquí. El acceso ya
        // enviaba a la pantalla de alta, pero solo eso: quien escribía /admin
        // en la barra de direcciones entraba al panel completo sin activarlo,
        // que es exactamente lo que se quería impedir.
        if (\App\Modelos\Usuario::exigeSegundoFactor($usuario)
            && !\App\Modelos\Usuario::tieneSegundoFactor($usuario)
            && Config::obtener('exigir_2fa_admin', true)) {
            Respuesta::redirigir('/admin/activar-2fa');
        }

        // Con una contraseña puesta por otra persona no se trabaja: se cambia
        // primero. Hasta ahora la marca se guardaba y no la miraba nadie.
        if ((int) $usuario['debe_cambiar'] === 1) {
            Respuesta::redirigir('/admin/clave');
        }

        if ($usuario['estado'] !== 'activo') {
            Sesion::cerrar('admin');
            Respuesta::error(403, 'Cuenta suspendida',
                'Tu cuenta está suspendida. Comunícate con la administración del evento.');
        }

        // Cada evento funciona por su cuenta (1.17.0): las pantallas del equipo
        // trabajan sobre el evento que se eligió al entrar, y el rol que cuenta
        // es el de ese evento. Las que no son de ningún evento —la cuenta
        // propia, la lista de eventos, el correo— miran el rol más alto.
        $ruta = $peticion->ruta();
        if (self::rutaPorEvento($ruta)) {
            $evento = self::eventoDelEquipo() ?? self::elegirSiEsUnoSolo($usuario);
            if ($evento === null) {
                self::pedirEvento($peticion, $usuario);
            }
            $eventoDelRol = (int) $evento['id'];
        } elseif (self::rutaDelEquipo($ruta)) {
            $eventoDelRol = null;
        } else {
            // Fuera de /admin —acreditar en la puerta, los carnets, el carnet
            // escaneado—, el evento es el de la página o el del token.
            $eventoDelRol = isset(App::eventoActivo()['id']) ? (int) App::eventoActivo()['id'] : null;
        }

        if ($rolExigido === 'global') {
            if (!self::esAdministradorGlobal($usuario)) {
                Bitacora::registrar('acceso_denegado', 'seguridad', (int) $usuario['id'], [
                    'ruta' => $peticion->ruta(),
                    'rol_exigido' => 'administrador global',
                ]);
                Respuesta::error(403, 'No tienes permiso',
                    'Esta sección es de la administración global de la plataforma: crear y eliminar eventos, el '
                    . 'correo y la actualización de la base. Pídeselo a quien administra la plataforma.');
            }
            return;
        }

        if ($rolExigido !== null && !self::tieneRol($usuario, $rolExigido, $eventoDelRol)) {
            Bitacora::registrar('acceso_denegado', 'seguridad', (int) $usuario['id'], [
                'ruta' => $peticion->ruta(),
                'rol_exigido' => $rolExigido,
            ]);
            Respuesta::error(403, 'No tienes permiso',
                'Tu rol en la plataforma no incluye esta sección.');
        }
    }

    public static function usuarioActual(): ?array
    {
        $sesion = Sesion::actual('admin');
        if (!$sesion) {
            return null;
        }
        $usuario = Bd::fila('SELECT * FROM {usuario} WHERE id = ?', [$sesion['sujeto_id']]);
        if (!$usuario) {
            Sesion::cerrar('admin');
            return null;
        }
        return $usuario;
    }

    /**
     * El miembro del equipo, pero solo si terminó de identificarse.
     *
     * usuarioActual() dice quién abrió sesión; esto dice si esa sesión sirve
     * para trabajar. La diferencia importa en las rutas que no llevan el
     * guardia 'admin' y comprueban el rol por su cuenta —el escaneo de un
     * carnet, sin ir más lejos—: ahí entraban cuentas con el segundo factor a
     * medias y cuentas suspendidas, y lo que se ve al otro lado es la ficha de
     * acreditación con la cédula de una persona.
     */
    public static function equipoOperativo(): ?array
    {
        $usuario = self::usuarioActual();
        if ($usuario === null || $usuario['estado'] !== 'activo') {
            return null;
        }
        if (!empty(Sesion::datos('admin')['pendiente_2fa'])) {
            return null;
        }
        // Con una contraseña puesta por otra persona no se trabaja. El guardia
        // 'admin' lo comprobaba desde siempre, pero esta función no, y por aquí
        // pasan las rutas que no lo llevan: escanear un carnet es una de ellas,
        // y al otro lado está la cédula de alguien.
        if ((int) $usuario['debe_cambiar'] === 1) {
            return null;
        }
        if (\App\Modelos\Usuario::exigeSegundoFactor($usuario)
            && !\App\Modelos\Usuario::tieneSegundoFactor($usuario)
            && Config::obtener('exigir_2fa_admin', true)) {
            return null;
        }
        return $usuario;
    }

    /**
     * Jerarquía de permisos.
     *
     * El administrador puede todo lo del operador y lo de consulta; el operador
     * puede lo de consulta. Se escribe explícito y no con números para que al
     * leer una ruta se entienda quién entra sin tener que buscar una tabla.
     */
    private const JERARQUIA = [
        'administrador' => ['administrador', 'operador', 'consulta'],
        'operador'      => ['operador', 'consulta'],
        'consulta'      => ['consulta'],
    ];

    /**
     * ¿Tiene ese rol —o uno que lo incluye— en el evento?
     *
     * Sin decir el evento, el de esta petición (App::eventoActivo()). Con
     * null, ninguno en particular: vale el rol más alto que tenga en
     * cualquiera, que es lo que miran las pantallas que no son de un evento.
     */
    public static function tieneRol(array $usuario, string $rolExigido, int|null|false $eventoId = false): bool
    {
        if ($eventoId === false) {
            $eventoId = isset(App::eventoActivo()['id']) ? (int) App::eventoActivo()['id'] : null;
        }
        $rol = self::rolEn($usuario, $eventoId);
        return $rol !== null && in_array($rolExigido, self::JERARQUIA[$rol] ?? [], true);
    }

    /** Para las vistas: ¿le muestro este botón? */
    public static function puede(string $rol): bool
    {
        $usuario = self::usuarioActual();
        return $usuario !== null && self::tieneRol($usuario, $rol);
    }

    /* =====================================================================
       Roles por evento (1.17.0)
       ===================================================================== */

    /** @var array<string, ?string> rol de cada cuenta en cada evento, en esta petición */
    private static array $roles = [];

    /**
     * ¿La cuenta vale en todos los eventos? Las de antes de la 1.17.0 sí: así
     * nadie pierde lo que veía al actualizar.
     */
    public static function esGlobal(array $usuario): bool
    {
        return ($usuario['alcance'] ?? 'global') !== 'eventos';
    }

    /** El administrador global: crea y elimina eventos, todas las cuentas, el correo. */
    public static function esAdministradorGlobal(array $usuario): bool
    {
        return self::esGlobal($usuario) && ($usuario['rol'] ?? '') === 'administrador';
    }

    /**
     * El rol de la cuenta en un evento, o null si no trabaja en él. Con null
     * como evento, el más alto que tenga en cualquiera.
     */
    public static function rolEn(array $usuario, ?int $eventoId): ?string
    {
        $rol = (string) ($usuario['rol'] ?? '');
        if (self::esGlobal($usuario)) {
            return isset(self::JERARQUIA[$rol]) ? $rol : null;
        }
        $clave = (int) $usuario['id'] . ':' . ($eventoId ?? '*');
        if (array_key_exists($clave, self::$roles)) {
            return self::$roles[$clave];
        }
        try {
            if ($eventoId === null) {
                $filas = Bd::filas('SELECT rol FROM {usuario_evento} WHERE usuario_id = ?', [(int) $usuario['id']]);
                $suyo = null;
                foreach (['administrador', 'operador', 'consulta'] as $candidato) {
                    if (in_array($candidato, array_column($filas, 'rol'), true)) {
                        $suyo = $candidato;
                        break;
                    }
                }
            } else {
                $suyo = Bd::valor('SELECT rol FROM {usuario_evento} WHERE usuario_id = ? AND evento_id = ?',
                    [(int) $usuario['id'], $eventoId]);
            }
        } catch (\Throwable) {
            $suyo = null;
        }
        $suyo = is_string($suyo) && isset(self::JERARQUIA[$suyo]) ? $suyo : null;
        return self::$roles[$clave] = $suyo;
    }

    /** Olvida los roles leídos: después de cambiar el equipo de un evento. */
    public static function olvidarRoles(): void
    {
        self::$roles = [];
        self::$eventoEquipoLeido = false;
        self::$eventoEquipo = null;
    }

    /** El rol en ese evento de quien tiene sesión del equipo y terminó de entrar, o null. */
    public static function rolDelEquipoEn(int $eventoId): ?string
    {
        $usuario = self::equipoOperativo();
        return $usuario === null ? null : self::rolEn($usuario, $eventoId);
    }

    /**
     * Los eventos en los que trabaja: todos si es global; si no, los suyos.
     * Con el rol de cada uno en 'rol_equipo'.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function eventosDe(array $usuario): array
    {
        $eventos = \App\Modelos\Evento::todos();
        if (self::esGlobal($usuario)) {
            return array_map(static fn(array $e): array => $e + ['rol_equipo' => (string) $usuario['rol']], $eventos);
        }
        $suyos = [];
        foreach ($eventos as $evento) {
            $rol = self::rolEn($usuario, (int) $evento['id']);
            if ($rol !== null) {
                $suyos[] = $evento + ['rol_equipo' => $rol];
            }
        }
        return $suyos;
    }

    /* =====================================================================
       El evento que eligió el equipo al entrar (1.17.0)
       ===================================================================== */

    private const EVENTO_ELEGIDO = 'evento_id';

    private static ?array $eventoEquipo = null;
    private static bool $eventoEquipoLeido = false;

    /**
     * Las pantallas del equipo que no son de un evento: entrar y salir, la
     * cuenta propia, la lista de eventos y la elección de uno, el correo y la
     * actualización de la base.
     */
    private const RUTAS_SIN_EVENTO = [
        '/admin/entrar', '/admin/verificar', '/admin/activar-2fa', '/admin/salir', '/admin/clave',
        '/admin/recuperar', '/admin/evento', '/admin/eventos', '/admin/cuenta', '/admin/autenticacion',
        '/admin/correo', '/admin/actualizar-esquema',
    ];

    /**
     * Las rutas del equipo: /admin y los QR de las jornadas que se pintan en
     * sus pantallas. Trabajan sobre el evento que eligió al entrar.
     */
    public static function rutaDelEquipo(string $ruta): bool
    {
        return $ruta === '/admin' || str_starts_with($ruta, '/admin/') || str_starts_with($ruta, '/medios/qr/dia/');
    }

    public static function rutaPorEvento(string $ruta): bool
    {
        if (!self::rutaDelEquipo($ruta)) {
            return false;
        }
        foreach (self::RUTAS_SIN_EVENTO as $sinEvento) {
            if ($ruta === $sinEvento || str_starts_with($ruta, $sinEvento . '/')) {
                return false;
            }
        }
        return true;
    }

    /**
     * El evento sobre el que trabaja quien tiene sesión del equipo: el que
     * eligió, si todavía existe y todavía trabaja en él. Null si no eligió.
     */
    public static function eventoDelEquipo(): ?array
    {
        if (self::$eventoEquipoLeido) {
            return self::$eventoEquipo;
        }
        self::$eventoEquipoLeido = true;
        $usuario = self::usuarioActual();
        $id = (int) (Sesion::datos('admin')[self::EVENTO_ELEGIDO] ?? 0);
        if ($usuario === null || $id <= 0) {
            return self::$eventoEquipo = null;
        }
        $evento = \App\Modelos\Evento::porId($id);
        if ($evento === null || self::rolEn($usuario, $id) === null) {
            return self::$eventoEquipo = null;
        }
        return self::$eventoEquipo = $evento;
    }

    /** Deja elegido ese evento en la sesión del equipo. False si no trabaja en él. */
    public static function elegirEvento(int $eventoId): bool
    {
        $usuario = self::usuarioActual();
        if ($usuario === null || \App\Modelos\Evento::porId($eventoId) === null || self::rolEn($usuario, $eventoId) === null) {
            return false;
        }
        Sesion::guardarDatos('admin', [self::EVENTO_ELEGIDO => $eventoId] + Sesion::datos('admin'));
        self::$eventoEquipoLeido = false;
        App::refrescarEvento();
        return true;
    }

    /** Con un solo evento no hay nada que elegir: se elige solo. */
    private static function elegirSiEsUnoSolo(array $usuario): ?array
    {
        $eventos = self::eventosDe($usuario);
        if (count($eventos) !== 1 || !self::elegirEvento((int) $eventos[0]['id'])) {
            return null;
        }
        return self::eventoDelEquipo();
    }

    /** Sin evento elegido y con varios para elegir: a la pantalla de elegir, recordando a dónde iba. */
    private static function pedirEvento(Peticion $peticion, array $usuario): never
    {
        if (self::eventosDe($usuario) === []) {
            if (self::esAdministradorGlobal($usuario)) {
                Respuesta::redirigir('/admin/eventos', 'Crea el primer evento para empezar.', 'warn');
            }
            Respuesta::error(403, 'Todavía no trabajas en ningún evento',
                'Tu cuenta existe, pero ningún evento te ha agregado a su equipo. Pídeselo a quien '
                . 'administra el evento.',
                [['texto' => 'Salir', 'url' => Url::a('/admin/entrar'), 'principal' => true]]);
        }
        $destino = $peticion->metodo() === 'GET' ? $peticion->ruta() : '/admin';
        $consulta = $_SERVER['QUERY_STRING'] ?? '';
        if ($destino !== '/admin' && $consulta !== '') {
            $destino .= '?' . $consulta;
        }
        if ($peticion->esAjax()) {
            Respuesta::json(['error' => 'evento', 'ir' => Url::a('/admin/evento')], 409);
        }
        Respuesta::redirigirAbsoluto(Url::a('/admin/evento', $destino === '/admin' ? [] : ['destino' => $destino]));
    }

    /* =====================================================================
       Instalador
       ===================================================================== */

    private static function instalador(): void
    {
        if (!Config::instalado()) {
            return;
        }
        // Ya instalado: el asistente se cierra solo. Reabrirlo exige tocar el
        // servidor —crear config/permitir-reinstalar—, que es justo la barrera
        // que se quiere: quien tenga acceso por FTP ya podía hacer daño igual,
        // pero nadie llega ahí desde el navegador.
        if (is_file(RAIZ . '/config/permitir-reinstalar')) {
            return;
        }
        // Marcada como instalada pero sin cuenta con la que entrar: cerrar el
        // asistente aquí deja la plataforma sin ninguna puerta. Se reabre en
        // modo reparación —sin la opción que borra datos— y se cierra sola en
        // cuanto exista una cuenta administradora. La barrera de verdad sigue
        // en el paso 2, que exige las credenciales de la base de datos.
        //
        // No se anota nada aquí: esta ruta la puede pedir cualquiera y la
        // bitácora se llenaría de ruido. Queda anotado en el paso 2, cuando
        // alguien demuestra tener esas credenciales.
        if (Instalacion::incompleta()) {
            return;
        }
        Respuesta::error(403, 'La plataforma ya está instalada',
            'El asistente de instalación se cierra al terminar. Para volver a ejecutarlo, '
            . 'crea el archivo config/permitir-reinstalar en el servidor.');
    }

    /* =====================================================================
       Redirección al acceso, conservando el destino
       ===================================================================== */

    private static function pedirIdentificacion(Peticion $peticion, string $rutaAcceso): never
    {
        // Solo tiene sentido volver a una pantalla que se pueda visitar; a un
        // POST no se regresa.
        $destino = $peticion->metodo() === 'GET' ? $peticion->ruta() : '/';
        $consulta = $_SERVER['QUERY_STRING'] ?? '';
        if ($destino !== '/' && $consulta !== '') {
            $destino .= '?' . $consulta;
        }

        if ($peticion->esAjax()) {
            Respuesta::json(['error' => 'sesion', 'ir' => Url::a($rutaAcceso)], 401);
        }

        Respuesta::redirigirAbsoluto(Url::a($rutaAcceso, $destino === '/' ? [] : ['destino' => $destino]));
    }
}
