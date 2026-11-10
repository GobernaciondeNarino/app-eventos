<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Construcción de URLs.
 *
 * Ninguna plantilla escribe rutas a mano. Todas pasan por aquí, y aquí se
 * antepone la subcarpeta donde esté instalada la aplicación. Es lo que permite
 * mover la plataforma de https://eventos.narino.gov.co/ a
 * https://tic.narino.gov.co/cumbreAI/ sin cambiar una línea.
 */
final class Url
{
    /**
     * Las rutas que no son de un evento y nunca llevan el prefijo /e/<slug>:
     * el equipo, los recursos, los archivos, la API, el instalador, la
     * verificación de certificados, y las que llevan un token que ya dice de
     * qué evento son —el QR del día, el carnet, el acceso por QR, el enlace de
     * una contraseña nueva, el de los expositores—. Que esas no cambien
     * importa: van impresas en los QR y en los correos.
     */
    private const SIN_PREFIJO = '#^/(admin|assets|medios|api|instalar|e)(/|$)|^/v(/|$)|^/[dc]/|^/entrar/qr/'
        . '|^/entrar/recuperar/[a-f0-9]|^/registro/expositores/[a-f0-9]#';

    /**
     * Ruta interna de la aplicación: Url::a('/carnet') → /cumbreAI/carnet.
     *
     * Desde la 1.17.0, con el prefijo del evento de esta petición si no es el
     * principal: en las páginas del Foro, Url::a('/registro') →
     * /cumbreAI/e/foro-tic-2027/registro. Las pantallas no tienen que saberlo.
     */
    public static function a(string $ruta = '/', array $consulta = []): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        return self::armar(self::llevaPrefijo($ruta) ? App::prefijoEvento() : '', $ruta, $consulta);
    }

    /** La ruta de un evento en particular, sea o no el de esta petición. */
    public static function deEvento(array $evento, string $ruta = '/', array $consulta = []): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        return self::armar(self::llevaPrefijo($ruta) ? self::prefijoDe($evento) : '', $ruta, $consulta);
    }

    /** La dirección completa de una página de un evento: para copiarla y compartirla. */
    public static function absolutaDeEvento(array $evento, string $ruta = '/'): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        $interna = (self::llevaPrefijo($ruta) ? self::prefijoDe($evento) : '') . $ruta;
        $configurada = trim((string) Config::obtener('url_base', ''));
        if ($configurada !== '') {
            return rtrim($configurada, '/') . '/' . ltrim($interna, '/');
        }
        return App::peticion()->origen() . self::armar(self::llevaPrefijo($ruta) ? self::prefijoDe($evento) : '', $ruta, []);
    }

    /** '/e/<slug>' para un evento que no es el principal; '' para el principal. */
    public static function prefijoDe(array $evento): string
    {
        $principal = App::principal();
        if ($principal !== null && (int) $principal['id'] === (int) ($evento['id'] ?? 0)) {
            return '';
        }
        $slug = \App\Modelos\Evento::slugDe($evento);
        return $slug === '' ? '' : '/e/' . $slug;
    }

    private static function llevaPrefijo(string $ruta): bool
    {
        return !preg_match(self::SIN_PREFIJO, $ruta);
    }

    private static function armar(string $prefijo, string $ruta, array $consulta): string
    {
        $base = App::peticion()->base();
        if ($ruta === '/') {
            // La portada de un evento con prefijo termina en barra: /e/foro/.
            $ruta = $prefijo === '' ? '' : '/';
        }
        $url = $base . $prefijo . $ruta;
        if ($url === '') {
            $url = '/';
        }
        if ($consulta) {
            $url .= '?' . http_build_query($consulta);
        }
        return $url;
    }

    /**
     * URL absoluta. Solo para lo que sale del navegador: los correos y el
     * contenido de los códigos QR, que se leen desde una cámara y no tienen
     * ninguna página de referencia.
     */
    public static function absoluta(string $ruta = '/', array $consulta = []): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        $configurada = trim((string) Config::obtener('url_base', ''));
        if ($configurada !== '') {
            $interna = (self::llevaPrefijo($ruta) ? App::prefijoEvento() : '') . $ruta;
            $url = rtrim($configurada, '/') . '/' . ltrim($interna, '/');
            return $consulta ? $url . '?' . http_build_query($consulta) : $url;
        }
        return App::peticion()->origen() . self::a($ruta, $consulta);
    }

    /**
     * Recurso estático con marca de versión.
     *
     * El sufijo ?v= viene de la fecha del archivo: al publicar un cambio de CSS
     * el navegador lo pide de nuevo, sin obligar a nadie a vaciar la caché.
     */
    public static function recurso(string $ruta): string
    {
        $ruta = ltrim($ruta, '/');
        $absoluta = RAIZ . '/' . $ruta;
        $version = is_file($absoluta) ? substr((string) filemtime($absoluta), -6) : APP_VERSION;
        return self::a('/' . $ruta) . '?v=' . $version;
    }

    /**
     * Comprueba que un destino de redirección sea interno.
     *
     * Después de identificarse se vuelve a donde la persona quería ir, y ese
     * destino llega por la cadena de consulta. Sin esta comprobación, un enlace
     * como /entrar?destino=https://sitio-falso/ convertiría la plataforma en un
     * trampolín creíble para robar credenciales.
     */
    public static function destinoSeguro(string $destino, string $porDefecto = '/'): string
    {
        $destino = trim($destino);

        if ($destino === '' || $destino[0] !== '/') {
            return $porDefecto;
        }
        // //otro-sitio.com y /\otro-sitio.com son URLs protocolo-relativas.
        if (str_starts_with($destino, '//') || str_starts_with($destino, '/\\')) {
            return $porDefecto;
        }
        if (str_contains($destino, "\n") || str_contains($destino, "\r")) {
            return $porDefecto;
        }

        // Llega con la subcarpeta incluida; se guarda la ruta interna.
        $base = App::peticion()->base();
        if ($base !== '' && str_starts_with($destino, $base . '/')) {
            $destino = substr($destino, strlen($base));
        }
        return $destino === '' ? $porDefecto : $destino;
    }
}
