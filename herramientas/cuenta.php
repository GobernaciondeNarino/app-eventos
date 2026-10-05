<?php
/**
 * Cuentas del equipo organizador, desde la consola.
 *
 * Existe para el día en que nadie puede entrar al panel: la instalación se
 * interrumpió, se perdió la contraseña, o el segundo factor quedó vinculado a
 * un teléfono que ya no está. Todo eso se arregla aquí sin tocar SQL a mano y
 * sin abrir ninguna puerta nueva en el navegador.
 *
 * En Plesk, si no hay acceso SSH: Sitios web y dominios → Tareas programadas →
 * «Ejecutar un script PHP», con la ruta cumbreAI/herramientas/cuenta.php y los
 * argumentos en el campo correspondiente. Ejecutar una vez y borrar la tarea.
 *
 * Uso:
 *   php herramientas/cuenta.php estado
 *   php herramientas/cuenta.php listar
 *   php herramientas/cuenta.php crear --correo=… --nombre="…" [--rol=administrador] [--clave=…]
 *   php herramientas/cuenta.php clave --correo=… [--clave=…]
 *   php herramientas/cuenta.php sin-2fa --correo=…
 *   php herramientas/cuenta.php activar --correo=…
 *   php herramientas/cuenta.php suspender --correo=…
 *
 * Si no se pasa --clave, se genera una y se muestra una sola vez.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'consola');
define('ESQUEMA_VERSION', '1.0.0');

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

use App\Modelos\Usuario;
use App\Nucleo\Bd;
use App\Nucleo\Config;
use App\Nucleo\Cripto;
use App\Nucleo\Instalacion;

/* =========================================================================
   Pequeñas utilidades de consola
   ========================================================================= */

$color = static function (string $texto, string $cual): string {
    $codigos = ['ok' => '0;32', 'mal' => '0;31', 'aviso' => '0;33', 'tenue' => '0;90', 'fuerte' => '1;37'];
    if (!stream_isatty(STDOUT) || !isset($codigos[$cual])) {
        return $texto;
    }
    return "\033[" . $codigos[$cual] . 'm' . $texto . "\033[0m";
};

$linea = static function (string $texto = ''): void {
    fwrite(STDOUT, $texto . PHP_EOL);
};

$morir = static function (string $texto) use ($color): never {
    fwrite(STDERR, $color('✕ ' . $texto, 'mal') . PHP_EOL);
    exit(1);
};

/** Lee --clave=valor de los argumentos. */
$opcion = static function (string $nombre, ?string $porDefecto = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, "--$nombre=")) {
            return substr($arg, strlen($nombre) + 3);
        }
    }
    return $porDefecto;
};

/**
 * Contraseña legible pero larga.
 *
 * Se generan palabras separadas por guiones en vez de una sopa de símbolos:
 * quien la recibe tiene que poder dictarla por teléfono y escribirla en un
 * celular en la puerta del evento sin equivocarse tres veces.
 */
$generarClave = static function (): string {
    $silabas = ['ba','ca','da','fa','ga','la','ma','na','pa','ra','sa','ta','be','ce','de','fe',
                'le','me','ne','pe','re','se','te','bi','ci','di','li','mi','ni','pi','ri','si',
                'ti','bo','co','do','lo','mo','no','po','ro','so','to','bu','cu','du','lu','mu'];
    $palabras = [];
    for ($p = 0; $p < 4; $p++) {
        $palabra = '';
        for ($s = 0; $s < 3; $s++) {
            $palabra .= $silabas[random_int(0, count($silabas) - 1)];
        }
        $palabras[] = $palabra;
    }
    return implode('-', $palabras) . '-' . random_int(10, 99);
};

/* =========================================================================
   Arranque
   ========================================================================= */

$comando = $argv[1] ?? 'estado';
if (str_starts_with($comando, '--')) {
    $comando = 'estado';
}

if (!Config::existe()) {
    $morir('No existe config/config.php. La plataforma nunca se instaló: abre la dirección '
        . 'del sitio en el navegador y ejecuta el asistente.');
}

Config::cargar();
date_default_timezone_set((string) Config::obtener('zona_horaria', 'America/Bogota'));

try {
    Bd::conectar();
} catch (\Throwable $e) {
    $morir('No se pudo conectar a la base de datos: ' . $e->getMessage());
}

$prefijo = Bd::prefijo();
$tabla = $prefijo . 'usuario';

