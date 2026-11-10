<?php
/**
 * Router para el servidor de desarrollo de PHP.
 *
 * Emula lo que hace Apache con el .htaccess de la raíz: servir los archivos que
 * existen de verdad, bloquear las carpetas internas y mandar todo lo demás a
 * index.php. Sin esto, el servidor de PHP sirve cualquier archivo que encuentre
 * y las comprobaciones de superficie expuesta pasarían sin comprobar nada.
 *
 * Vive aquí, en el repositorio, y no en un archivo suelto de /tmp: las reglas
 * tienen que poder revisarse junto al .htaccess que imitan, y no perderse ni
 * quedarse desactualizadas.
 *
 * Uso:
 *   # colgando de una subcarpeta, como en producción
 *   BASE=/cumbreAI php -S 127.0.0.1:8900 -t . pruebas/servidor.php
 *
 *   # o en la raíz del dominio
 *   php -S 127.0.0.1:8900 -t . pruebas/servidor.php
 */
declare(strict_types=1);

$RAIZ = dirname(__DIR__);
$BASE = rtrim((string) (getenv('BASE') ?: ''), '/');

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($BASE !== '' && !str_starts_with($uri, $BASE)) {
    http_response_code(404);
    echo 'Fuera de la subcarpeta ' . htmlspecialchars($BASE, ENT_QUOTES);
    return true;
}
$relativa = $BASE === '' ? $uri : substr($uri, strlen($BASE));
$relativa = '/' . ltrim($relativa, '/');

/* -------------------------------------------------------------------------
   Bloqueos del .htaccess
   ------------------------------------------------------------------------- */

// RedirectMatch 404 (?i)^/?(.*/)?(app|config|almacen|docs|herramientas|pruebas|\.git|\.github|\.claude)/
if (preg_match('#(?i)^/?(.*/)?(app|config|almacen|docs|herramientas|pruebas|\.git|\.github|\.claude)/#', $relativa)) {
    http_response_code(404);
    return true;
}

// <FilesMatch> con los archivos sueltos que tampoco deben verse
if (preg_match('#(?i)(^\.|\.(sql|log|md|json|lock|ini|bak|old|swp|dist|yml|yaml)$|~$|^(composer|package)\.)#', basename($relativa))) {
    http_response_code(403);
    return true;
}

/* -------------------------------------------------------------------------
   Archivos que existen: se sirven aquí, como haría Apache
   -------------------------------------------------------------------------
   No vale devolver false y dejárselo al servidor de PHP: con la aplicación
   colgando de una subcarpeta, él buscaría «docroot + /cumbreAI/assets/…», que
   no existe, y contestaría 404 a todos los recursos.
   ------------------------------------------------------------------------- */
$archivo = $RAIZ . $relativa;
if ($relativa !== '/' && is_file($archivo) && !str_ends_with($archivo, '.php')) {
    $tipos = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'txt' => 'text/plain',
        'webmanifest' => 'application/manifest+json',
    ];
    $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($archivo));
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    return true;
}

/* -------------------------------------------------------------------------
   Todo lo demás, al punto de entrada
   ------------------------------------------------------------------------- */
$_SERVER['SCRIPT_NAME']     = ($BASE === '' ? '' : $BASE) . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $RAIZ . '/index.php';
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];

require $RAIZ . '/index.php';
return true;
