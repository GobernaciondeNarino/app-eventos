<?php
declare(strict_types=1);

/**
 * Funciones cortas de uso constante en las vistas.
 *
 * Son pocas y a propósito: cada una existe porque su versión larga aparecía
 * decenas de veces y el ruido escondía el contenido de la plantilla.
 */

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Url;

/**
 * Escape para HTML. Es la función más usada del proyecto.
 *
 * Nombre de una letra porque va dentro de cada interpolación de cada vista:
 * <?= e($persona['nombre']) ?>. Si escapar costara más de escribir, alguien
 * terminaría por saltárselo «solo esta vez».
 */
function e(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL interna, con la subcarpeta de instalación ya puesta. */
function u(string $ruta = '/', array $consulta = []): string
{
    return Url::a($ruta, $consulta);
}

/** Recurso estático con marca de versión. */
function recurso(string $ruta): string
{
    return Url::recurso($ruta);
}

/** Campo oculto con el testigo contra falsificación de peticiones. */
function testigo(): string
{
    return App\Nucleo\Csrf::campo();
}

/**
 * Declara el JavaScript propio de la pantalla.
 *
 * Se llama desde la vista y la plantilla lo recoge. Tiene que pasar por aquí y
 * no por una variable suelta: la vista y la plantilla se pintan por separado y
 * no comparten ámbito.
 */
function guiones(string ...$archivos): void
{
    App\Nucleo\Respuesta::guiones(...$archivos);
}

/** Número con separador de miles colombiano. */
function numero(int|float|string|null $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

/**
 * 1085234567 → 1.085.234.567
 *
 * Con letras —pasaporte, PPT, cédula de extranjería— se deja como viene. Antes
 * se quitaban las letras para poner los puntos, y un pasaporte «AB123456» se
 * mostraba como «123.456»: otro número, en el carnet y en la puerta.
 */
function documento(?string $n): string
{
    $crudo = mb_strtoupper(trim((string) $n));
    if ($crudo === '') {
        return '—';
    }
    if (preg_match('/[A-Z]/u', $crudo)) {
        return $crudo;
    }
    $limpio = preg_replace('/\D/', '', $crudo) ?? '';
    return $limpio === '' ? '—' : strrev(implode('.', str_split(strrev($limpio), 3)));
}

/**
 * «CC 1.085.234.567», o nada si la persona no dio identificación.
 *
 * Desde la 3.7 el número se puede dejar opcional en el formulario, así que
 * puede no haberlo: entonces no se pinta «CC —» en el carnet.
 */
function identificacion(?string $tipo, ?string $documento): string
{
    return trim((string) $documento) === '' ? '' : trim((string) $tipo . ' ' . documento($documento));
}

/** 2026-09-15 → 15 sep 2026 */
function fecha(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $marca = strtotime($iso);
    if ($marca === false) {
        return '—';
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $marca) . ' ' . $meses[(int) date('n', $marca) - 1] . ' ' . date('Y', $marca);
}

function hora(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $marca = strtotime($iso);
    return $marca === false ? '' : date('g:i a', $marca);
}

/** «María Fernanda Zambrano» → «MZ» */
function iniciales(?string $nombre): string
{
    $partes = preg_split('/\s+/', trim((string) $nombre)) ?: [];
    $partes = array_values(array_filter($partes));
    if (!$partes) {
        return '?';
    }
    $primera = mb_substr($partes[0], 0, 1);
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';
    return mb_strtoupper($primera . $ultima);
}

/**
 * Nombre legible de un perfil de asistencia.
 *
 * Desde la 3.8 los nombres los configura cada evento (Configuración →
 * Registro), así que se buscan en el formulario de ese evento: por omisión, el
 * activo, que es el que ven todas las pantallas del equipo. El carnet pasa el
 * de su dueño.
 */
function etiquetaRol(string $rol, ?int $eventoId = null): string
{
    try {
        $eventoId ??= (int) (\App\Nucleo\App::eventoActivo()['id'] ?? 0);
        return \App\Modelos\Formulario::delEvento($eventoId)->nombrePerfil($rol);
    } catch (\Throwable) {
        // Sin base —una prueba, el instalador—: los nombres de fábrica.
        return \App\Modelos\Persona::PERFILES_DE_ADMIN[$rol] ?? \App\Modelos\Persona::PERFILES_DE_FABRICA[$rol] ?? $rol;
    }
}

/**
 * Todos los perfiles de un evento, para el equipo: clave => nombre. Los de la
 * lista, encendidos o no, y los que solo pone un administrador.
 *
 * @return array<string, string>
 */
function perfilesDelEvento(?int $eventoId = null): array
{
    try {
        $eventoId ??= (int) (\App\Nucleo\App::eventoActivo()['id'] ?? 0);
        return \App\Modelos\Formulario::delEvento($eventoId)->todosLosPerfiles();
    } catch (\Throwable) {
        return \App\Modelos\Persona::PERFILES_DE_FABRICA + \App\Modelos\Persona::PERFILES_DE_ADMIN;
    }
}

/**
 * La talla del rótulo del perfil en el carnet.
 *
 * Va grande para leerlo a un metro en la fila, y a ese tamaño caben unas doce
 * letras por línea: «PARTICIPANTE». Los nombres más largos —«Rueda de
 * Negocios», los que agregue el evento— se achican, porque el carnet tiene el
 * alto fijo y dos o tres líneas grandes dejaban la entidad fuera.
 */
function tallaRol(string $nombre): string
{
    $largo = mb_strlen($nombre);
    return match (true) {
        $largo <= 12 => '',
        $largo <= 18 => ' carnet__rol--medio',
        default      => ' carnet__rol--largo',
    };
}

/** Clase de la etiqueta de estado según el rol. */
function claseRol(string $rol): string
{
    return match ($rol) {
        'expositor'   => 'tag--warn',
        'organizador' => 'tag--ok',
        'staff'       => 'tag--ok',
        'prensa'      => 'tag--mute',
        default       => '',
    };
}

/** Marca «is-active» para el enlace de navegación de la pantalla actual. */
function activo(string $pantalla, string $actual): string
{
    return $pantalla === $actual ? ' is-active' : '';
}

/**
 * El User-Agent, reducido a algo que una persona reconozca.
 *
 * Se usa en la lista de dispositivos recordados, donde lo único que importa es
 * que su dueño pueda decir «ese es mi celular» o «ese no es mío». Enseñar la
 * cadena completa —que ocupa dos renglones y menciona cinco navegadores que no
 * son— no ayudaría a decidir nada.
 *
 * El orden de las comprobaciones no es casual: casi todos los navegadores
 * mienten diciendo también que son Safari y Chrome, así que los más específicos
 * van primero.
 */
function navegadorLegible(?string $agente): string
{
    $agente = (string) $agente;
    if (trim($agente) === '') {
        return 'Dispositivo sin identificar';
    }

    $navegador = 'Navegador';
    foreach ([
        'Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox', 'CriOS' => 'Chrome', 'FxiOS' => 'Firefox',
        'Chrome' => 'Chrome', 'Safari' => 'Safari',
    ] as $aguja => $nombre) {
        if (str_contains($agente, $aguja)) {
            $navegador = $nombre;
            break;
        }
    }

    $sistema = 'este dispositivo';
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Macintosh' => 'Mac',
        'Linux' => 'Linux',
    ] as $aguja => $nombre) {
        if (str_contains($agente, $aguja)) {
            $sistema = $nombre;
            break;
        }
    }

    return $navegador . ' en ' . $sistema;
}
