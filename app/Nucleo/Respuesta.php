<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Salida al navegador: vistas, redirecciones, JSON, archivos y errores.
 *
 * Las vistas son PHP plano. No hay motor de plantillas porque no hace falta:
 * con la función e() escapando por defecto y las variables pasadas de forma
 * explícita, se consigue lo mismo sin una capa más que mantener en cinco años.
 */
final class Respuesta
{
    private static array $compartidas = [];

    /** Variables disponibles en todas las vistas (identidad del evento, sesión…). */
    public static function compartir(string $clave, mixed $valor): void
    {
        self::$compartidas[$clave] = $valor;
    }

    public static function compartidas(): array
    {
        return self::$compartidas;
    }

    /** Pinta una vista dentro de la plantilla general. */
    public static function vista(string $vista, array $datos = [], int $codigo = 200): never
    {
        http_response_code($codigo);
        echo self::render($vista, $datos);
        exit;
    }

    /** Pinta una vista sin plantilla: para fragmentos y correos. */
    public static function parcial(string $vista, array $datos = []): string
    {
        return self::capturar($vista, $datos);
    }

    /** @var array<int, string> Guiones propios de la pantalla que se está pintando. */
    private static array $guiones = [];

    /**
     * Declara el JavaScript propio de una pantalla.
     *
     * Lo llaman las vistas. Antes cada una hacía «$guiones = ['escaner.js']» y
     * la plantilla leía esa variable, pero la vista y la plantilla se pintan en
     * llamadas distintas y con su propio ámbito, así que la plantilla nunca la
     * veía: ni un solo guion de pantalla llegaba al navegador. El escáner de la
     * puerta, el selector de municipios del preregistro y la vista previa de la
     * identidad estaban en el HTML y no se cargaban.
     */
    public static function guiones(string ...$archivos): void
    {
        foreach ($archivos as $archivo) {
            if (!in_array($archivo, self::$guiones, true)) {
                self::$guiones[] = $archivo;
            }
        }
    }

    /** @return array<int, string> */
    public static function guionesDeclarados(): array
    {
        return self::$guiones;
    }

    private static function render(string $vista, array $datos): string
    {
        // La vista primero: es ahí donde se declaran sus guiones, y la
        // plantilla tiene que pintarse después para poder incluirlos.
        $contenido = self::capturar($vista, $datos);
        $datos['contenido'] = $contenido;
        $datos['guiones'] = self::$guiones;
        return self::capturar('plantilla', $datos);
    }

    private static function capturar(string $vista, array $datos): string
    {
        $archivo = RAIZ . '/app/Vistas/' . str_replace(['..', '\\'], '', $vista) . '.php';
        if (!is_file($archivo)) {
            throw new \RuntimeException("No existe la vista «{$vista}».");
        }

        // extract() con EXTR_SKIP: una variable de la vista nunca pisa a una
        // compartida, así que $sesion o $tema no se pueden falsear desde un
        // controlador por descuido.
        $variables = $datos + self::$compartidas;
        extract($variables, EXTR_SKIP);

        ob_start();
        try {
            require $archivo;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function redirigir(string $ruta, ?string $aviso = null, string $tipo = 'ok'): never
    {
        if ($aviso !== null) {
            Aviso::poner($aviso, $tipo);
        }
        header('Location: ' . Url::a($ruta), true, 303);
        exit;
    }

    public static function redirigirAbsoluto(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function json(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Descarga generada al vuelo (CSV, vCard). */
    public static function descarga(string $nombre, string $contenido, string $tipo = 'text/plain'): never
    {
        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'descarga';
        header('Content-Type: ' . $tipo . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($contenido));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        echo $contenido;
        exit;
    }

    /**
     * Sirve un archivo subido.
     *
     * Los logos, las fotos y los adjuntos de los expositores nunca se sirven
     * directamente desde el disco: pasan por aquí. Así el tipo lo decide el
     * servidor y no la extensión del archivo, y una imagen SVG con un script
     * dentro no se ejecuta en el origen del sitio (ver docs/SEGURIDAD.md).
     *
     * $nombre es con el que se baja. Por omisión, el que tiene en el disco
     * —que lo puso el servidor y es ilegible a propósito: «pr7-hv-a1b2.pdf»—.
     * Se le quita todo lo que no sea letra, número, punto o guion: acaba dentro
     * de una cabecera HTTP, y una comilla o un salto de línea ahí dejan de ser
     * texto y pasan a ser estructura.
     */
    public static function archivo(string $ruta, string $tipo, bool $enLinea = true, string $nombre = ''): never
    {
        if (!is_file($ruta)) {
            self::error(404, 'Archivo no encontrado', 'El recurso solicitado ya no está disponible.');
        }

        $nombre = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $nombre !== '' ? $nombre : basename($ruta));
        if ($nombre === '') {
            $nombre = 'archivo';
        }

        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . (string) filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment')
            . '; filename="' . $nombre . '"');
        readfile($ruta);
        exit;
    }

    /**
     * Página de error.
     *
     * $acciones permite ofrecer una salida concreta en vez del «Ir al inicio»
     * de siempre, que en un 503 de la propia portada no lleva a ninguna parte.
     * Cada acción es ['texto' => …, 'url' => …, 'principal' => bool].
     *
     * $detalle son líneas técnicas —clase de la excepción, archivo, línea,
     * traza— y solo se llenan mientras la instalación no ha terminado. Ver
     * App::detalleDelFallo() para por qué eso no es una fuga.
     *
     * @param array<int, array{texto: string, url: string, principal?: bool}> $acciones
     * @param array<int, string> $detalle
     */
    public static function error(int $codigo, string $titulo, string $mensaje = '', array $acciones = [], array $detalle = []): never
    {
        http_response_code($codigo);
        echo self::render('error', [
            'codigo'   => $codigo,
            'titulo'   => $titulo,
            'mensaje'  => $mensaje,
            'acciones' => $acciones,
            'detalle'  => $detalle,
        ]);
        exit;
    }
}
