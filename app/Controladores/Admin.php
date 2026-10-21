<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Modelos\Asistencia;
use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Modelos\Recuperacion;
use App\Modelos\Usuario;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Dispositivo;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Limite;
use App\Nucleo\Orden;
use App\Nucleo\Paginacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Backoffice del equipo organizador.
 *
 * Cada método asume que el guardia de su ruta ya comprobó el rol; el permiso
 * está declarado en app/rutas.php y no se repite aquí. Lo que sí se hace en
 * cada acción que escribe es dejar rastro en la bitácora.
 */
final class Admin
{
    /* =====================================================================
       Panel
       ===================================================================== */

    public function panel(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        // El aviso de «la base está atrasada» lo pinta la plantilla, en todas
        // las pantallas del equipo y del staff. Vivía solo aquí, y eso dejaba
        // suelto el camino que de verdad duele: subir los archivos nuevos,
        // entrar directo a una pantalla que estrena columna, y verla fallar sin
        // que nada explique por qué.
        Respuesta::vista('admin/panel', [
            'titulo'      => 'Panel',
            'pantalla'    => 'panel',
            'resumen'     => Evento::resumen((int) $evento['id']),
            'porJornada'  => Asistencia::totalPorJornada((int) $evento['id']),
            'municipios'  => Evento::porMunicipio((int) $evento['id']),
            'bitacora'    => Bitacora::recientes(12),
            'pendientes'  => $this->pendientes((int) $evento['id']),
        ]);
    }

    /**
     * Aplica al esquema lo que falte para esta versión del código.
     *
     * Solo agrega: crea las tablas que no estén y las columnas que falten. No
     * borra ni cambia nada de lo que ya hay, que es lo que permite ejecutarlo
     * sobre una base con el evento en curso.
     */
    public function actualizarEsquema(Peticion $peticion): void
    {
        [$pendiente, $motivo] = \App\Esquema::revisionPendiente();
        if (!$pendiente) {
            Respuesta::redirigir('/admin', 'La base de datos ya estaba al día.', 'ok');
        }

        try {
            $cambiadas = \App\Nucleo\Actualizacion::aplicar('panel');
        } catch (\Throwable $e) {
            \App\Nucleo\Registro::excepcion($e);
            Respuesta::redirigir('/admin',
                'No se pudo actualizar la base: ' . $e->getMessage(), 'warn');
        }

        Respuesta::redirigir('/admin', $cambiadas === []
            ? 'La base quedó marcada en la versión ' . \App\Esquema::VERSION . '; no hizo falta cambiar nada.'
            : 'Base de datos actualizada a la versión ' . \App\Esquema::VERSION . ': '
              . count($cambiadas) . ' tabla(s) creadas o ampliadas.');
    }

    /** Cosas que alguien debería mirar hoy. */
    private function pendientes(int $eventoId): array
    {
        $lista = [];

        $porAprobar = (int) Bd::valor(
            "SELECT COUNT(*) FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
              WHERE p.evento_id = ? AND pr.estado = 'pendiente'",
            [$eventoId]
        );
        if ($porAprobar > 0) {
            $lista[] = ['texto' => $porAprobar . ' propuestas de exposición por revisar',
                        'ruta' => '/admin/expositores', 'tipo' => 'warn'];
        }

        $sinPropuesta = Persona::contarExpositoresSinPropuesta(
            $eventoId, \App\Modelos\Formulario::llegadosPorEnlace($eventoId)
        );
        if ($sinPropuesta > 0) {
            $lista[] = ['texto' => $sinPropuesta . ($sinPropuesta === 1
                            ? ' expositor registrado todavía sin propuesta'
                            : ' expositores registrados todavía sin propuesta'),
                        'ruta' => '/admin/expositores?estado=sin-propuesta', 'tipo' => ''];
        }

        $sinSegundoFactor = (int) Bd::valor(
            "SELECT COUNT(*) FROM {usuario}
              WHERE rol = 'administrador' AND estado = 'activo' AND totp_confirmado = 0"
        );
        if ($sinSegundoFactor > 0) {
            $lista[] = ['texto' => $sinSegundoFactor . ' administradores sin segundo factor activo',
                        'ruta' => '/admin/organizadores', 'tipo' => 'danger'];
        }

        $tema = Tema::del($eventoId);
        if ($tema['logo'] === '') {
            $lista[] = ['texto' => 'El evento todavía no tiene logo cargado',
                        'ruta' => '/admin/identidad', 'tipo' => ''];
        }

        if (!App::peticion()->esSegura()) {
            $lista[] = ['texto' => 'La plataforma no está sirviéndose por HTTPS',
                        'ruta' => '/admin', 'tipo' => 'danger'];
        }

        return $lista;
    }

    /* =====================================================================
       Escáner del operador
       ===================================================================== */

    /* =====================================================================
       Registros
       ===================================================================== */

    public function registros(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
            'dia'   => $peticion->entero('dia'),
        ];
        $jornadas = Evento::jornadas($eventoId);

        // Las cifras de arriba y las páginas salen de todo el filtro; de la
        // base solo se trae la página que se ve.
        $resumen = Persona::resumen($eventoId, $filtros, count($jornadas));
        $paginacion = Paginacion::desde($peticion, $resumen['en_filtro']);
        $orden = Persona::ordenElegido($peticion, $eventoId);
        $personas = Persona::buscar($eventoId, $filtros + [
            'limite' => $paginacion->porPagina,
            'desde'  => $paginacion->desplazamiento(),
        ], $orden);

