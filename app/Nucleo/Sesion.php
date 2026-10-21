<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Sesiones propias, guardadas en la base de datos.
 *
 * No se usa la sesión de PHP por tres razones concretas:
 *  1. En un alojamiento compartido los archivos de sesión suelen quedar en un
 *     directorio común, legible por otras cuentas del mismo servidor.
 *  2. Guardándolas en la base se puede cerrar una sesión a distancia, listar
 *     las abiertas y caducarlas de verdad.
 *  3. Hacen falta dos duraciones muy distintas: la del organizador es corta y
 *     la del asistente dura lo que el evento, para que no tenga que
 *     identificarse cada mañana en la puerta.
 *
 * El identificador que viaja en la cookie no es el que se guarda: en la tabla
 * queda su SHA-256. Así, quien consiga leer la tabla no obtiene cookies
 * utilizables.
 */
final class Sesion
{
    private const COOKIE_ADMIN = 'evtic_admin';
    private const COOKIE_ASISTENTE = 'evtic_asis';

    /** El organizador maneja datos personales: sesión corta. */
    private const VIDA_ADMIN = 43200;        // 12 h absolutas
    private const INACTIVIDAD_ADMIN = 7200;  // 2 h sin actividad

    /** El asistente solo ve lo suyo: sesión larga, que dura el evento. */
    private const VIDA_ASISTENTE = 2592000;  // 30 días

    private static array $cache = [];

    /* =====================================================================
       Cookies
       ===================================================================== */

    private static function ponerCookie(string $nombre, string $valor, int $expira): void
    {
        $peticion = App::peticion();
        $ruta = $peticion->base() === '' ? '/' : $peticion->base() . '/';

        setcookie($nombre, $valor, [
            'expires'  => $expira,
            'path'     => $ruta,
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            // Lax y no Strict: el asistente llega a la aplicación desde el
            // lector de QR de su teléfono, que cuenta como navegación externa.
            // Con Strict la cookie no viajaría y pediría login otra vez.
            'samesite' => 'Lax',
        ]);

        // El valor manda, y no la fecha de caducidad.
        //
        // Antes esto era «$expira < time() ? '' : $valor», dando por hecho que
        // una fecha pasada significaba borrar. Pero la sesión del organizador
        // usa expires=0 —una cookie que muere al cerrar el navegador— y cero es
        // menor que ahora, así que $_COOKIE se vaciaba justo después de abrir o
        // rotar la sesión. En el resto de esa misma petición la sesión no
        // existía, y por eso guardarDatos() no llegaba a apagar pendiente_2fa:
        // el administrador entraba con su código correcto y volvía a la
        // pantalla de verificación, una y otra vez, sin poder pasar nunca.
        $_COOKIE[$nombre] = $valor;
    }

