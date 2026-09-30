<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Protección contra peticiones falsificadas.
 *
 * Cada formulario que cambia algo lleva un testigo. Sin esto, bastaría con que
 * un organizador con sesión abierta visitara una página cualquiera para que esa
 * página, desde el navegador de la persona, sellara ingresos o aprobara
 * propuestas sin que se entere.
 *
 * El testigo se guarda en una cookie propia y se compara con el campo del
 * formulario: el patrón de doble envío. Se eligió sobre guardarlo en la sesión
 * porque también hace falta en pantallas donde todavía no hay sesión —el
 * preregistro y el propio formulario de acceso— que son justo las que más
 * conviene proteger.
 */
final class Csrf
{
    private const COOKIE = 'evtic_csrf';
    public const CAMPO = '_testigo';

    private static string $token = '';

    public static function token(): string
    {
        if (self::$token !== '') {
            return self::$token;
        }

        // Con sesión abierta, el testigo se deriva de ella y no hace falta
        // cookie: es lo que impide que un subdominio hermano fije el valor.
        $atado = self::atadoALaSesion();
        if ($atado !== '') {
            return self::$token = $atado;
        }

        $actual = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($actual) && preg_match('/^[a-f0-9]{64}$/', $actual)) {
            return self::$token = $actual;
        }

        $nuevo = bin2hex(random_bytes(32));
        $peticion = App::peticion();
        setcookie(self::COOKIE, $nuevo, [
            'expires'  => 0,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $nuevo;
        return self::$token = $nuevo;
    }

    /** Campo oculto listo para poner dentro de un <form>. */
    public static function campo(): string
    {
        return '<input type="hidden" name="' . self::CAMPO . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /**
     * Testigo atado a la sesión, cuando hay sesión.
     *
     * La cookie de doble envío sola tiene un punto débil que en un dominio como
     * narino.gov.co no es teórico: cualquier subdominio puede escribir una
     * cookie para el dominio padre. Quien controle un sitio hermano puede fijar
     * el valor del testigo en el navegador de la víctima y después enviar un
     * formulario con ese mismo valor: los dos coinciden y la comprobación pasa.
     *
     * Atándolo a la sesión eso deja de servir: el testigo que vale es el que se
     * deriva del identificador de sesión de la víctima, y ese el atacante no lo
     * conoce. Sin sesión no hay nada que atar —ni nada que abusar en nombre de
     * nadie—, así que se mantiene la comparación con la cookie.
     */
    private static function atadoALaSesion(): string
    {
        // Mientras se instala no hay a qué atarse, y preguntarlo cuesta caro.
        //
        // El asistente no tiene acceso ni sesiones, y la tabla que las guarda
        // puede no existir todavía. Pero el formulario sí lleva testigo, y
        // pintarlo llamaba a Sesion::actual(), que consulta la base. Con una
        // cookie de sesión vieja en el navegador —de un intento anterior de
        // instalación, que es lo normal— la consulta salía hacia una base sin
        // configurar en el paso 1, o sin tabla «sesion» en el paso 3, y el
        // asistente respondía 500. Solo en ese navegador, lo que lo hacía
        // parecer un fallo del servidor.
        if (!Config::instalado()) {
            return '';
        }

        foreach (['admin', 'asistente'] as $tipo) {
            $sesion = Sesion::actual($tipo);
            if ($sesion) {
                return hash_hmac(
                    'sha256',
                    'csrf|' . $tipo . '|' . $sesion['id'],
                    (string) Config::obtener('llave_cifrado', 'sin-llave')
                );
            }
        }
        return '';
    }

    public static function valido(Peticion $peticion): bool
    {
        $enviado = $peticion->campo(self::CAMPO);
        if ($enviado === '') {
            return false;
        }

        $atado = self::atadoALaSesion();
        if ($atado !== '') {
            return hash_equals($atado, $enviado);
        }

        $esperado = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($esperado) || $esperado === '') {
            return false;
        }
        return hash_equals($esperado, $enviado);
    }

    /**
     * Comprueba y corta si no cuadra.
     *
     * Se responde 419 —«la sesión expiró»— en vez de 403 porque la causa más
     * común no es un ataque sino una pestaña que llevaba horas abierta.
     */
    public static function exigir(Peticion $peticion): void
    {
        if (!$peticion->esPost() || self::valido($peticion)) {
            return;
        }

        if (self::envioDescartado()) {
            Respuesta::error(413, 'El envío pesaba demasiado',
                'El servidor descartó el formulario completo porque superaba su límite de '
                . 'subida (post_max_size, hoy en ' . ini_get('post_max_size') . '). Si '
                . 'adjuntaste un archivo grande, ese es el motivo: prueba con uno más liviano, '
                . 'o pide al área de sistemas que suba el límite.');
        }

        Bitacora::registrar('csrf_rechazado', 'seguridad', null, [
            'ruta' => $peticion->ruta(),
        ]);
        Respuesta::error(419, 'La página estuvo demasiado tiempo abierta',
            'Por seguridad se descartó el envío. Vuelve a cargar la página e inténtalo de nuevo.');
    }

    /**
     * ¿PHP tiró el envío entero por pasarse de post_max_size?
     *
     * Cuando eso pasa no queda nada: $_POST y $_FILES llegan vacíos y el testigo
     * con ellos. Sin esta comprobación la persona ve «la sesión expiró» y se
     * pone a recargar la página, que es lo único que no lo va a arreglar nunca.
     *
     * Hay cuerpo si el navegador anunció uno en Content-Length. Ningún camino de
     * la plataforma manda un POST con cuerpo que PHP no sepa leer —no se acepta
     * JSON en ninguna dirección—, así que un cuerpo anunciado y dos arreglos
     * vacíos solo pueden ser esto.
     */
    private static function envioDescartado(): bool
    {
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === [];
    }
}