        Respuesta::vista('admin/registros', [
            'titulo'   => 'Registros',
            'pantalla' => 'admin-registros',
            'personas' => $personas,
            'jornadas' => $jornadas,
            'filtros'  => $filtros,
            'resumen'  => $resumen,
            'paginacion' => $paginacion,
            'orden'    => $orden,
            // Lo que conservan los enlaces de las páginas, de las columnas y
            // de la exportación: con los nombres que usa el formulario.
            'consulta' => array_filter([
                'q'   => $filtros['texto'],
                'rol' => $filtros['rol'],
                'dia' => $filtros['dia'] ?: '',
            ]),
            'total'    => (int) Bd::valor('SELECT COUNT(*) FROM {persona} WHERE evento_id = ?', [$eventoId]),
            'puedeVerSensibles' => Guardia::puede('administrador'),
            // El rol consulta está pensado para ver indicadores, no para leer
            // la cédula de cada asistente. El operador sí la necesita: es lo
            // que compara contra el documento físico al acreditar.
            'puedeVerDocumento' => Guardia::puede('operador'),
        ]);
    }

    /**
     * Ficha completa de una persona, para el botón de perfil de la tabla.
     *
     * Es una pantalla de verdad y no solo un diálogo: se puede abrir en su
     * propia dirección, enlazar y recargar. Cuando la pide el guion de la tabla
     * —con la cabecera de petición asíncrona— se devuelve únicamente el
     * fragmento, que es lo que se mete en el diálogo.
     *
     * Leer la ficha de alguien es leer sus datos personales, así que queda en
     * la bitácora igual que la pantalla de acreditación.
     */
    public function ficha(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $persona = Persona::porId((int) $parametros['persona']);

        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::error(404, 'Registro no encontrado',
                'Esa persona no está registrada en el evento activo.');
        }

        $id = (int) $persona['id'];
        Bitacora::registrar('ficha_consultada', 'persona', $id);

        $esAdministrador = Guardia::puede('administrador');

        $datos = [
            'titulo'    => $persona['nombre'] !== '' ? $persona['nombre'] : (string) $persona['correo'],
            'pantalla'  => 'admin-registros',
            'persona'   => $persona,
            'documento' => Guardia::puede('operador') ? Persona::documento($persona) : '',
            'credencial' => Credencial::dePersona($id),
            'historial' => Asistencia::historial($id, (int) $persona['evento_id']),
            'contactos' => (int) Bd::valor(
                'SELECT COUNT(*) FROM {contacto} WHERE persona_id = ? AND revocado_en IS NULL',
                [$id]
            ),
            // La caracterización es dato sensible (Ley 1581, art. 5).
            'caracterizacion' => $esAdministrador ? Persona::caracterizacion($id) : [],
            'esAdministrador' => $esAdministrador,
            'tieneClave'      => Persona::tieneClave($persona),
            'qrActivo'        => Autenticacion::activo('qr'),
            'claveNueva'      => (string) ($parametros['claveNueva'] ?? ''),
            'claveActiva'     => Autenticacion::activo('clave'),
            'enlaceClave'     => $parametros['enlaceClave'] ?? null,
            'urlAcceso'       => '',
        ];

        // El QR de acceso solo se pinta si un administrador lo pidió: es una
        // llave, y no tiene por qué aparecer en pantalla cada vez que alguien
        // del equipo abre una ficha.
        $pideQr = !empty($parametros['conQr']) || $peticion->query('qr') === '1';
        if ($esAdministrador && $datos['qrActivo'] && $pideQr) {
            $datos['urlAcceso'] = Credencial::urlAcceso($id);
            $datos['qrAcceso'] = Qr::svg($datos['urlAcceso'], [
                'nivel' => 'Q', 'silencio' => 2, 'clase' => 'qr', 'titulo' => 'QR de acceso',
            ]);
        }

        if ($peticion->esAjax()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo Respuesta::parcial('admin/ficha-persona', $datos);
            exit;
        }

        Respuesta::vista('admin/ficha-persona', $datos);
    }

    /**
     * Restablece la contraseña de un asistente.
     *
     * No hay forma de «ver» la contraseña de nadie, y no es una limitación de
     * esta pantalla: se guarda como un hash Argon2id, que es una función de un
     * solo sentido. Si se pudiera leer, cualquiera que copiara la tabla
     * tendría las contraseñas de todos los asistentes en claro.
     *
     * Lo que sí se puede hacer es lo que hace cualquier sistema serio: generar
     * una nueva, mostrarla una sola vez y que la persona la cambie. La anterior
     * deja de servir en ese momento.
     */
    public function restablecerClave(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $id = $peticion->entero('persona');
        $persona = Persona::porId($id);

        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir('/admin/registros', 'Esa persona no existe en este evento.', 'warn');
        }

        // Legible por teléfono: sin caracteres que se confundan al dictarla en
        // la puerta de un recinto con ruido.
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $nueva = '';
        for ($i = 0; $i < 10; $i++) {
            $nueva .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        Persona::ponerClave($id, $nueva);

        // Se cierran sus sesiones: si alguien pide restablecer la contraseña es
        // porque perdió el control de la cuenta, y dejar abiertas las sesiones
        // anteriores haría inútil el cambio.
        Sesion::cerrarTodasDe('asistente', $id);
        Dispositivo::olvidarTodos($id);

        Bitacora::registrar('clave_asistente_restablecida', 'persona', $id);

        // La contraseña nueva NO viaja en la redirección ni en el aviso: se
        // pinta directamente en la respuesta de esta petición, una sola vez.
        $this->ficha($peticion, ['persona' => $id, 'claveNueva' => $nueva]);
    }

    /**
     * Le envía a un asistente un enlace para que ponga él mismo una contraseña
     * nueva.
     *
     * Es la otra salida cuando alguien no recuerda la suya, y la buena cuando
     * no está delante: no hay que dictarle nada por teléfono, la contraseña la
     * elige la persona y nadie más la conoce. La actual sigue sirviendo hasta
     * que la cambie, así que enviarlo no le quita el acceso a nadie.
     */
    public function enviarEnlaceClave(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $id = $peticion->entero('persona');
        $persona = Persona::porId($id);

        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir('/admin/registros', 'Esa persona no existe en este evento.', 'warn');
        }

        $aviso = Autenticacion::activo('clave')
            ? $this->enviarEnlaceDeClave('asistente', $persona)
            : ['warn', 'El acceso con contraseña está apagado en «Acceso y correo»: esta persona entra con '
                . 'un código al correo o con su QR, sin contraseña.'];

        $this->ficha($peticion, ['persona' => $id, 'enlaceClave' => $aviso]);
    }

    /**
     * Crea el enlace, lo envía y lo deja en la bitácora con quién lo envió.
     *
     * @return array{0: string, 1: string} el tipo de aviso y su texto
     */
    private function enviarEnlaceDeClave(string $tipo, array $cuenta): array
    {
        if (!Correo::disponible()) {
            return ['warn', 'No hay correo saliente configurado, así que el enlace no llegaría. Configúralo en '
                . '«Acceso y correo»' . ($tipo === 'asistente'
                    ? ', o genérale una contraseña nueva y entrégasela en persona.' : '.')];
        }

        try {
            $enviado = Recuperacion::enviar($tipo, $cuenta, (int) Guardia::usuarioActual()['id']);
        } catch (\Throwable $e) {
            \App\Nucleo\Registro::excepcion($e);
            return ['danger', 'No se pudo preparar el enlace. Si la plataforma se acaba de actualizar, '
                . 'pon al día la base de datos desde el panel y vuelve a intentarlo.'];
        }

        Bitacora::registrar('enlace_clave_enviado', $tipo === 'admin' ? 'usuario' : 'persona',
            (int) $cuenta['id'], ['enviado' => $enviado]);

        if (!$enviado) {
            return ['danger', 'El correo no salió: ' . (Correo::ultimoError() ?: 'el servidor de correo no lo aceptó')
                . '. Revisa «Acceso y correo».'];
        }
        return ['ok', 'Le enviamos a ' . $cuenta['correo'] . ' un enlace para poner una contraseña nueva. Vence '
            . 'en 24 horas y sirve una sola vez; la contraseña actual sigue sirviendo mientras no la cambie.'];
    }

    /** Cambia el token del QR de acceso de una persona. */
    public function regenerarQrAcceso(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $id = $peticion->entero('persona');
        $persona = Persona::porId($id);

        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir('/admin/registros', 'Esa persona no existe en este evento.', 'warn');
        }

        Persona::regenerarTokenDeAcceso($id);
        Dispositivo::olvidarTodos($id);
        Bitacora::registrar('qr_acceso_regenerado', 'persona', $id);

        $this->ficha($peticion, ['persona' => $id, 'conQr' => true]);
    }

    /**
     * Cambia el perfil de asistencia de una persona.
     *
     * Es la única puerta por la que se pone el perfil **Staff**, y por eso la
     * ruta exige administrador: ese perfil deja ver los carnets de todo el
     * evento —con la cédula de cada quien— y sellar ingresos. El formulario
     * público no lo admite ni enviándolo a mano.
     *
     * El cambio queda en la bitácora con el perfil anterior y el nuevo: es un
     * cambio de permisos, y hay que poder responder quién lo hizo y cuándo.
     */
    public function cambiarPerfil(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $id = $peticion->entero('persona');
        $persona = Persona::porId($id);

        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir('/admin/registros', 'Esa persona no existe en este evento.', 'warn');
        }

        // Los del evento —encendidos o no: apagado es que no se ofrece en el
        // formulario, pero un administrador lo puede seguir poniendo— y los
        // que solo pone un administrador.
        $nuevo = $peticion->campo('rol');
        $perfiles = \App\Modelos\Formulario::delEvento((int) $evento['id'])->todosLosPerfiles();
        if (!isset($perfiles[$nuevo])) {
            Respuesta::redirigir('/admin/registros/' . $id, 'Ese perfil no existe.', 'warn');
        }

        // Hasta la 1.9.0 la columna era un ENUM con cinco valores —seis desde
        // la 1.6.0, con «staff»—, y una base atrasada no admite los demás: el
        // UPDATE fallaría con «Data truncated» y la pantalla con un 500. Se
        // dice qué falta en vez de dejar que reviente.
        [$atrasada] = \App\Esquema::revisionPendiente();
        if ($atrasada && !in_array($nuevo, Persona::PERFILES_DE_ANTES, true)) {
            Respuesta::redirigir('/admin/registros/' . $id,
                'Falta actualizar la base de datos: el perfil ' . $perfiles[$nuevo] . ' todavía no cabe en ella. '
                . 'Pulsa «Actualizar la base de datos» en el panel y vuelve a intentarlo.', 'warn');
        }

        $anterior = (string) $persona['rol'];
        if ($nuevo === $anterior) {
            Respuesta::redirigir('/admin/registros/' . $id, 'El perfil ya era ese.');
        }

        // Un registro sin completar no puede ser staff: entrar a acreditar
        // exige sesión de asistente, y el guardia la manda a terminar el
        // formulario. Quedaría con el perfil puesto y sin poder usarlo.
        if ($nuevo === 'staff' && !Persona::registroCompleto($persona)) {
            Respuesta::redirigir('/admin/registros/' . $id,
                'Esa persona todavía no ha completado su registro, así que no podría entrar '
                . 'a acreditar. Pídele que llene su nombre y su identificación.', 'warn');
        }

        Bd::actualizar('persona', ['rol' => $nuevo], 'id = :id', ['id' => $id]);
        Bitacora::registrar('perfil_cambiado', 'persona', $id, [
            'de' => $anterior,
            'a'  => $nuevo,
        ]);

        Respuesta::redirigir('/admin/registros/' . $id,
            $nuevo === 'staff'
                ? etiquetaRol($nuevo) . ': ' . $persona['nombre'] . ' ya puede entrar a ver los '
                  . 'carnets y a acreditar con su propio acceso de asistente.'
                : 'Perfil cambiado a ' . etiquetaRol($nuevo) . '.');
    }

    /**
     * Exportación a CSV.
     *
     * La caracterización solo va si quien exporta es administrador y la pide
     * explícitamente. Ambas cosas quedan en la bitácora.
     */
    public function exportar(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $conSensibles = $peticion->query('caracterizacion') === '1';

        if ($conSensibles && !Guardia::puede('administrador')) {
            Respuesta::error(403, 'No tienes permiso',
                'La exportación con caracterización está reservada al rol administrador.');
        }

        // La vista construye el enlace de exportación con las claves de
        // $filtros, así que llega «texto» y no «q». Leyendo solo «q», el CSV
        // salía con todos los registros aunque en pantalla se viera un filtro
        // aplicado: quien exportaba se llevaba mucho más de lo que creía.
        //
        // Sale todo lo que deja el filtro, en el orden que se ve en pantalla.
        // Hasta la 3.12 se cortaba en quinientas filas sin avisar.
        $filtros = [
            'texto'  => $peticion->query('texto') ?: $peticion->query('q'),
            'rol'    => $peticion->query('rol'),
            'dia'    => $peticion->entero('dia'),
        ];
        $personas = Persona::buscar((int) $evento['id'], $filtros,
            Persona::ordenElegido($peticion, (int) $evento['id']));
        $jornadas = Evento::jornadas((int) $evento['id']);
        $puedeVerDocumento = Guardia::puede('operador');

        $cabecera = ['Nombre', 'Documento', 'Correo', 'Teléfono', 'Entidad', 'Departamento', 'Municipio', 'Perfil'];
        foreach ($jornadas as $j) {
            $cabecera[] = 'Día ' . $j['numero'];
        }
        if ($conSensibles) {
            $cabecera = array_merge($cabecera, ['Género', 'Rango de edad', 'Etnia', 'Discapacidad']);
        }

        $filas = [$cabecera];
        foreach ($personas as $p) {
            $dias = Persona::diasDe($p['dias'] ?? null);
            $fila = [
                $p['nombre'],
                $puedeVerDocumento ? Persona::documento($p) : '',
                $p['correo'],
                $p['telefono'],
                $p['entidad'],
                $p['departamento'],
                $p['municipio'],
                etiquetaRol((string) $p['rol']),
            ];
            foreach ($jornadas as $j) {
                $fila[] = in_array((int) $j['numero'], $dias, true) ? 'Sí' : 'No';
            }
            if ($conSensibles) {
                $c = Persona::caracterizacion((int) $p['id']);
                $fila = array_merge($fila, [
                    $c['genero'] ?? '', $c['rango_edad'] ?? '', $c['etnia'] ?? '', $c['discapacidad'] ?? '',
                ]);
            }
            $filas[] = $fila;
        }

        Bitacora::registrar('exportacion', 'persona', null, [
            'total' => count($personas),
            'sensible' => $conSensibles,
        ]);

        Respuesta::descarga(
            $conSensibles ? 'registros-caracterizacion.csv' : 'registros.csv',
            "\xEF\xBB\xBF" . $this->aCsv($filas),   // BOM para que Excel lea las tildes
            'text/csv'
        );
    }

    /**
     * CSV con el punto y coma como separador, que es lo que espera Excel en
     * configuración regional española.
     *
     * Los valores que empiezan por = + - @ se neutralizan con un apóstrofo: sin
     * eso, alguien podría escribir una fórmula en el campo «entidad» del
     * formulario público y esa fórmula se ejecutaría al abrir el reporte en el
     * equipo de un funcionario.
     */
    private function aCsv(array $filas): string
    {
        $lineas = [];
        foreach ($filas as $fila) {
            $celdas = [];
            foreach ($fila as $celda) {
                $valor = (string) $celda;
                if ($valor !== '' && str_contains("=+-@\t\r", $valor[0])) {
                    $valor = "'" . $valor;
                }
                $celdas[] = '"' . str_replace('"', '""', $valor) . '"';
            }
            $lineas[] = implode(';', $celdas);
        }
        return implode("\r\n", $lineas);
    }

    /* =====================================================================
       Códigos QR por jornada
       ===================================================================== */

    public function codigosDia(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $jornadas = Evento::jornadas((int) $evento['id']);

        $numeros = array_column($jornadas, 'numero');
        foreach ($jornadas as $k => &$j) {
            $j['url'] = Url::absoluta('/d/' . $j['token']);
            $j['qr'] = Qr::svg($j['url'], [
                'nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                'titulo' => 'Código de acceso del día ' . $j['numero'],
            ]);
            // Lo que pasaría con los demás si se elimina este: la confirmación
            // lo dice antes de hacerlo.
            $restantes = $numeros;
            unset($restantes[$k]);
            $j['al_eliminar'] = Evento::renumeracionEnPalabras(Evento::mapaSeguido($restantes));
        }
        unset($j);

        // Por omisión, el día siguiente al último: es lo que se agrega el 90 %
        // de las veces, y quien necesite otra fecha solo tiene que cambiarla.
        $ultima = $jornadas ? (string) end($jornadas)['fecha'] : date('Y-m-d');
        $siguiente = date('Y-m-d', strtotime($ultima . ' +1 day'));

        Respuesta::vista('admin/qr-dias', [
            'titulo'   => 'Códigos QR por día',
            'pantalla' => 'admin-qr',
            'jornadas' => $jornadas,
            'puedeRotar' => Guardia::puede('administrador'),
            'puedeEditarDias' => Guardia::puede('administrador'),
            'siguienteFecha'  => $siguiente,
            'seguidas'        => Evento::jornadasSeguidas($jornadas),
            'paraSeguirlas'   => Evento::renumeracionEnPalabras(Evento::mapaSeguido($numeros)),
        ]);
    }

    public function imprimirCodigo(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $jornada = Evento::jornada((int) $evento['id'], (int) $parametros['numero']);
        if (!$jornada) {
            Respuesta::error(404, 'Jornada no encontrada', 'Ese día no existe en este evento.');
        }

        $url = Url::absoluta('/d/' . $jornada['token']);

        Respuesta::vista('admin/qr-imprimir', [
            'titulo'       => 'Código del día ' . $jornada['numero'],
            'jornada'      => $jornada,
            'url'          => $url,
            'qr'           => Qr::svg($url, ['nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                                             'titulo' => 'Código de acceso']),
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Agrega una jornada al evento.
     *
     * El número de días se fijaba al crear el evento y no se podía tocar: si el
     * evento se alargaba un día, la única salida era volver a instalarlo.
     */
    public function agregarJornada(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        try {
            $numero = Evento::agregarJornada(
                (int) $evento['id'],
                $peticion->campo('fecha'),
                $peticion->campo('abre_a'),
                $peticion->campo('cierra_a')
            );
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/qr-dias', $e->getMessage(), 'warn');
        }

        App::olvidarEvento();
        Respuesta::redirigir('/admin/qr-dias',
            'Día ' . $numero . ' agregado, con su propio código QR. Imprímelo para la entrada.');
    }

    /**
     * Elimina una jornada.
     *
     * Con «forzar» se lleva también sus ingresos y sus charlas. Hace falta:
     * los días de prueba se llenan de escaneos justamente probando, y sin esta
     * salida la única forma de limpiar era entrar a la base a mano.
     */
    public function eliminarJornada(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $numero = (int) $this->jornadaDelFormulario($peticion, $evento)['numero'];
        $forzar = $peticion->marcado('forzar');

        try {
            $mapa = Evento::eliminarJornada((int) $evento['id'], $numero, $forzar);
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/qr-dias', $e->getMessage(), 'warn');
        }

        App::olvidarEvento();
        $mensaje = $forzar
            ? 'Día ' . $numero . ' eliminado, con los ingresos y las charlas que tenía.'
            : 'Día ' . $numero . ' eliminado.';
        if ($mapa !== []) {
            $mensaje .= ' Los siguientes se renumeraron: ' . Evento::renumeracionEnPalabras($mapa) . '.';
        }
        Respuesta::redirigir('/admin/qr-dias', $mensaje, $forzar ? 'warn' : 'ok');
    }

    /** Para un evento que quedó con números salteados de una versión anterior. */
    public function renumerarJornadas(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $mapa = Evento::dejarJornadasSeguidas((int) $evento['id']);

        App::olvidarEvento();
        Respuesta::redirigir('/admin/qr-dias', $mapa === []
            ? 'Los días ya estaban seguidos.'
            : 'Listo: ' . Evento::renumeracionEnPalabras($mapa) . '. Los códigos QR no cambiaron.');
    }

    /**
     * La jornada sobre la que actúa un formulario de esta pantalla.
     *
     * Los formularios mandan el número, que es lo que se ve, y el id de la
     * jornada. Desde que se eliminan días renumerando los siguientes, un número
     * puede cambiar de dueño mientras la pantalla sigue abierta en otra
     * pestaña: el «día 2» de esa pestaña ya es otra fecha. Si no cuadran, no se
     * toca nada y se pide mirar otra vez.
     *
     * Sin id —un envío de antes de la 3.7.1— vale el número solo.
     */
    private function jornadaDelFormulario(Peticion $peticion, array $evento): array
    {
        $jornada = Evento::jornada((int) $evento['id'], $peticion->entero('numero'));
        $id = $peticion->entero('jornada');
        if (!$jornada) {
            Respuesta::redirigir('/admin/qr-dias', 'Esa jornada no existe.', 'warn');
        }
        if ($id > 0 && (int) $jornada['id'] !== $id) {
            Respuesta::redirigir('/admin/qr-dias',
                'Los días cambiaron de número mientras tenías esta pantalla abierta, así que no se hizo '
                . 'nada. Revisa la lista y vuelve a intentarlo.', 'warn');
        }
        return $jornada;
    }

    public function ajustarJornada(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $numero = (int) $this->jornadaDelFormulario($peticion, $evento)['numero'];

        try {
            Evento::ajustarJornada(
                (int) $evento['id'],
                $numero,
                $peticion->campo('fecha'),
                $peticion->campo('abre_a'),
                $peticion->campo('cierra_a')
            );
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/qr-dias', $e->getMessage(), 'warn');
        }

        App::olvidarEvento();
        Respuesta::redirigir('/admin/qr-dias', 'Día ' . $numero . ' actualizado.');
    }

    public function rotarCodigo(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $numero = (int) $this->jornadaDelFormulario($peticion, $evento)['numero'];

        Evento::rotarToken((int) $evento['id'], $numero);
        Respuesta::redirigir('/admin/qr-dias',
            'Código del día ' . $numero . ' regenerado. El pliego impreso anterior ya no sirve: reemplázalo.', 'warn');
    }

    /* =====================================================================
       Propuestas de exposición
       ===================================================================== */

    public function expositores(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $estado = $peticion->query('estado');
        $soloSinPropuesta = $estado === 'sin-propuesta';

        $donde = ['p.evento_id = :evento'];
        $parametros = ['evento' => (int) $evento['id']];
        if (in_array($estado, ['pendiente', 'observada', 'aprobada', 'rechazada'], true)) {
            $donde[] = 'pr.estado = :estado';
            $parametros['estado'] = $estado;
        }

        // Se ordena desde el título de cada columna y va por páginas: cada
        // propuesta trae su diálogo de revisión, y con cien en una sola
        // página la pantalla se volvía pesada de abrir.
        $orden = Orden::desde($peticion, [
            'propuesta' => ['pr.titulo'],
            'expositor' => ['p.nombre', "TRIM(p.nombre) = ''"],
            'categoria' => ['pr.categoria', "pr.categoria = ''"],
            // Las que ya tienen día, por día y hora; las demás al final, por el
            // día que pidieron.
            'jornada'   => [['dc.numero', 'c.hora_inicio', 'pr.dia_preferido'], 'dc.numero IS NULL'],
            'estado'    => ["FIELD(pr.estado, 'pendiente', 'observada', 'aprobada', 'rechazada')"],
        ], 'estado', 'asc', 'pr.creado_en DESC, pr.id DESC');
        $paginacion = Paginacion::desde($peticion, $soloSinPropuesta ? 0 : (int) Bd::valor(
            'SELECT COUNT(*) FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
              WHERE ' . implode(' AND ', $donde),
            $parametros
        ), 25);

        // Se traen los datos completos de la persona, no solo su nombre: decidir
        // sobre una propuesta es decidir sobre quién la presenta, y tener que
        // abrir la ficha en otra pantalla para ver de qué entidad viene o si
        // dejó teléfono convertía la revisión en un ir y venir.
        $propuestas = $soloSinPropuesta ? [] : Bd::filas(
            'SELECT pr.*,
                    p.id AS persona_id, p.nombre AS expositor, p.correo, p.telefono,
                    p.comparte_telefono, p.tipo_documento, p.documento_cifrado,
                    p.entidad, p.municipio, p.departamento, p.rol, p.foto,
                    p.creado_en AS registrado_en,
                    c.id AS charla_id, c.hora_inicio, c.salon,
                    c.evento_dia_id AS jornada_asignada, dc.numero AS dia_asignado
               FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id
          LEFT JOIN {evento_dia} dc ON dc.id = c.evento_dia_id
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY ' . $orden->sql() . '
              LIMIT ' . $paginacion->porPagina . ' OFFSET ' . $paginacion->desplazamiento(),
            $parametros
        );

        $conteos = [];
        foreach (Bd::filas(
            'SELECT pr.estado, COUNT(*) AS n
               FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
              WHERE p.evento_id = ? GROUP BY pr.estado',
            [(int) $evento['id']]
        ) as $fila) {
            $conteos[$fila['estado']] = (int) $fila['n'];
        }

        // Quienes se registraron como expositores —por el enlace privado o con
        // ese perfil— y todavía no tienen propuesta. Arriba no salen: la lista
        // es de propuestas.
        $porEnlace = \App\Modelos\Formulario::llegadosPorEnlace((int) $evento['id']);
        $conteos['sin-propuesta'] = Persona::contarExpositoresSinPropuesta((int) $evento['id'], $porEnlace);

        // Vistos solos (?estado=sin-propuesta) se ordenan y van por páginas con
        // los mismos enlaces que las propuestas. Debajo de «Todas» salen los
        // primeros, con el camino a la lista entera.
        $ordenSin = Orden::desde($peticion, [
            'expositor' => ['p.nombre', "TRIM(p.nombre) = ''"],
            'entidad'   => ['p.entidad', "p.entidad = ''"],
            'registro'  => ['p.creado_en'],
        ], 'registro', 'desc', 'p.id DESC');
        $paginacionSin = Paginacion::desde($peticion, $soloSinPropuesta ? $conteos['sin-propuesta'] : 0, 25);
        $sinPropuesta = Persona::expositoresSinPropuesta((int) $evento['id'], $porEnlace,
            // Debajo de «Todas», siempre los más recientes: el orden elegido
            // ahí es el de las propuestas.
            $soloSinPropuesta ? $ordenSin : null,
            $soloSinPropuesta ? $paginacionSin->porPagina : self::SIN_PROPUESTA_EN_TODAS,
            $soloSinPropuesta ? $paginacionSin->desplazamiento() : 0);

        // Cuándo cambió cada propuesta su expositor por última vez, desde «Mi
        // exposición»: si fue después de revisarla, hay algo nuevo que mirar.
        $actualizadas = [];
        foreach (Bd::filas(
            "SELECT entidad_id, MAX(creado_en) AS en FROM {bitacora}
              WHERE accion = 'propuesta_actualizada' AND entidad = 'propuesta'
           GROUP BY entidad_id"
        ) as $fila) {
            $actualizadas[(int) $fila['entidad_id']] = (string) $fila['en'];
        }

        Respuesta::vista('admin/expositores', [
            'titulo'     => 'Expositores',
            'pantalla'   => 'admin-expositores',
            'propuestas' => $propuestas,
            'sinPropuesta' => $sinPropuesta,
            'porEnlace'  => $porEnlace,
            'actualizadas' => $actualizadas,
            'conteos'    => $conteos,
            'estado'     => $estado,
            'orden'      => $soloSinPropuesta ? $ordenSin : $orden,
            'paginacion' => $soloSinPropuesta ? $paginacionSin : $paginacion,
            // Lo que conservan los enlaces de las columnas y de las páginas, y
            // a dónde se vuelve después de decidir una propuesta.
            'consulta'   => array_filter(['estado' => $estado]),
            'jornadas'   => Evento::jornadas((int) $evento['id']),
            // Las categorías y duraciones salen de los dos formularios de
            // registro: una propuesta pudo llegar por cualquiera.
            'eventoId'   => (int) $evento['id'],
            'correoListo' => Correo::disponible(),
            // La ruta ya exige administrador, pero la comprobación se deja
            // escrita para que mover el guardia no destape el documento sin
            // que nadie se dé cuenta.
            'puedeVerDocumento' => Guardia::puede('operador'),
        ]);
    }

    /** Cuántos registrados sin propuesta se muestran debajo de «Todas». */
    private const SIN_PROPUESTA_EN_TODAS = 10;

    /**
     * La lista de Expositores tal como estaba: filtro, orden y página.
     *
     * Llega en un campo oculto del formulario de decisión. Solo se aceptan
     * esas cinco claves con valores cortos y sin símbolos: nada más de lo que
     * venga ahí llega a la dirección.
     */
    private function volverAExpositores(Peticion $peticion): string
    {
        parse_str($peticion->campo('volver'), $antes);
        $limpio = [];
        foreach (['estado', 'orden', 'dir', 'pagina', 'por'] as $clave) {
            $valor = $antes[$clave] ?? '';
            if (is_string($valor) && preg_match('/^[a-z0-9\-]{1,30}$/', $valor)) {
                $limpio[$clave] = $valor;
            }
        }
        return '/admin/expositores' . ($limpio ? '?' . http_build_query($limpio) : '');
    }

    /**
     * Aprobar, observar o rechazar.
     * Al aprobar se crea la charla: es lo que la vuelve visible en la agenda.
     */
    public function decidirPropuesta(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        // Por cuál de los dos formularios se mandan hoy las propuestas.
        $formulario = \App\Modelos\Formulario::dePropuestas((int) $evento['id']);
        $id = $peticion->entero('propuesta');
        $decision = $peticion->campo('decision');
        $observacion = mb_substr($peticion->campo('observacion'), 0, 1000);

        // Se vuelve a la misma página, con el mismo filtro y el mismo orden: el
        // comité revisa una tras otra, y volver siempre al principio de la
        // lista obligaba a buscar otra vez por dónde iba.
        $volver = $this->volverAExpositores($peticion);

        if (!in_array($decision, ['aprobada', 'observada', 'rechazada'], true)) {
            Respuesta::redirigir($volver, 'Decisión no válida.', 'warn');
        }

        $propuesta = Bd::fila(
            'SELECT pr.*, p.nombre AS expositor, p.correo FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
              WHERE pr.id = ? AND p.evento_id = ?',
            [$id, (int) $evento['id']]
        );
        if (!$propuesta) {
            Respuesta::redirigir($volver, 'Esa propuesta no existe.', 'warn');
        }

        if ($decision === 'observada' && trim($observacion) === '') {
            Respuesta::redirigir($volver,
                'Escribe la observación antes de devolver la propuesta.', 'warn');
        }

        // Los ajustes que hace el comité al revisarla: el título, la
        // categoría, la duración. Cada uno se aplica solo si cambió y es
        // válido, y queda anotado para contárselo al expositor.
        [$ajustes, $cambios] = $this->ajustesDePropuesta($peticion, $propuesta, (int) $evento['id']);

        // El día asignado, antes de tocar nada. Llega por su id: al eliminar un
        // día los siguientes se renumeran, y el «día 2» de una pantalla abierta
        // antes ya sería otra fecha. El número queda para los envíos de antes
        // de la 3.7.1.
        $jornada = null;
        if ($decision === 'aprobada') {
            $jornadaId = $peticion->entero('jornada');
            if ($jornadaId > 0) {
                $jornada = Bd::fila(
                    'SELECT * FROM {evento_dia} WHERE id = ? AND evento_id = ?',
                    [$jornadaId, (int) $evento['id']]
                );
                if (!$jornada) {
                    Respuesta::redirigir($volver,
                        'El día que elegiste ya no existe: se eliminó mientras revisabas la propuesta. '
                        . 'No se cambió nada; elige otro día y vuelve a aprobarla.', 'warn');
                }
            } else {
                $jornada = Evento::jornada((int) $evento['id'], $peticion->entero('dia', (int) $propuesta['dia_preferido']))
                    ?? Evento::jornada((int) $evento['id'], (int) $propuesta['dia_preferido']);
            }
        }

        $usuario = Guardia::usuarioActual();
        $agenda = null;

        Bd::transaccion(static function () use ($propuesta, $decision, $observacion, $usuario, $peticion, $jornada, $ajustes, &$agenda): void {
            Bd::actualizar('propuesta', [
                'estado'       => $decision,
                'observacion'  => $observacion !== '' ? $observacion : null,
                'revisada_por' => (int) $usuario['id'],
                'revisada_en'  => date('Y-m-d H:i:s'),
            ] + $ajustes, 'id = :id', ['id' => $propuesta['id']]);

            if ($decision !== 'aprobada') {
                Bd::ejecutar('DELETE FROM {charla} WHERE propuesta_id = ?', [$propuesta['id']]);
                return;
            }

            if (!$jornada) {
                return;
            }

            $hora = $peticion->campo('hora', '09:00');
            if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
                $hora = '09:00';
            }
            $salon = mb_substr($peticion->campo('salon'), 0, 80);

            Bd::ejecutar(
                'INSERT INTO {charla} (propuesta_id, evento_dia_id, hora_inicio, salon, publicada)
                      VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE evento_dia_id = VALUES(evento_dia_id),
                                         hora_inicio = VALUES(hora_inicio),
                                         salon = VALUES(salon),
                                         publicada = 1',
                [$propuesta['id'], $jornada['id'], $hora . ':00', $salon]
            );
            $agenda = [
                'dia'   => (int) $jornada['numero'],
                'fecha' => (string) $jornada['fecha'],
                'hora'  => $hora,
                'salon' => $salon,
            ];
        });

        // Si se le asignó otro día del que pidió, también es un cambio que
        // tiene que saber: puede que ese día no pueda. Solo si el formulario
        // le preguntó: oculto, el día guardado es el de omisión, no uno que
        // haya elegido.
        if ($agenda !== null && $formulario->visible('dia_preferido') && (int) $propuesta['dia_preferido'] > 0
            && $agenda['dia'] !== (int) $propuesta['dia_preferido']) {
            $cambios[] = 'Día: pediste el día ' . (int) $propuesta['dia_preferido'] . '; quedó el día '
                . $agenda['dia'] . ' (' . fecha($agenda['fecha']) . ').';
        }

        // El aviso al expositor, en los tres casos. Se puede no mandar —una
        // corrección de un error de tecleo no merece un correo—, pero va
        // marcado de entrada: quien envió una propuesta espera saber qué pasó.
        $avisar = $peticion->marcado('avisar');
        $avisado = false;
        if ($avisar) {
            $avisado = Correo::decisionPropuesta(
                (string) $propuesta['correo'],
                (string) $propuesta['expositor'],
                (string) $evento['nombre'],
                $decision,
                (string) ($ajustes['titulo'] ?? $propuesta['titulo']),
                $observacion,
                $cambios,
                $agenda,
                // Aprobada, a la agenda. Devuelta o no seleccionada, a «Mi
                // exposición»: ahí ve lo que dijo el comité y, si la corrige, la
                // vuelve a enviar con sus documentos, sin escribirla de nuevo.
                \App\Nucleo\Url::absoluta($decision === 'aprobada' ? '/agenda' : '/exposicion')
            );
            if (!$avisado) {
                \App\Nucleo\Registro::error('No se pudo avisar al expositor de la decisión', [
                    'propuesta_id' => (int) $propuesta['id'],
                    'detalle'      => Correo::ultimoError(),
                ]);
            }
        }

        Bitacora::registrar('propuesta_decidida', 'propuesta', (int) $propuesta['id'], [
            'decision' => $decision,
            'cambios'  => count($cambios),
            'avisado'  => $avisado,
        ]);

        $mensaje = match ($decision) {
            'aprobada'  => 'Propuesta aprobada y publicada en la agenda.',
            'observada' => 'Propuesta devuelta con observaciones.',
            default     => 'Propuesta rechazada.',
        };
        if ($avisar) {
            $mensaje .= $avisado
                ? ' Se le avisó por correo a ' . $propuesta['correo'] . '.'
                : ' Pero no se pudo enviar el correo al expositor: revisa Configuración → Acceso y correo.';
        }
        Respuesta::redirigir($volver, $mensaje, $avisar && !$avisado ? 'warn' : 'ok');
    }

    /**
     * Lo que el comité cambió de la propuesta al revisarla.
     *
     * La categoría y la duración se aceptan de las listas de los dos
     * formularios de registro: la propuesta pudo llegar por cualquiera de ellos.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>} [columnas a guardar, cambios en palabras]
     */
    private function ajustesDePropuesta(Peticion $peticion, array $propuesta, int $eventoId): array
    {
        $columnas = [];
        $cambios = [];

        $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', $peticion->campo('titulo')) ?? ''), 0, 200);
        if ($titulo !== '' && mb_strlen($titulo) >= 5 && $titulo !== (string) $propuesta['titulo']) {
            $columnas['titulo'] = $titulo;
            $cambios[] = 'Título: «' . $propuesta['titulo'] . '» pasa a ser «' . $titulo . '».';
        }

        $categoria = $peticion->campo('categoria');
        if ($categoria !== '' && $categoria !== (string) $propuesta['categoria']
            && in_array($categoria, \App\Modelos\Formulario::categoriasDePropuestas($eventoId, (string) $propuesta['categoria']), true)) {
            $columnas['categoria'] = mb_substr($categoria, 0, 80);
            $cambios[] = 'Categoría: «' . $propuesta['categoria'] . '» pasa a ser «' . $categoria . '».';
        }

        $duracion = $peticion->entero('duracion', (int) $propuesta['duracion_min']);
        if ($duracion !== (int) $propuesta['duracion_min']
            && in_array($duracion, \App\Modelos\Formulario::duracionesDePropuestas($eventoId, (int) $propuesta['duracion_min']), true)) {
            $columnas['duracion_min'] = $duracion;
            $cambios[] = 'Duración: de ' . (int) $propuesta['duracion_min'] . ' a ' . $duracion . ' minutos.';
        }

        return [$columnas, $cambios];
    }

    /* =====================================================================
       Equipo
       ===================================================================== */

    public function organizadores(Peticion $peticion): void
    {
        Respuesta::vista('admin/organizadores', [
            'titulo'   => 'Organizadores',
            'pantalla' => 'admin-organizadores',
            'equipo'   => Usuario::todos(),
        ]);
    }

    public function crearUsuario(Peticion $peticion): void
    {
        $clave = $peticion->campoCrudo('clave');

        if (mb_strlen($clave) < 12) {
            Respuesta::redirigir('/admin/organizadores',
                'La contraseña inicial debe tener al menos 12 caracteres.', 'warn');
        }
        if (!filter_var($peticion->campo('correo'), FILTER_VALIDATE_EMAIL)) {
            Respuesta::redirigir('/admin/organizadores', 'Escribe un correo válido.', 'warn');
        }
        if (mb_strlen($peticion->campo('nombre')) < 5) {
            Respuesta::redirigir('/admin/organizadores', 'Escribe el nombre completo.', 'warn');
        }

        try {
            Usuario::crear([
                'nombre'       => $peticion->campo('nombre'),
                'correo'       => $peticion->campo('correo'),
                'clave'        => $clave,
                'rol'          => $peticion->campo('rol', 'operador'),
                'puesto'       => $peticion->campo('puesto'),
                'debe_cambiar' => true,
            ]);
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/organizadores', $e->getMessage(), 'warn');
        }

        Respuesta::redirigir('/admin/organizadores',
            'Cuenta creada. Entrégale la contraseña por un canal seguro; deberá cambiarla al entrar.');
    }

    public function cambiarEstadoUsuario(Peticion $peticion): void
    {
        $yo = Guardia::usuarioActual();
        $id = $peticion->entero('usuario');

        if ($id === (int) $yo['id']) {
            Respuesta::redirigir('/admin/organizadores',
                'No puedes suspender tu propia cuenta.', 'warn');
        }

        $objetivo = Usuario::porId($id);
        if (!$objetivo) {
            Respuesta::redirigir('/admin/organizadores', 'Esa cuenta no existe.', 'warn');
        }

        // Sin administradores activos nadie podría volver a configurar nada.
        if ($objetivo['rol'] === 'administrador'
            && $peticion->campo('estado') === 'suspendido'
            && Usuario::administradoresActivos() <= 1) {
            Respuesta::redirigir('/admin/organizadores',
                'Es el único administrador activo. Nombra otro antes de suspenderlo.', 'warn');
        }

        Usuario::cambiarEstado($id, $peticion->campo('estado'));
        Respuesta::redirigir('/admin/organizadores', 'Estado de la cuenta actualizado.');
    }

    /**
     * Le envía a otra cuenta del equipo un enlace para cambiar su contraseña.
     *
     * Para quien la olvidó y no puede pedirlo él mismo, o para la cuenta recién
     * creada: así la contraseña inicial no tiene que viajar por chat. El enlace
     * no salta la verificación en dos pasos; si además perdió el teléfono, se
     * restablece aparte, con «Restablecer 2FA».
     */
    public function enviarEnlaceClaveUsuario(Peticion $peticion): void
    {
        $yo = Guardia::usuarioActual();
        $id = $peticion->entero('usuario');

        if ($id === (int) $yo['id']) {
            Respuesta::redirigir('/admin/clave', 'Tu propia contraseña la cambias aquí.', 'warn');
        }
        $objetivo = Usuario::porId($id);
        if (!$objetivo) {
            Respuesta::redirigir('/admin/organizadores', 'Esa cuenta no existe.', 'warn');
        }
        if ($objetivo['estado'] !== 'activo') {
            Respuesta::redirigir('/admin/organizadores', 'La cuenta de ' . $objetivo['nombre']
                . ' está suspendida. Reactívala antes de enviarle el enlace.', 'warn');
        }

        [$tipo, $mensaje] = $this->enviarEnlaceDeClave('admin', $objetivo);
        Respuesta::redirigir('/admin/organizadores', $mensaje, $tipo);
    }

    /**
     * Quita el segundo factor de otra persona del equipo.
     *
     * Para quien perdió el teléfono y no puede recibir el código por correo, o
     * cuya configuración quedó ilegible tras un cambio de llave. Al volver a
     * entrar con su contraseña escanea un código QR nuevo. Sus sesiones abiertas
     * se cierran: si el teléfono se perdió, no debe seguir dentro.
     */
    public function restablecerSegundoFactor(Peticion $peticion): void
    {
        $yo = Guardia::usuarioActual();
        $id = $peticion->entero('usuario');

        if ($id === (int) $yo['id']) {
            Respuesta::redirigir('/admin/cuenta',
                'Tu propia verificación en dos pasos se restablece aquí, en Configuración → Mi cuenta.', 'warn');
        }
        $objetivo = Usuario::porId($id);
        if (!$objetivo) {
            Respuesta::redirigir('/admin/organizadores', 'Esa cuenta no existe.', 'warn');
        }

        Usuario::quitarSegundoFactor($id);
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
        Bitacora::registrar('segundo_factor_retirado', 'usuario', $id, ['motivo' => 'lo restableció otra persona']);

        Respuesta::redirigir('/admin/organizadores', 'Se quitó la verificación en dos pasos de '
            . $objetivo['nombre'] . '. Al entrar con su contraseña configurará la aplicación otra vez.');
    }

    /**
     * Cambia el rol de una cuenta del equipo.
     *
     * Hasta ahora el rol solo se elegía al crear la cuenta: para pasar a
     * alguien de consulta a operador —que en un evento pasa el mismo día, con
     * gente que llega a ayudar en la puerta— había que borrar la cuenta y
     * crearla otra vez, perdiendo su historial en la bitácora.
     */
    public function cambiarRolUsuario(Peticion $peticion): void
    {
        $yo = Guardia::usuarioActual();
        $id = $peticion->entero('usuario');
        $rol = $peticion->campo('rol');

        // Quitarse a uno mismo el rol de administrador es la forma más rápida
        // de quedarse fuera del panel sin nadie que lo devuelva.
        if ($id === (int) $yo['id']) {
            Respuesta::redirigir('/admin/organizadores',
                'No puedes cambiar tu propio rol. Pídeselo a otro administrador.', 'warn');
        }

        $objetivo = Usuario::porId($id);
        if (!$objetivo) {
            Respuesta::redirigir('/admin/organizadores', 'Esa cuenta no existe.', 'warn');
        }
        if (!in_array($rol, Usuario::ROLES, true)) {
            Respuesta::redirigir('/admin/organizadores', 'Ese rol no existe.', 'warn');
        }

        // La misma barrera que en el estado: quedarse sin ningún administrador
        // activo deja la plataforma sin nadie que pueda configurarla.
        if ($objetivo['rol'] === 'administrador' && $rol !== 'administrador'
            && $objetivo['estado'] === 'activo'
            && Usuario::administradoresActivos() <= 1) {
            Respuesta::redirigir('/admin/organizadores',
                'Es el único administrador activo. Nombra otro antes de quitarle el rol.', 'warn');
        }

        try {
            Usuario::cambiarRol($id, $rol);
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/organizadores', $e->getMessage(), 'warn');
        }

        Respuesta::redirigir('/admin/organizadores',
            $objetivo['nombre'] . ' ahora es ' . $rol . '. Tendrá que volver a entrar.');
    }

    /* =====================================================================
       Eventos
       ===================================================================== */

    public function eventos(Peticion $peticion): void
    {
        // Lo que se perdería al eliminar cada uno. Se calcula aquí y se enseña
        // en el diálogo: un «¿seguro?» sin un número al lado no es una
        // confirmación, es un trámite que se pulsa sin leer.
        $eventos = Evento::todos();
        foreach ($eventos as &$ev) {
            $ev['contenido'] = Evento::contenido((int) $ev['id']);
        }
        unset($ev);

        Respuesta::vista('admin/eventos', [
            'titulo'   => 'Eventos',
            'pantalla' => 'admin-eventos',
            'eventos'  => $eventos,
            'estados'  => Evento::ESTADOS,
        ]);
    }

    /** Corrige los datos de un evento. Lo que más se usa: un nombre mal escrito. */
    public function editarEvento(Peticion $peticion): void
    {
        $id = $peticion->entero('evento');

        try {
            Evento::actualizar($id, [
                'nombre'       => $peticion->campo('nombre'),
                'dependencia'  => $peticion->campo('dependencia'),
                'sede'         => $peticion->campo('sede'),
                'fecha_inicio' => $peticion->campo('fecha_inicio'),
                'estado'       => $peticion->campo('estado'),
            ], $peticion->marcado('mover_jornadas'));
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/eventos', $e->getMessage(), 'warn');
        }

        App::olvidarEvento();
        Respuesta::redirigir('/admin/eventos', 'Evento actualizado.');
    }

    /**
     * Apaga el evento activo.
     *
     * No borra nada y se deshace activándolo otra vez. Mientras no haya
     * ninguno activo, los asistentes ven la pantalla de «todavía no hay nada»
     * y el equipo, la invitación a crearlo.
     */
    public function desactivarEvento(Peticion $peticion): void
    {
        $id = $peticion->entero('evento');
        if (!Evento::porId($id)) {
            Respuesta::redirigir('/admin/eventos', 'Ese evento no existe.', 'warn');
        }

        Evento::desactivar($id);
        App::olvidarEvento();

        Respuesta::redirigir('/admin/eventos',
            'Evento desactivado. Los asistentes ya no lo ven; actívalo de nuevo cuando quieras.', 'warn');
    }

    /**
     * Elimina un evento con todo lo suyo.
     *
     * Hace falta escribir ELIMINAR. No es capricho: esto se lleva por delante
     * los registros de las personas, sus asistencias y sus contactos, y no hay
     * deshacer. Un botón suelto en una fila de tabla se pulsa por error.
     */
    public function eliminarEvento(Peticion $peticion): void
    {
        $id = $peticion->entero('evento');

        if (mb_strtoupper(trim($peticion->campo('confirmacion'))) !== 'ELIMINAR') {
            Respuesta::redirigir('/admin/eventos',
                'Para eliminar un evento hay que escribir ELIMINAR en la confirmación.', 'warn');
        }

        try {
            $contenido = Evento::eliminar($id);
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/eventos', $e->getMessage(), 'warn');
        }

        App::olvidarEvento();

        Respuesta::redirigir('/admin/eventos', 'Evento eliminado, con sus '
            . $contenido['jornadas'] . ' jornadas y ' . $contenido['personas'] . ' registros.', 'warn');
    }

    public function crearEvento(Peticion $peticion): void
    {
        $nombre = $peticion->campo('nombre');
        $fecha = $peticion->campo('fecha_inicio');
        $jornadas = $peticion->entero('jornadas', 1);

        if (mb_strlen($nombre) < 3) {
            Respuesta::redirigir('/admin/eventos', 'Escribe el nombre del evento.', 'warn');
        }
        if (!Evento::fechaValida($fecha)) {
            Respuesta::redirigir('/admin/eventos', 'Elige una fecha de inicio válida.', 'warn');
        }

        $id = Evento::crear([
            'nombre'       => $nombre,
            'dependencia'  => $peticion->campo('dependencia'),
            'sede'         => $peticion->campo('sede'),
            'fecha_inicio' => $fecha,
            'jornadas'     => $jornadas,
            'estado'       => 'borrador',
            'activo'       => false,
        ]);

        Bitacora::registrar('evento_creado', 'evento', $id);
        Respuesta::redirigir('/admin/eventos',
            'Evento creado como borrador. Configura su identidad antes de activarlo.');
    }

    public function activarEvento(Peticion $peticion): void
    {
        $id = $peticion->entero('evento');
        if (!Evento::porId($id)) {
            Respuesta::redirigir('/admin/eventos', 'Ese evento no existe.', 'warn');
        }

        Evento::activar($id);
        App::olvidarEvento();
        Respuesta::redirigir('/admin/eventos', 'Ese es ahora el evento activo.');
    }

    /* =====================================================================
       Identidad
       ===================================================================== */

    public function identidad(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $tema = Tema::del((int) $evento['id']);

        Respuesta::vista('admin/identidad', [
            'titulo'      => 'Identidad',
            'pantalla'    => 'configuracion',
            'temaEvento'  => $tema,
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'contraste'   => Tema::revisarContraste($tema['colores']),
        ]);
    }

    public function guardarIdentidad(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];

        Bd::actualizar('evento', [
            'nombre'      => mb_substr($peticion->campo('nombre') ?: (string) $evento['nombre'], 0, 160),
            'dependencia' => mb_substr($peticion->campo('dependencia'), 0, 160),
            'sede'        => mb_substr($peticion->campo('sede'), 0, 160),
        ], 'id = :id', ['id' => $eventoId]);

        $colores = [];
        foreach (['brand', 'accent', 'bg', 'surface', 'sunken', 'line', 'title', 'text', 'muted', 'onBrand'] as $clave) {
            $valor = $peticion->campo('color_' . $clave);
            if ($valor !== '' && Tema::hexValido($valor)) {
                $colores[$clave] = $valor;
            }
        }

        $datos = [
            'preset'     => $peticion->campo('preset', 'tic-nocturno'),
            'tipografia' => $peticion->campo('tipografia', 'tecnologica'),
            'colores'    => $colores,
        ];

        $archivo = $peticion->archivo('logo');
        if ($archivo !== null) {
            try {
                [$nombreArchivo, $tipo] = $this->guardarLogo($archivo, $eventoId);
                $datos['logo_archivo'] = $nombreArchivo;
                $datos['logo_tipo'] = $tipo;
            } catch (\DomainException $e) {
                Respuesta::redirigir('/admin/identidad', $e->getMessage(), 'warn');
            }
        }
        if ($peticion->marcado('quitar_logo')) {
            $datos['logo_archivo'] = '';
            $datos['logo_tipo'] = '';
        }

        Tema::guardar($eventoId, $datos);
        App::olvidarEvento();
        Bitacora::registrar('identidad_guardada', 'evento', $eventoId, [
            'preset' => $datos['preset'],
            'tipografia' => $datos['tipografia'],
        ]);

        Respuesta::redirigir('/admin/identidad', 'Identidad guardada y aplicada a toda la plataforma.');
    }

    /**
     * Guarda el logo del evento.
     *
     * Los controles importantes están aquí:
     *   · el tipo se decide por el contenido real del archivo, no por su
     *     extensión ni por lo que declare el navegador;
     *   · los mapas de bits se vuelven a generar, lo que descarta cualquier
     *     carga útil escondida en los metadatos;
     *   · el SVG se limpia de scripts y referencias externas antes de guardar;
     *   · el nombre lo pone el servidor.
     * Ver docs/SEGURIDAD.md, sección de subida de archivos.
     */
    private function guardarLogo(array $archivo, int $eventoId): array
    {
        if (($archivo['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new \DomainException('No se pudo recibir el archivo. ¿Supera el límite del servidor?');
        }
        if (($archivo['size'] ?? 0) > 512 * 1024) {
            throw new \DomainException('El logo pesa más de 512 KB. Reduce el archivo e inténtalo de nuevo.');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \DomainException('El archivo no llegó por una subida válida.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $tipoReal = (string) $finfo->file($archivo['tmp_name']);

        $permitidos = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'text/plain'    => 'svg',   // algunos servidores detectan así el SVG
            'text/xml'      => 'svg',
        ];
        if (!isset($permitidos[$tipoReal])) {
            throw new \DomainException('Formato no admitido. Usa SVG, PNG, JPG o WEBP.');
        }
        $extension = $permitidos[$tipoReal];

        $directorio = RAIZ . '/almacen/logos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        $nombre = 'evento-' . $eventoId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destino = $directorio . '/' . $nombre;

        if ($extension === 'svg') {
            $contenido = (string) file_get_contents($archivo['tmp_name']);
            if (!str_contains($contenido, '<svg')) {
                throw new \DomainException('El archivo no parece un SVG válido.');
            }
            file_put_contents($destino, $this->limpiarSvg($contenido));
            $tipoGuardado = 'image/svg+xml';
        } else {
            $this->reescribirImagen($archivo['tmp_name'], $destino, $extension);
            $tipoGuardado = $tipoReal === 'image/jpeg' ? 'image/jpeg'
                : ($tipoReal === 'image/webp' ? 'image/webp' : 'image/png');
        }
        @chmod($destino, 0640);

        // Se borra el logo anterior para no dejar archivos huérfanos.
        $temaAnterior = Tema::del($eventoId);
        if ($temaAnterior['logo'] !== '' && $temaAnterior['logo'] !== $nombre) {
            @unlink($directorio . '/' . basename($temaAnterior['logo']));
        }

        return [$nombre, $tipoGuardado];
    }

    /**
     * Quita del SVG todo lo que pueda ejecutar o traer contenido externo.
     *
     * Un SVG es un documento XML capaz de contener JavaScript. Aunque la
     * plataforma lo sirve con Content-Disposition y una política restrictiva,
     * limpiarlo antes de guardarlo evita depender de una sola barrera.
     */
    private function limpiarSvg(string $svg): string
    {
        $patrones = [
            // Elementos peligrosos con todo su contenido.
            '#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            // Y sus versiones vacías o sin cerrar.
            '#<\s*(?:script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*/?>#i',
            // Atributos de evento: onload, onclick, onerror…
            '#\son[a-z]+\s*=\s*(["\']).*?\1#is',
            '#\son[a-z]+\s*=\s*[^\s>]+#i',
            // Referencias a otros orígenes; las internas (#id) se conservan.
            '#(?:xlink:href|href)\s*=\s*(["\'])\s*(?!\#)[^"\']*\1#i',
            '#javascript\s*:#i',
            // Declaraciones de entidades: la vía de los ataques XXE.
            '#<!DOCTYPE.*?>#is',
            '#<!ENTITY.*?>#is',
        ];

        // Se repite hasta que el texto deja de cambiar, y no una sola vez.
        // Con una sola pasada, «<scr<script>ipt>» se queda en «<script>»: al
        // quitar la etiqueta de dentro, los dos trozos de fuera se juntan y
        // reconstruyen justo lo que se quería eliminar. El tope evita que un
        // archivo preparado a mala fe haga girar esto para siempre.
        for ($vuelta = 0; $vuelta < 10; $vuelta++) {
            $antes = $svg;
            foreach ($patrones as $patron) {
                $svg = preg_replace($patron, '', $svg) ?? $svg;
            }
            if ($svg === $antes) {
                break;
            }
        }

        return $svg;
    }

    /** Vuelve a generar la imagen: descarta metadatos y cargas ocultas. */
    private function reescribirImagen(string $origen, string $destino, string $extension): void
    {
        if (!function_exists('imagecreatefromstring')) {
            // Sin gd no se puede reescribir; se copia tal cual, que sigue siendo
            // seguro porque nunca se sirve como código.
            copy($origen, $destino);
            return;
        }

        $imagen = @imagecreatefromstring((string) file_get_contents($origen));
        if ($imagen === false) {
            throw new \DomainException('La imagen está dañada o no se pudo leer.');
        }

        // Un logo no necesita más de 600 px de lado.
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $maximo = 600;
        if ($ancho > $maximo || $alto > $maximo) {
            $escala = $maximo / max($ancho, $alto);
            $nueva = imagescale($imagen, (int) round($ancho * $escala), (int) round($alto * $escala));
            if ($nueva !== false) {
                imagedestroy($imagen);
                $imagen = $nueva;
            }
        }

        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        match ($extension) {
            'jpg'  => imagejpeg($imagen, $destino, 88),
            'webp' => imagewebp($imagen, $destino, 88),
            default => imagepng($imagen, $destino, 9),
        };
        imagedestroy($imagen);
    }

    /* =====================================================================
       Correo
       -------------------------------------------------------------------------
       Sin correo, la plataforma pierde la mitad de lo que hace: el asistente
       que cierra sesión ya no puede volver a entrar —el código de acceso es lo
       único que lo identifica— y los carnets no salen de la pantalla en que se
       generaron. Por eso esta pantalla no se limita a guardar unos campos:
       revisa la configuración, prueba contra el servidor de verdad y explica
       cada código de error con el arreglo concreto.
       ===================================================================== */

    public function correo(Peticion $peticion): void
    {
        Respuesta::vista('admin/correo', [
            'titulo'    => 'Acceso y correo',
            'pantalla'  => 'configuracion',
            'metodos'      => \App\Nucleo\Autenticacion::activos(),
            'catalogo'     => \App\Nucleo\Autenticacion::METODOS,
            'preferido'    => \App\Nucleo\Autenticacion::preferido(),
            'revisionAuth' => \App\Nucleo\Autenticacion::revision(),
            'mensajeria'   => \App\Nucleo\Mensajeria::PROVEEDORES,
            'auth'         => $this->ajustesDeAutenticacion(),
            'ajustes'   => $this->ajustesDeCorreo(),
            'revision'  => Correo::revision(),
            'prueba'    => Sesion::datos('admin')['prueba_correo'] ?? null,
            'red'       => Sesion::datos('admin')['red_correo'] ?? null,
            'proveedores' => \App\Nucleo\CorreoApi::PROVEEDORES,
            'sugerido'  => (string) (Guardia::usuarioActual()['correo'] ?? ''),
        ]);

        // El resultado de la última prueba se enseña una sola vez.
        $this->olvidarPrueba();
    }

    public function guardarCorreo(Peticion $peticion): void
    {
        $modo = $peticion->campo('modo_correo', 'php');
        if (!in_array($modo, ['smtp', 'api', 'php', 'registro'], true)) {
            Respuesta::redirigir('/admin/correo', 'Ese modo de envío no existe.', 'warn');
        }

        $remitente = mb_strtolower(trim($peticion->campo('correo_remitente')));
        if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            Respuesta::redirigir('/admin/correo', 'El remitente no es una dirección válida.', 'warn');
        }

        $puerto = (int) $peticion->campo('smtp_puerto', '587');
        if ($puerto < 1 || $puerto > 65535) {
            Respuesta::redirigir('/admin/correo', 'El puerto debe estar entre 1 y 65535.', 'warn');
        }

        $seguridad = $peticion->campo('smtp_seguridad', 'tls');
        if (!in_array($seguridad, ['tls', 'ssl', 'ninguna'], true)) {
            Respuesta::redirigir('/admin/correo', 'Esa opción de seguridad no existe.', 'warn');
        }

        // La contraseña solo se toca si escribieron una nueva. El formulario
        // nunca la devuelve al navegador, así que un campo vacío significa
        // «déjala como está», no «bórrala».
        $clave = $peticion->campoCrudo('smtp_clave');
        $claveActual = (string) Config::obtener('smtp_clave', '');
        if (trim($clave) === '') {
            $clave = $peticion->marcado('borrar_clave') ? '' : $claveActual;
        } else {
            // Google enseña la contraseña de aplicación en cuatro grupos de
            // cuatro letras. Copiarla con los espacios es lo natural y lo que
            // hace todo el mundo; el servidor no los admite.
            $clave = (string) preg_replace('/\s+/', '', $clave);
        }

        $proveedor = $peticion->campo('api_proveedor', 'brevo');
        if (!\App\Nucleo\CorreoApi::conocido($proveedor)) {
            Respuesta::redirigir('/admin/correo', 'Ese proveedor de API no existe.', 'warn');
        }

        // Igual que la del SMTP: vacío significa «déjala como está».
        $claveApi = trim($peticion->campoCrudo('api_clave'));
        if ($claveApi === '') {
            $claveApi = $peticion->marcado('borrar_clave_api')
                ? ''
                : (string) Config::obtener('api_clave', '');
        }

        $nuevos = [
            'modo_correo'                => $modo,
            'api_proveedor'              => $proveedor,
            'api_clave'                  => $claveApi,
            'correo_remitente'           => $remitente,
            'correo_nombre'              => mb_substr(trim($peticion->campo('correo_nombre')), 0, 120),
            'smtp_host'                  => mb_substr(trim($peticion->campo('smtp_host')), 0, 190),
            'smtp_puerto'                => $puerto,
            'smtp_seguridad'             => $seguridad,
            'smtp_usuario'               => mb_substr(trim($peticion->campo('smtp_usuario')), 0, 190),
            'smtp_clave'                 => $clave,
            'smtp_espera'                => max(5, min(60, (int) $peticion->campo('smtp_espera', '15'))),
            'smtp_verificar_certificado' => $peticion->marcado('smtp_verificar_certificado'),
            'smtp_solo_ipv4'             => $peticion->marcado('smtp_solo_ipv4'),
        ];

        if (!Config::escribir($nuevos + Config::todo())) {
            Respuesta::redirigir('/admin/correo',
                'No se pudo escribir config/config.php. Revisa los permisos de la carpeta config/.', 'warn');
        }
        Config::establecerEnMemoria($nuevos);

        // En la bitácora no entra la contraseña, solo que se cambió.
        Bitacora::registrar('correo_configurado', 'sistema', null, [
            'modo'        => $modo,
            'host'        => $nuevos['smtp_host'],
            'puerto'      => $puerto,
            'seguridad'   => $seguridad,
            'usuario'     => $nuevos['smtp_usuario'],
            'proveedor'   => $proveedor,
            'clave_nueva' => trim($peticion->campoCrudo('smtp_clave')) !== ''
                || trim($peticion->campoCrudo('api_clave')) !== '',
        ]);

        Respuesta::redirigir('/admin/correo', 'Configuración de correo guardada.', 'ok');
    }

    public function probarCorreo(Peticion $peticion): void
    {
        $destinatario = mb_strtolower(trim($peticion->campo('destinatario')));
        if ($destinatario !== '' && !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            Respuesta::redirigir('/admin/correo', 'La dirección de prueba no es válida.', 'warn');
        }

        // Cada prueba abre una conexión y espera hasta quince segundos. Sin
        // límite, el botón es una forma cómoda de tener el servidor ocupado.
        $quien = (string) (Guardia::usuarioActual()['id'] ?? '0');
        $espera = Limite::bloqueado('probar_correo', $quien);
        if ($espera > 0) {
            Respuesta::redirigir('/admin/correo',
                'Demasiadas pruebas seguidas. Vuelve a intentarlo en ' . ceil($espera / 60) . ' min.', 'warn');
        }
        Limite::registrar('probar_correo', $quien);

        $resultado = Correo::probar($destinatario);

        Bitacora::registrar('correo_probado', 'sistema', null, [
            'destinatario' => $destinatario !== '' ? $destinatario : '(solo conexión)',
            'ok'           => $resultado['ok'],
            'codigo'       => $resultado['codigo'],
        ]);

        // Va a la sesión y no a la URL: la transcripción es larga y además
        // lleva nombres de servidor que no tienen por qué quedar en el historial
        // del navegador ni en los registros del proxy.
        Sesion::guardarDatos('admin', ['prueba_correo' => $resultado] + Sesion::datos('admin'));

        Respuesta::redirigir('/admin/correo',
            $resultado['ok'] ? 'Prueba correcta.' : 'La prueba falló: mira el detalle.',
            $resultado['ok'] ? 'ok' : 'warn');
    }

    /**
     * Prueba la salida de red hasta el servidor de correo.
     *
     * Separado de probarCorreo() a propósito: cuando la conexión no se abre, la
     * pregunta ya no es de correo sino de red, y responderla necesita mirar el
     * DNS y cada puerto por separado. Con «Network is unreachable» a secas se
     * piden aperturas de puerto que muchas veces ya estaban hechas.
     */
    /**
     * Deja la configuración apuntando al servidor de correo de la propia máquina.
     *
     * Un botón y no un instructivo, porque es la salida cuando el proveedor
     * bloquea la salida SMTP y hay que aplicarla deprisa: servidor localhost,
     * sin cifrado y sin credenciales. Conectarse a 127.0.0.1 no es tráfico
     * saliente, así que el bloqueo no le aplica; es la misma ruta por la que
     * WordPress envía en este servidor.
     */
    public function usarCorreoLocal(Peticion $peticion): void
    {
        $puerto = (int) $peticion->campo('puerto', '25');
        if (!in_array($puerto, [25, 587, 465], true)) {
            $puerto = 25;
        }

        $nuevos = [
            'modo_correo'    => 'smtp',
            'smtp_host'      => 'localhost',
            'smtp_puerto'    => $puerto,
            'smtp_seguridad' => $puerto === 465 ? 'ssl' : 'ninguna',
            'smtp_usuario'   => '',
            'smtp_clave'     => '',
            // El relé de la propia máquina suele traer certificado autofirmado,
            // y con «sin cifrar» esto no se usa; queda puesto para que cambiar
            // a 465 después no falle por el certificado.
            'smtp_verificar_certificado' => false,
        ];

        if (!Config::escribir($nuevos + Config::todo())) {
            Respuesta::redirigir('/admin/correo',
                'No se pudo escribir config/config.php. Revisa los permisos de config/.', 'warn');
        }
        Config::establecerEnMemoria($nuevos);

        Bitacora::registrar('correo_configurado', 'sistema', null, [
            'modo' => 'smtp', 'host' => 'localhost', 'puerto' => $puerto, 'via' => 'boton_local',
        ]);

        Respuesta::redirigir('/admin/correo',
            'Configurado para el correo local (localhost:' . $puerto . '). Prueba el envío, y '
            . 'revisa el aviso sobre el dominio del remitente.', 'ok');
    }

    public function probarRedCorreo(Peticion $peticion): void
    {
        $quien = (string) (Guardia::usuarioActual()['id'] ?? '0');
        $espera = Limite::bloqueado('probar_correo', $quien);
        if ($espera > 0) {
            Respuesta::redirigir('/admin/correo',
                'Demasiadas pruebas seguidas. Vuelve a intentarlo en ' . ceil($espera / 60) . ' min.', 'warn');
        }
        Limite::registrar('probar_correo', $quien);

        $red = Correo::diagnosticoDeRed();

        Bitacora::registrar('correo_red_probada', 'sistema', null, [
            'host'  => $red['host'],
            'ipv4'  => count($red['ipv4']),
            'ipv6'  => count($red['ipv6']),
            'abren' => count(array_filter($red['intentos'], static fn(array $i): bool => $i['ok'])),
        ]);

        Sesion::guardarDatos('admin', ['red_correo' => $red] + Sesion::datos('admin'));
        Respuesta::redirigir('/admin/correo', 'Diagnóstico de red terminado.', 'ok');
    }

    /** Los métodos de acceso y lo que necesita cada uno, sin credenciales. */
    private function ajustesDeAutenticacion(): array
    {
        return [
            'claveMinima' => \App\Nucleo\Autenticacion::claveMinima(),
            'waProveedor' => (string) Config::obtener('wa_proveedor', 'meta'),
            'waCuenta'    => (string) Config::obtener('wa_cuenta', ''),
            'waRemitente' => (string) Config::obtener('wa_remitente', ''),
            'hayWaToken'  => (string) Config::obtener('wa_token', '') !== '',
            'smsProveedor' => (string) Config::obtener('sms_proveedor', 'twilio'),
            'smsCuenta'    => (string) Config::obtener('sms_cuenta', ''),
            'smsRemitente' => (string) Config::obtener('sms_remitente', ''),
            'haySmsToken'  => (string) Config::obtener('sms_token', '') !== '',
        ];
    }

    /** Guarda los métodos de acceso y su configuración. */
    /**
     * Guarda la configuración de autenticación.
     *
     * La pantalla está organizada por pestañas y cada una envía lo suyo, así
     * que el campo «seccion» dice qué parte llegó. Sin él, guardar WhatsApp
     * habría borrado la configuración de SMS —los campos ausentes en un envío
     * llegan vacíos, no «sin cambios»—, que es el error clásico al partir un
     * formulario largo en varios.
     *
     * «todo» conserva el comportamiento anterior, para cualquier enlace o
     * guion que siga enviando el formulario completo.
     */
    public function guardarAutenticacion(Peticion $peticion): void
    {
        $seccion = $peticion->campo('seccion', 'todo');
        if (!in_array($seccion, ['todo', 'metodos', 'clave', 'whatsapp', 'sms'], true)) {
            Respuesta::redirigir('/admin/autenticacion', 'Sección desconocida.', 'warn');
        }

        $nuevos = [];
        $resumen = 'Configuración guardada.';

        // Igual que en el correo: vacío significa «déjala como está».
        $tokenDe = function (string $prefijo) use ($peticion): string {
            $nuevo = trim($peticion->campoCrudo($prefijo . '_token'));
            if ($nuevo !== '') {
                return $nuevo;
            }
            return $peticion->marcado('borrar_' . $prefijo . '_token')
                ? ''
                : (string) Config::obtener($prefijo . '_token', '');
        };

        if ($seccion === 'todo' || $seccion === 'metodos') {
            $validos = array_values(array_filter(
                $peticion->campoArreglo('metodos'),
                static fn($m): bool => is_string($m) && isset(Autenticacion::METODOS[$m])
            ));
            if ($validos === []) {
                Respuesta::redirigir('/admin/autenticacion',
                    'Deja al menos un método activo: si no, nadie podría entrar.', 'warn');
            }

            $preferido = $peticion->campo('metodo_preferido', $validos[0]);
            if (!in_array($preferido, $validos, true)) {
                $preferido = $validos[0];
            }

            $nuevos['auth_metodos'] = $validos;
            $nuevos['auth_metodo_preferido'] = $preferido;
            $resumen = 'Métodos de acceso guardados: ' . implode(', ', $validos) . '.';

            Bitacora::registrar('autenticacion_configurada', 'sistema', null, [
                'metodos'   => $validos,
                'preferido' => $preferido,
            ]);
        }

        if ($seccion === 'todo' || $seccion === 'clave') {
            $nuevos['auth_clave_minima'] = max(6, min(64, (int) $peticion->campo('auth_clave_minima', '8')));
            if ($seccion === 'clave') {
                $resumen = 'La contraseña mínima queda en ' . $nuevos['auth_clave_minima'] . ' caracteres.';
            }
        }

        if ($seccion === 'todo' || $seccion === 'whatsapp') {
            $proveedor = $peticion->campo('wa_proveedor', 'meta');
            if (!\App\Nucleo\Mensajeria::conocido('whatsapp', $proveedor)) {
                Respuesta::redirigir('/admin/autenticacion', 'Ese proveedor de WhatsApp no existe.', 'warn');
            }
            $nuevos['wa_proveedor'] = $proveedor;
            $nuevos['wa_cuenta']    = mb_substr(trim($peticion->campo('wa_cuenta')), 0, 190);
            $nuevos['wa_remitente'] = mb_substr(trim($peticion->campo('wa_remitente')), 0, 40);
            $nuevos['wa_token']     = $tokenDe('wa');
            if ($seccion === 'whatsapp') {
                $resumen = 'Configuración de WhatsApp guardada.';
            }
        }

        if ($seccion === 'todo' || $seccion === 'sms') {
            $proveedor = $peticion->campo('sms_proveedor', 'twilio');
            if (!\App\Nucleo\Mensajeria::conocido('sms', $proveedor)) {
                Respuesta::redirigir('/admin/autenticacion', 'Ese proveedor de SMS no existe.', 'warn');
            }
            $nuevos['sms_proveedor'] = $proveedor;
            $nuevos['sms_cuenta']    = mb_substr(trim($peticion->campo('sms_cuenta')), 0, 400);
            $nuevos['sms_remitente'] = mb_substr(trim($peticion->campo('sms_remitente')), 0, 40);
            $nuevos['sms_token']     = $tokenDe('sms');
            if ($seccion === 'sms') {
                $resumen = 'Configuración de SMS guardada.';
            }
        }

        if (!Config::escribir($nuevos + Config::todo())) {
            Respuesta::redirigir('/admin/autenticacion',
                'No se pudo escribir config/config.php. Revisa los permisos de config/.', 'warn');
        }
        Config::establecerEnMemoria($nuevos);

        Respuesta::redirigir('/admin/autenticacion', $resumen, 'ok');
    }

    /** Lo que la pantalla necesita, sin la contraseña. */
    private function ajustesDeCorreo(): array
    {
        return [
            'modo'         => (string) Config::obtener('modo_correo', 'php'),
            'remitente'    => (string) Config::obtener('correo_remitente', ''),
            'nombre'       => (string) Config::obtener('correo_nombre', ''),
            'host'         => (string) Config::obtener('smtp_host', ''),
            'puerto'       => (int) Config::obtener('smtp_puerto', 587),
            'seguridad'    => (string) Config::obtener('smtp_seguridad', 'tls'),
            'usuario'      => (string) Config::obtener('smtp_usuario', ''),
            // Solo si hay contraseña guardada, nunca cuál.
            'hayClave'     => (string) Config::obtener('smtp_clave', '') !== '',
            'apiProveedor' => (string) Config::obtener('api_proveedor', 'brevo'),
            'hayClaveApi'  => (string) Config::obtener('api_clave', '') !== '',
            'espera'       => (int) Config::obtener('smtp_espera', 15),
            'verificar'    => (bool) Config::obtener('smtp_verificar_certificado', true),
            'soloIpv4'     => (bool) Config::obtener('smtp_solo_ipv4', false),
        ];
    }

    private function olvidarPrueba(): void
    {
        $datos = Sesion::datos('admin');
        if (isset($datos['prueba_correo']) || isset($datos['red_correo'])) {
            unset($datos['prueba_correo'], $datos['red_correo']);
            Sesion::guardarDatos('admin', $datos);
        }
    }
}
