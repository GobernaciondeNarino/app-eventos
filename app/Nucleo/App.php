<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Arranque de la aplicación.
 *
 * Orden deliberado: primero las cabeceras de seguridad —que deben salir aunque
 * después todo falle—, luego la configuración, luego la base de datos, y solo
 * al final el despacho de la ruta.
 */
final class App
{
    private static ?Peticion $peticion = null;
    private static ?array $evento = null;

    public static function peticion(): Peticion
    {
        return self::$peticion ??= new Peticion();
    }

    public static function arrancar(): never
    {
        self::configurarErrores();
        $peticion = self::peticion();
        self::cabeceras($peticion);

        // Valores mínimos para que la plantilla pueda pintarse desde el primer
        // instante. Sin esto, un error ocurrido antes de conectar a la base de
        // datos —o el propio instalador— reventaría al intentar dibujar la
        // página de error, y el visitante vería una pantalla en blanco.
        self::compartirBase();

        Config::cargar();

        date_default_timezone_set((string) Config::obtener('zona_horaria', 'America/Bogota'));

        // Sin instalación no hay base de datos a la que conectarse, así que se
        // despacha de una vez: la única ruta que responderá es el asistente,
        // que abre su propia conexión cuando el usuario le da los datos.
        //
        // Antes de dar eso por bueno se comprueba si la base ya está lista: una
        // instalación terminada a la que solo le falta la marca no debe mandar
        // a todo el mundo al asistente.
        if (!Config::instalado() && !self::rescatarInstalacion()) {
            self::modoInstalacion($peticion);
            self::despachar($peticion);
        }

        try {
            Bd::conectar();
        } catch (\Throwable $e) {
            Registro::excepcion($e);

            // El diagnóstico es justo lo que hace falta cuando la base no
            // responde, así que tiene que poder abrirse sin ella. Sabe
            // arreglárselas: la clase Instalacion atrapa el fallo de conexión y
            // lo cuenta como parte del informe.
            if ($peticion->ruta() === '/instalar/diagnostico') {
                self::despachar($peticion);
            }

            Respuesta::error(503, 'Base de datos fuera de servicio',
                'La plataforma no puede conectarse a su base de datos en este momento. '
                . 'Si el problema continúa, avisa al área de sistemas.',
                [['texto' => 'Ver el diagnóstico', 'url' => Url::a('/instalar/diagnostico'), 'principal' => true]]);
        }

        // Antes de tocar sesiones ni cuentas, la base se pone al día con el
        // código recién subido. El asistente queda fuera: tiene sus propios
        // modos y quien lo abre quiere decidir qué se hace.
        if (!str_starts_with($peticion->ruta(), '/instalar')) {
            Actualizacion::alDia();
        }

        Sesion::limpiar();
        self::compartirConVistas();

        // El testigo se emite en cualquier página, no solo donde hay un
        // formulario: quien llega directo a una pantalla que envía por POST
        // —el lector de QR abre rutas sueltas— no debería toparse con un
        // «la página expiró» sin haber hecho nada raro.
        if (!$peticion->esPost()) {
            Csrf::token();
        }

        self::despachar($peticion);
    }

    private static function despachar(Peticion $peticion): never
    {
        $enrutador = new Enrutador();
        require RAIZ . '/app/rutas.php';
        $enrutador->despachar($peticion);
    }

    /* =====================================================================
       Modo instalación
       ===================================================================== */

    private static function modoInstalacion(Peticion $peticion): void
    {
        $ruta = $peticion->ruta();
        // Los recursos estáticos tienen que seguir sirviéndose para que el
        // asistente se vea con su propio diseño.
        if (str_starts_with($ruta, '/assets/')) {
            return;
        }
        if (!str_starts_with($ruta, '/instalar')) {
            Respuesta::redirigirAbsoluto(Url::a('/instalar'));
        }
    }