/** Busca la cuenta por correo o termina con un mensaje claro. */
$exigirCuenta = static function () use ($opcion, $morir): array {
    $correo = (string) $opcion('correo', '');
    if ($correo === '') {
        $morir('Falta --correo=alguien@narino.gov.co');
    }
    $usuario = Usuario::porCorreo($correo);
    if ($usuario === null) {
        $morir('No hay ninguna cuenta con el correo ' . $correo . '. Usa «listar» para verlas todas.');
    }
    return $usuario;
};

/* =========================================================================
   Comandos
   ========================================================================= */

switch ($comando) {

    /* ------------------------------------------------------- estado ----- */
    case 'estado':
        $d = Instalacion::diagnostico();
        $marca = static fn(bool $bien): string => $bien ? $color('✓', 'ok') : $color('✕', 'mal');

        $linea();
        $linea($color('Plataforma de Eventos TIC · estado de la instalación', 'fuerte'));
        $linea();
        $linea('  ' . $marca($d['config']) . ' config/config.php');
        $linea('  ' . $marca($d['bd']) . ' conexión a ' . Config::obtener('bd_nombre')
            . ' en ' . Config::obtener('bd_host'));
        $linea('  ' . $marca(!$d['faltantes']) . ' tablas: ' . $d['tablas'] . ' de ' . $d['esperadas']
            . ' con prefijo ' . $color($prefijo, 'fuerte'));
        if ($d['faltantes']) {
            $linea('      faltan: ' . implode(', ', $d['faltantes']));
        }
        $linea('  ' . $marca($d['administradores'] > 0) . ' cuentas administradoras activas: '
            . $d['administradores'] . ' (de ' . $d['usuarios'] . ' cuentas en total)');
        $linea('  ' . $marca($d['eventos'] > 0) . ' eventos creados: ' . $d['eventos']);
        $linea('  ' . $marca((bool) Config::obtener('instalado', false)) . ' marcada como instalada');
        $linea();
        $linea('  Las cuentas del equipo viven en la tabla ' . $color($tabla, 'fuerte') . '.');
        $linea('  El panel se abre en ' . $color(rtrim((string) Config::obtener('url_base', ''), '/')
            . '/admin/entrar', 'fuerte'));
        $linea();

        if ($d['motivo'] !== '') {
            $linea($color('  ▲ ' . $d['motivo'], 'aviso'));
            $linea();
        }
        if ($d['administradores'] === 0) {
            $linea('  Para crear la primera cuenta:');
            $linea($color('    php herramientas/cuenta.php crear --correo=tu@correo.gov.co --nombre="Tu Nombre"', 'tenue'));
            $linea();
        }
        break;

    /* ------------------------------------------------------- listar ----- */
    case 'listar':
        $usuarios = Bd::filas('SELECT * FROM {usuario} ORDER BY id');
        if (!$usuarios) {
            $linea($color('No hay ninguna cuenta en ' . $tabla . '.', 'aviso'));
            $linea('Crea la primera con: php herramientas/cuenta.php crear --correo=… --nombre="…"');
            break;
        }

        $linea();
        $linea($color(sprintf('  %-3s  %-30s  %-26s  %-14s  %-11s  %s', 'ID', 'CORREO', 'NOMBRE', 'ROL', 'ESTADO', '2FA'), 'fuerte'));
        foreach ($usuarios as $u) {
            $dosFactores = ((int) $u['totp_confirmado'] === 1) ? 'activo' : 'sin activar';
            $linea(sprintf(
                '  %-3d  %-30s  %-26s  %-14s  %-11s  %s',
                $u['id'],
                mb_strimwidth((string) $u['correo'], 0, 30, '…'),
                mb_strimwidth((string) $u['nombre'], 0, 26, '…'),
                $u['rol'],
                $u['estado'] === 'activo' ? $color('activo', 'ok') : $color('suspendido', 'mal'),
                $dosFactores
            ));
        }
        $linea();
        $linea($color('  Tabla: ' . $tabla, 'tenue'));
        $linea();
        break;

    /* -------------------------------------------------------- crear ----- */
    case 'crear':
        $correo = mb_strtolower(trim((string) $opcion('correo', '')));
        $nombre = trim((string) $opcion('nombre', ''));
        $rol = (string) $opcion('rol', 'administrador');
        $clave = (string) $opcion('clave', '');
        $generada = $clave === '';

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $morir('Falta un --correo válido.');
        }
        if (mb_strlen($nombre) < 5) {
            $morir('Falta --nombre="Nombre y apellido".');
        }
        if (!in_array($rol, Usuario::ROLES, true)) {
            $morir('El rol debe ser uno de: ' . implode(', ', Usuario::ROLES) . '.');
        }
        if (Usuario::porCorreo($correo) !== null) {
            $morir('Ya existe una cuenta con ese correo. Para devolverle el acceso: '
                . 'php herramientas/cuenta.php clave --correo=' . $correo);
        }
        if ($generada) {
            $clave = $generarClave();
        } elseif (mb_strlen($clave) < 12) {
            $morir('La contraseña debe tener al menos 12 caracteres.');
        }

        $id = Usuario::crear([
            'nombre' => $nombre,
            'correo' => $correo,
            'clave'  => $clave,
            'rol'    => $rol,
            'puesto' => 'Creada desde la consola',
        ]);

        $linea();
        $linea($color('✓ Cuenta creada en ' . $tabla . ' con el id ' . $id . '.', 'ok'));
        $linea();
        $linea('  Correo:     ' . $color($correo, 'fuerte'));
        if ($generada) {
            $linea('  Contraseña: ' . $color($clave, 'fuerte'));
            $linea($color('              Se muestra una sola vez. Cámbiala al entrar.', 'tenue'));
        }
        $linea('  Entrar en:  ' . rtrim((string) Config::obtener('url_base', ''), '/') . '/admin/entrar');
        if ($rol === 'administrador' && Config::obtener('exigir_2fa_admin', true)) {
            $linea();
            $linea($color('  Al entrar se pedirá vincular la aplicación de códigos: el segundo', 'tenue'));
            $linea($color('  factor es obligatorio para el rol administrador.', 'tenue'));
        }
        $linea();
        break;

    /* -------------------------------------------------------- clave ----- */
    case 'clave':
        $usuario = $exigirCuenta();
        $clave = (string) $opcion('clave', '');
        $generada = $clave === '';

        if ($generada) {
            $clave = $generarClave();
        } elseif (mb_strlen($clave) < 12) {
            $morir('La contraseña debe tener al menos 12 caracteres.');
        }

        Usuario::cambiarClave((int) $usuario['id'], $clave);

        $linea();
        $linea($color('✓ Contraseña cambiada. Se cerraron las sesiones abiertas de esa cuenta.', 'ok'));
        $linea();
        $linea('  Correo:     ' . $color((string) $usuario['correo'], 'fuerte'));
        if ($generada) {
            $linea('  Contraseña: ' . $color($clave, 'fuerte'));
            $linea($color('              Se muestra una sola vez.', 'tenue'));
        }
        $linea();
        break;

    /* ------------------------------------------------------ sin-2fa ----- */
    case 'sin-2fa':
        $usuario = $exigirCuenta();
        Usuario::quitarSegundoFactor((int) $usuario['id']);
        \App\Nucleo\Sesion::cerrarTodasDe('admin', (int) $usuario['id']);
        \App\Nucleo\Bitacora::registrar('segundo_factor_retirado', 'usuario', (int) $usuario['id']);

        $linea();
        $linea($color('✓ Segundo factor retirado de ' . $usuario['correo'] . '.', 'ok'));
        $linea('  En el próximo acceso se pedirá vincular otra vez la aplicación de códigos.');
        $linea();
        break;

    /* ------------------------------------------- activar / suspender ---- */
    case 'activar':
    case 'suspender':
        $usuario = $exigirCuenta();
        $estado = $comando === 'activar' ? 'activo' : 'suspendido';
        Usuario::cambiarEstado((int) $usuario['id'], $estado);

        $linea();
        $linea($color('✓ La cuenta ' . $usuario['correo'] . ' quedó ' . $estado . '.', 'ok'));
        $linea();
        break;

    /* --------------------------------------------------------- ayuda ---- */
    default:
        $linea();
        $linea($color('Cuentas del equipo organizador · Plataforma de Eventos TIC', 'fuerte'));
        $linea();
        $linea('  estado                                    diagnóstico de la instalación');
        $linea('  listar                                    todas las cuentas del equipo');
        $linea('  crear      --correo= --nombre= [--rol=]   crea una cuenta (rol por defecto: administrador)');
        $linea('  clave      --correo= [--clave=]           cambia la contraseña');
        $linea('  sin-2fa    --correo=                      quita el segundo factor');
        $linea('  activar    --correo=                      reactiva una cuenta suspendida');
        $linea('  suspender  --correo=                      suspende una cuenta');
        $linea();
        $linea($color('  Sin --clave se genera una y se muestra una sola vez. Pasarla por', 'tenue'));
        $linea($color('  argumento la deja en el historial del intérprete de comandos.', 'tenue'));
        $linea();
        exit($comando === 'ayuda' || $comando === '--ayuda' ? 0 : 1);
}

exit(0);
