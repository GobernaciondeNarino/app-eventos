<?php
/**
 * Plataforma de Eventos TIC — punto de entrada único.
 *
 * Secretaría TIC, Innovación y Gobierno Abierto · Gobernación de Nariño
 *
 * Todo el tráfico pasa por aquí. Esta carpeta es la que se sube al servidor y
 * funciona igual colgada de la raíz de un dominio que de una subcarpeta
 * (https://tic.narino.gov.co/cumbreAI/): la ruta base se detecta sola.
 *
 * Si la aplicación todavía no está instalada, cualquier dirección lleva al
 * asistente de instalación.
 */

declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', __DIR__);
define('APP_VERSION', '3.3.0');
define('ESQUEMA_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>PHP demasiado antiguo</h1><p>La plataforma necesita PHP 8.1 o superior. '
        . 'Este servidor tiene ' . htmlspecialchars(PHP_VERSION) . '. '
        . 'En Plesk se cambia en «Configuración de PHP» del dominio.</p>');
}

/**
 * Carga de clases.
 *
 * Sin Composer a propósito: la plataforma debe poder subirse por FTP a un
 * alojamiento compartido sin ejecutar nada previo. El espacio de nombres se
 * traduce directo a carpetas y se comprueba que la ruta resultante no se salga
 * de app/, por si algún día alguien construye un nombre de clase con datos de
 * fuera.
 */
spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $relativa = str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (str_contains($relativa, '..')) {
        return;
    }
    $archivo = RAIZ . '/app/' . $relativa;
    if (is_file($archivo)) {
        require $archivo;
    }
});

require RAIZ . '/app/ayudas.php';

App\Nucleo\App::arrancar();