    /**
     * Rescate: la base está lista pero la configuración dice que no.
     *
     * Pasó en producción y no es un caso teórico. Si config/config.php tiene
     * credenciales válidas y al otro lado hay un esquema completo, una cuenta
     * administradora y un evento, la plataforma funciona: lo único que falta es
     * la marca. Insistir en el asistente entonces solo consigue que todas las
     * direcciones redirijan a él sin que nadie entienda por qué.
     *
     * Se corrige la marca en disco y se sigue. Es deliberadamente estricto: sin
     * cuenta con la que entrar no se rescata nada, porque entonces el asistente
     * sí es lo que hace falta.
     */
    private static function rescatarInstalacion(): bool
    {
        if (!Config::existe() || Config::obtener('bd_nombre') === null) {
            return false;
        }

        try {
            Bd::conectar();
        } catch (\Throwable) {
            return false;
        }

        if (!Instalacion::completa() || !Instalacion::hayEvento()) {
            return false;
        }

        // Que no se pueda escribir el archivo no es motivo para no seguir: la
        // plataforma funciona igual. Solo se anota cuando la corrección queda
        // grabada, o si no el registro se llenaría con una línea por petición.
        if (Config::escribir(['instalado' => true] + Config::todo())) {
            Registro::aviso('La configuración decía que la plataforma no estaba instalada, pero la '
                . 'base de datos está completa. Se corrigió la marca y se continuó.');
        }
        Config::establecerEnMemoria(['instalado' => true]);
        Instalacion::olvidar();
        return true;
    }

    /* =====================================================================
       Cabeceras de seguridad
       ===================================================================== */