    private static function leerCookie(string $nombre): string
    {
        $v = $_COOKIE[$nombre] ?? '';
        return (is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v)) ? $v : '';
    }

    /* =====================================================================
       Ciclo de vida
       ===================================================================== */

    /**
     * Abre sesión. $tipo es 'admin' o 'asistente'.
     * Devuelve el identificador en claro (el que va en la cookie).
     */
    public static function abrir(string $tipo, int $sujetoId, array $datos = []): string
    {
        $enClaro = bin2hex(random_bytes(32));
        $guardado = hash('sha256', $enClaro);
        $vida = $tipo === 'admin' ? self::VIDA_ADMIN : self::VIDA_ASISTENTE;
        $peticion = App::peticion();

        Bd::insertar('sesion', [
            'id'           => $guardado,
            'tipo'         => $tipo,
            'sujeto_id'    => $sujetoId,
            'datos'        => json_encode($datos, JSON_UNESCAPED_UNICODE),
            'ip'           => @inet_pton($peticion->ip()) ?: null,
            'agente'       => $peticion->agente(),
            'ultima_senal' => date('Y-m-d H:i:s'),
            'expira_en'    => date('Y-m-d H:i:s', time() + $vida),
        ]);

        self::ponerCookie(
            $tipo === 'admin' ? self::COOKIE_ADMIN : self::COOKIE_ASISTENTE,
            $enClaro,
            $tipo === 'admin' ? 0 : time() + $vida   // la del admin muere al cerrar el navegador
        );

        self::$cache[$tipo] = null;

        // Este teléfono queda recordado. No para el equipo organizador: su
        // sesión es corta a propósito y muere al cerrar el navegador.
        //
        // La marca no se pone cuando la sesión viene justamente de esa marca:
        // si no, cada visita crearía una fila nueva por el mismo dispositivo.
        if ($tipo === 'asistente' && ($datos['via'] ?? '') !== 'dispositivo') {
            Dispositivo::recordar($sujetoId);
        }

        return $enClaro;
    }

    /** Devuelve la fila de sesión vigente, o null. */
    public static function actual(string $tipo): ?array
    {
        if (array_key_exists($tipo, self::$cache) && self::$cache[$tipo] !== null) {
            return self::$cache[$tipo];
        }

        $enClaro = self::leerCookie($tipo === 'admin' ? self::COOKIE_ADMIN : self::COOKIE_ASISTENTE);
        if ($enClaro === '') {
            return null;
        }

        // Sin base de datos no hay sesión que buscar, porque las sesiones viven
        // justamente ahí.
        //
        // No es un caso teórico. El navegador de quien instaló guarda la cookie
        // de los intentos anteriores; si después se hace una instalación limpia
        // —se borra config/config.php y se vacía la base—, esa cookie sigue
        // viajando. El asistente pintaba su testigo CSRF, el testigo preguntaba
        // por la sesión, la sesión preguntaba por la base, y la base no existía:
        // el asistente entero respondía 500 y solo en ese navegador, lo que lo
        // hacía parecer un fallo del servidor.
        if ((string) Config::obtener('bd_nombre', '') === '') {
            return null;
        }

        // Una sesión que no se puede leer es una sesión que no existe.
        //
        // Se atrapa a propósito y se anota. Si la tabla no está —una instalación
        // a medias, una migración a mitad— lo correcto es tratar al visitante
        // como anónimo y dejar que las pantallas públicas y el asistente sigan
        // funcionando. Dejar que la excepción suba convierte cada página del
        // sitio en un 500, incluida la que serviría para arreglarlo.
        try {
            $fila = Bd::fila(
                'SELECT * FROM {sesion} WHERE id = ? AND tipo = ? AND expira_en > NOW()',
                [hash('sha256', $enClaro), $tipo]
            );
        } catch (\Throwable $e) {
            Registro::error('No se pudo consultar la sesión; se sigue como visitante anónimo', [
                'tipo'    => $tipo,
                'detalle' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$fila) {
            return null;
        }

        // Caducidad por inactividad, solo para el organizador.
        if ($tipo === 'admin') {
            $inactiva = time() - strtotime((string) $fila['ultima_senal']);
            if ($inactiva > self::INACTIVIDAD_ADMIN) {
                self::cerrarPorId((string) $fila['id']);
                return null;
            }
        }

        // La señal se refresca como mucho una vez por minuto, para no escribir
        // en la base en cada carga de página.
        if (time() - strtotime((string) $fila['ultima_senal']) > 60) {
            Bd::ejecutar('UPDATE {sesion} SET ultima_senal = NOW() WHERE id = ?', [$fila['id']]);
        }

        $fila['datos'] = json_decode((string) $fila['datos'], true) ?: [];
        return self::$cache[$tipo] = $fila;
    }

    public static function datos(string $tipo): array
    {
        return self::actual($tipo)['datos'] ?? [];
    }

    public static function guardarDatos(string $tipo, array $datos): void
    {
        $sesion = self::actual($tipo);
        if (!$sesion) {
            return;
        }
        Bd::ejecutar('UPDATE {sesion} SET datos = ? WHERE id = ?', [
            json_encode($datos, JSON_UNESCAPED_UNICODE),
            $sesion['id'],
        ]);
        self::$cache[$tipo]['datos'] = $datos;
    }

    /**
     * Cambia el identificador conservando el contenido.
     *
     * Se llama justo después de autenticar y después de superar el segundo
     * factor. Sin esto, quien haya podido fijar una cookie antes del login se
     * queda con una sesión ya autenticada.
     */
    public static function rotar(string $tipo): void
    {
        $sesion = self::actual($tipo);
        if (!$sesion) {
            return;
        }
        $nuevoClaro = bin2hex(random_bytes(32));
        Bd::ejecutar('UPDATE {sesion} SET id = ? WHERE id = ?', [
            hash('sha256', $nuevoClaro),
            $sesion['id'],
        ]);
        $vida = $tipo === 'admin' ? self::VIDA_ADMIN : self::VIDA_ASISTENTE;
        self::ponerCookie(
            $tipo === 'admin' ? self::COOKIE_ADMIN : self::COOKIE_ASISTENTE,
            $nuevoClaro,
            $tipo === 'admin' ? 0 : time() + $vida
        );
        self::$cache[$tipo] = null;
    }

    public static function cerrar(string $tipo): void
    {
        $sesion = self::actual($tipo);
        if ($sesion) {
            self::cerrarPorId((string) $sesion['id']);
        }
        self::ponerCookie(
            $tipo === 'admin' ? self::COOKIE_ADMIN : self::COOKIE_ASISTENTE,
            '',
            time() - 3600
        );

        // Salir tiene que significar salir. Si la marca del dispositivo
        // sobreviviera al cierre de sesión, la siguiente página la volvería a
        // abrir sola y el botón «salir» no serviría para nada.
        if ($tipo === 'asistente') {
            Dispositivo::olvidar();
        }

        self::$cache[$tipo] = null;
    }

    private static function cerrarPorId(string $id): void
    {
        Bd::ejecutar('DELETE FROM {sesion} WHERE id = ?', [$id]);
    }

    /** Cierra todas las sesiones de una persona. Para cuando cambia su clave. */
    public static function cerrarTodasDe(string $tipo, int $sujetoId): void
    {
        Bd::ejecutar('DELETE FROM {sesion} WHERE tipo = ? AND sujeto_id = ?', [$tipo, $sujetoId]);
    }

    /** Limpieza de expiradas. Se llama de vez en cuando, no en cada petición. */
    public static function limpiar(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        Bd::ejecutar('DELETE FROM {sesion} WHERE expira_en < NOW()');
        Bd::ejecutar('DELETE FROM {codigo_acceso} WHERE expira_en < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        Bd::ejecutar('DELETE FROM {intento} WHERE creado_en < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        Dispositivo::podar();
        \App\Modelos\Recuperacion::podar();

        // Los registros de error viejos se van con lo demás. Registro::podar()
        // existía desde el principio y no lo llamaba nadie: en una instalación
        // con el correo caído, esa carpeta crece con una línea por cada código
        // de acceso pedido y no la vacía nunca nadie.
        Registro::podar(30);
    }
}