    private static function cabeceras(Peticion $peticion): void
    {
        // Se envían desde PHP además de desde .htaccess. En Plesk es común que
        // mod_headers no esté activo o que nginx sirva por delante sin aplicar
        // las reglas de Apache; así la protección no depende de eso.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');

        // La cámara del escáner y el resto de la aplicación son del mismo
        // origen; no hay CDN. La excepción de estilos en línea está explicada
        // en docs/SEGURIDAD.md.
        //
        // Cloudflare, si tiene Web Analytics encendido, inyecta su beacon en el
        // HTML de salida. La política lo bloquea y la consola del navegador se
        // queja: no rompe nada —la aplicación no depende de ese script— pero
        // asusta a quien la abre. Se puede permitir a propósito desde la
        // configuración; por omisión no, porque una política más estrecha es
        // mejor que una cómoda y esto se apaga también desde el panel de
        // Cloudflare. Ver docs/SEGURIDAD.md.
        $scripts = "'self'";
        $conexiones = "'self'";
        if (Config::obtener('permitir_cloudflare_analytics', false)) {
            $scripts .= ' https://static.cloudflareinsights.com';
            $conexiones .= ' https://cloudflareinsights.com';
        }

        header("Content-Security-Policy: default-src 'self'; script-src {$scripts}; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; "
            . "connect-src {$conexiones}; media-src 'self' blob:; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

        if ($peticion->esSegura()) {
            // Sin includeSubDomains. Esta aplicación vive en una subcarpeta de
            // un dominio compartido con otras: no le corresponde obligar a todo
            // narino.gov.co y a cada uno de sus subdominios a hablar solo por
            // HTTPS durante un año. Esa decisión es del área de sistemas, y se
            // toma en el servidor, no aquí.
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    /* =====================================================================
       Errores
       ===================================================================== */

    private static function configurarErrores(): void
    {
        // Nunca se muestran los errores al visitante: una traza revela rutas del
        // servidor, nombres de tablas y a veces credenciales.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_exception_handler(static function (\Throwable $e): void {
            Registro::excepcion($e);

            $detalle = self::detalleDelFallo($e);
            if ($detalle !== []) {
                Respuesta::error(500, 'La instalación se interrumpió',
                    'Algo falló antes de terminar de instalar la plataforma. Abajo está la causa exacta.',
                    [
                        ['texto' => 'Volver al asistente', 'url' => Url::a('/instalar'), 'principal' => true],
                        ['texto' => 'Ver el diagnóstico', 'url' => Url::a('/instalar/diagnostico')],
                    ],
                    $detalle);
            }

            Respuesta::error(500, 'Algo salió mal',
                'Se registró el problema para revisarlo. Vuelve a intentarlo en unos minutos.');
        });

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if (!(error_reporting() & $nivel)) {
                return false;
            }
            throw new \ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Registro::error($error['message'], [
                    'archivo' => $error['file'] . ':' . $error['line'],
                ]);
            }
        });
    }

    /**
     * Qué se puede contar en pantalla sobre un fallo.
     *
     * Devuelve líneas mientras la instalación no ha terminado —o con «depurar»
     * encendido a mano—, y un array vacío el resto del tiempo.
     *
     * Que se vea antes de instalar no es una fuga y sí es imprescindible.
     * Todavía no existe la llave de cifrado, ni una sola cuenta, ni un dato de
     * ninguna persona: no hay nada que filtrar. Lo que sí había era una
     * pantalla que decía «se registró el problema para revisarlo» a alguien que
     * no tiene consola, no puede leer almacen/registro/ y no tiene forma
     * humana de averiguar qué pasó. Esa pantalla dejaba la instalación muerta
     * sin ninguna pista, que es exactamente lo que ocurrió en producción.
     *
     * En cuanto config/config.php existe con la marca de instalado, esto se
     * apaga solo y se vuelve al mensaje genérico de siempre.
     *
     * @return array<int, string>
     */
    private static function detalleDelFallo(\Throwable $e): array
    {
        $depurando = (bool) Config::obtener('depurar', false);
        if (!$depurando && Config::instalado()) {
            return [];
        }

        $lineas = [];
        for ($actual = $e, $nivel = 0; $actual !== null && $nivel < 3; $actual = $actual->getPrevious(), $nivel++) {
            $lineas[] = ($nivel === 0 ? '' : 'Causado por: ')
                . get_class($actual) . ': ' . $actual->getMessage();
            $lineas[] = '  en ' . self::rutaCorta($actual->getFile()) . ':' . $actual->getLine();
        }

        // Las primeras llamadas bastan para situar el fallo, y la traza entera
        // en una página de error solo es ruido.
        $traza = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
        foreach ($traza as $marco) {
            $lineas[] = '  ' . self::rutaCorta(trim($marco));
        }

        $lineas[] = '';
        $lineas[] = 'PHP ' . PHP_VERSION . ' · ' . date('Y-m-d H:i:s');

        return $lineas;
    }

    /** La ruta del servidor no le dice nada a nadie y sí revela la cuenta. */
    private static function rutaCorta(string $texto): string
    {
        return str_replace(RAIZ . '/', '', str_replace(RAIZ, '…', $texto));
    }

    /* =====================================================================
       Contexto de las vistas
       ===================================================================== */

    /** Contexto mínimo, disponible incluso sin base de datos. */
    private static function compartirBase(): void
    {
        Respuesta::compartir('evento', null);
        Respuesta::compartir('tema', Tema::del(0));
        Respuesta::compartir('persona', null);
        Respuesta::compartir('usuario', null);
        Respuesta::compartir('aviso', null);
        Respuesta::compartir('titulo', '');
        Respuesta::compartir('pantalla', '');
        Respuesta::compartir('rutaActual', self::peticion()->ruta());
    }

    private static function compartirConVistas(): void
    {
        $evento = self::eventoActivo();

        Respuesta::compartir('evento', $evento);
        Respuesta::compartir('tema', Tema::del($evento['id'] ?? 0));
        $persona = Guardia::personaActual();
        $usuario = Guardia::usuarioActual();

        Respuesta::compartir('persona', $persona);
        Respuesta::compartir('usuario', $usuario);
        Respuesta::compartir('aviso', Aviso::tomar());
        Respuesta::compartir('rutaActual', self::peticion()->ruta());

        // ¿La base se quedó atrás del código? El aviso sale en todas las
        // pantallas de quien puede hacer algo al respecto, no solo en el panel.
        //
        // Antes vivía únicamente en /admin, y eso dejaba el peor camino posible:
        // se suben los archivos nuevos, se entra directo a una pantalla que
        // estrena columna, y la pantalla responde 500 sin decir por qué. Pasó
        // con la de acreditar, que es justo la que se usa en la puerta.
        //
        // Solo se calcula para el equipo y para el staff —son dos consultas a
        // information_schema— porque son los únicos que pueden actuar sobre él.
        $puedeVerlo = $usuario !== null || \App\Modelos\Persona::esStaff($persona);
        [$pendiente, $motivo] = $puedeVerlo ? \App\Esquema::revisionPendiente() : [false, ''];
        Respuesta::compartir('esquemaAtrasado', $pendiente);
        Respuesta::compartir('esquemaMotivo', $motivo);
        Respuesta::compartir('esquemaVersion', \App\Esquema::VERSION);
    }

    /**
     * El evento activo, o no se sigue.
     *
     * Casi todas las pantallas cuelgan de un evento y hacen `$evento['id']` sin
     * más. Sin evento eso es un aviso de índice indefinido, y como el manejador
     * de errores convierte los avisos en excepciones, la pantalla acaba en un
     * 500 sin explicación. Pasa de verdad: entre que se instala y que alguien
     * crea el evento, y cada vez que se borra el único que había.
     *
     * La salida depende de quién esté mirando, porque no es la misma situación:
     * el equipo puede crear el evento y se le lleva allí; al visitante solo se
     * le puede decir que todavía no hay nada, que es un 503 legítimo.
     */
    public static function eventoExigido(): array
    {
        $evento = self::eventoActivo();
        if ($evento !== null) {
            return $evento;
        }

        if (str_starts_with(self::peticion()->ruta(), '/admin')) {
            // Solo el administrador puede crear el evento. Mandar allí a un
            // operador es mandarlo a un 403: se le dice qué pasa y a quién
            // pedírselo, que es lo único que puede hacer.
            if (Guardia::puede('administrador')) {
                Respuesta::redirigir('/admin/eventos', 'Crea el primer evento para empezar.', 'warn');
            }
            Respuesta::error(503, 'Todavía no hay un evento abierto',
                'El equipo aún no ha creado el evento. Hasta que un administrador lo publique '
                . 'no hay nada que acreditar ni que consultar.',
                [['texto' => 'Salir', 'url' => Url::a('/admin/entrar'), 'principal' => true]]);
        }

        if (!Instalacion::hayAdministrador()) {
            Respuesta::error(503, 'La instalación quedó a medias',
                'Las tablas están creadas pero no hay ninguna cuenta administradora, así que '
                . 'tampoco hay quién publique un evento. El asistente de instalación volvió a '
                . 'abrirse para terminarla.',
                [['texto' => 'Terminar la instalación', 'url' => Url::a('/instalar'), 'principal' => true]]);
        }

        Respuesta::error(503, 'Todavía no hay un evento abierto',
            'La plataforma está instalada pero el equipo aún no ha publicado ningún evento. '
            . 'Se crea desde el panel, en «Eventos».',
            [['texto' => 'Entrar al panel', 'url' => Url::a('/admin/entrar'), 'principal' => true]]);
    }

    /** El evento marcado como activo; si no hay ninguno, el más reciente. */
    public static function eventoActivo(): ?array
    {
        if (self::$evento !== null) {
            return self::$evento;
        }
        $fila = Bd::fila('SELECT * FROM {evento} WHERE activo = 1 ORDER BY id DESC LIMIT 1')
            ?? Bd::fila('SELECT * FROM {evento} ORDER BY id DESC LIMIT 1');
        return self::$evento = $fila;
    }

    public static function olvidarEvento(): void
    {
        self::$evento = null;
    }
}
