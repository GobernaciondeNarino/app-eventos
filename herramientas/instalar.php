<?php
/**
 * Instalación desde la consola.
 *
 * El asistente del navegador es lo normal, pero depende de seis pasos, de una
 * cookie y de que nada se interrumpa por el camino. Cuando eso falla, el sitio
 * entero queda redirigiendo al asistente y no hay forma de salir desde fuera.
 * Esto hace lo mismo de una sola vez, con las mismas clases —no hay una segunda
 * implementación que pueda divergir— y contando en voz alta lo que va pasando.
 *
 * En Plesk, si no hay acceso SSH: Sitios web y dominios → Tareas programadas →
 * «Ejecutar un script PHP», con la ruta cumbreAI/herramientas/instalar.php y
 * los argumentos en el campo correspondiente. Ejecutar una vez y borrar la
 * tarea.
 *
 * Uso:
 *   php herramientas/instalar.php \
 *       --bd-nombre=eventos_tic --bd-usuario=eventos_app --bd-clave=… \
 *       --admin-correo=alguien@narino.gov.co --admin-nombre="Nombre Apellido" \
 *       --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
 *       --url=https://tic.narino.gov.co/cumbreAI
 *
 * Opciones:
 *   --bd-host=localhost  --bd-puerto=3306  --bd-prefijo=evt_
 *   --admin-clave=…      si no se pasa, se genera y se muestra una sola vez
 *   --modo=actualizar    limpio | actualizar | anexar. Por defecto actualizar
 *                        si ya hay tablas, y limpio si la base está vacía.
 *   --sin-2fa            no exigir segundo factor al administrador
 *   --esquema            con --reparar: pone la base al día con esta versión del
 *                        código (crea tablas y columnas que falten; nunca borra)
 *   --reparar            termina una instalación que se quedó a medias, sin
 *                        tocar las tablas ni los datos que ya existan: crea la
 *                        cuenta administradora si falta, el evento si falta, y
 *                        escribe config/config.php. Toma los datos de conexión
 *                        de config/config.php, o de config/instalacion.php si
 *                        el asistente llegó al paso 2, o de --bd-*
 *   --forzar             permite reinstalar sobre una instalación terminada
 *   --llave=…            la llave de cifrado anterior, si config/config.php se
 *                        perdió y la base ya tiene datos cifrados (es la línea
 *                        'llave_cifrado' del config.php de la copia de seguridad)
 *   --llave-nueva        seguir sin la llave anterior: los documentos guardados
 *                        no se podrán leer y cada cuenta configurará otra vez su
 *                        verificación en dos pasos
 *
 * Para terminar una instalación interrumpida (las tablas creadas pero la tabla
 * de usuarios vacía, que es lo que deja un asistente cortado a mitad):
 *
 *   php herramientas/instalar.php --reparar \
 *       --admin-correo=alguien@narino.gov.co --admin-nombre="Nombre Apellido" \
 *       --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
 *       --url=https://tic.narino.gov.co/cumbreAI
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', '3.9.0');

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

use App\Esquema;
use App\Modelos\Evento;
use App\Modelos\Usuario;
use App\Nucleo\Actualizacion;
use App\Nucleo\Bd;
use App\Nucleo\Config;
use App\Nucleo\Cripto;
use App\Nucleo\Instalacion;

/* =========================================================================
   Consola
   ========================================================================= */

$color = static function (string $texto, string $cual): string {
    $codigos = ['ok' => '0;32', 'mal' => '0;31', 'aviso' => '0;33', 'tenue' => '0;90', 'fuerte' => '1;37'];
    if (!stream_isatty(STDOUT) || !isset($codigos[$cual])) {
        return $texto;
    }
    return "\033[" . $codigos[$cual] . 'm' . $texto . "\033[0m";
};
$linea = static function (string $texto = ''): void { fwrite(STDOUT, $texto . PHP_EOL); };
$paso  = static function (string $texto) use ($linea, $color): void { $linea($color('· ' . $texto, 'tenue')); };
$bien  = static function (string $texto) use ($linea, $color): void { $linea($color('✓ ' . $texto, 'ok')); };
$morir = static function (string $texto) use ($color): never {
    fwrite(STDERR, $color('✕ ' . $texto, 'mal') . PHP_EOL);
    exit(1);
};

$opcion = static function (string $nombre, ?string $porDefecto = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, "--$nombre=")) {
            return substr($arg, strlen($nombre) + 3);
        }
    }
    return $porDefecto;
};
$bandera = static fn(string $nombre): bool => in_array("--$nombre", array_slice($argv, 1), true);

/**
 * La llave de cifrado que va a quedar en la configuración.
 *
 * La que ya tenga la configuración, si la tiene. Si no —config/config.php se
 * perdió—, la de --llave, comprobada contra los datos. Y una nueva solo si la
 * base no tiene nada cifrado, o si se pidió con --llave-nueva: hasta la 3.5 se
 * generaba sin preguntar, y con eso las cédulas guardadas y el segundo factor
 * de todas las cuentas dejaban de poder leerse.
 */
$resolverLlave = static function () use ($opcion, $bandera, $morir): string {
    $actual = (string) Config::obtener('llave_cifrado', '');
    if ($actual !== '') {
        return $actual;
    }
    $cifrados = Instalacion::datosCifrados();
    $dada = trim((string) $opcion('llave', ''));
    if ($dada !== '') {
        $cruda = base64_decode($dada, true);
        if ($cruda === false || strlen($cruda) !== 32) {
            $morir('--llave no tiene el formato de una llave de la plataforma (32 bytes en base64).');
        }
        if ($cifrados['muestras'] !== [] && !Instalacion::llaveAbreLosDatos($dada, $cifrados['muestras'])) {
            $morir('La llave de --llave no abre los datos de esta base. Cópiala del config/config.php que '
                . 'estaba en el servidor antes de actualizar.');
        }
        return $dada;
    }
    if ($cifrados['muestras'] !== [] && !$bandera('llave-nueva')) {
        $morir('La base tiene datos cifrados (' . $cifrados['personas'] . ' documentos, ' . $cifrados['cuentas']
            . ' cuentas con segundo factor) y no hay llave con que leerlos: falta config/config.php. '
            . 'Pasa la llave anterior con --llave=… (la línea \'llave_cifrado\' del config.php de la copia '
            . 'de seguridad), o añade --llave-nueva para seguir sin ella sabiendo que esos datos no se '
            . 'podrán leer.');
    }
    return Cripto::generarLlave();
};

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

$linea();
$linea($color('Plataforma de Eventos TIC · instalación desde la consola', 'fuerte'));
$linea();

/* =========================================================================
   Reparar: terminar una instalación que se quedó a medias
   -------------------------------------------------------------------------
   Antes esto solo corregía la marca «instalado», y exigía que la base ya
   estuviera completa. Justo el caso que más se da —las tablas creadas y la
   tabla de usuarios vacía, que es lo que deja un asistente cortado entre el
   paso 3 y el 5— caía en el «no hay nada que reparar» y remitía al asistente,
   que es precisamente lo que no estaba funcionando.

   Ahora hace lo que falte y nada más: nunca toca las tablas ni borra datos.
   ========================================================================= */

if ($bandera('reparar')) {
    /* ---- De dónde salen los datos de conexión ------------------------- */

    $origen = '';
    if (Config::existe()) {
        Config::cargar();
        $origen = 'config/config.php';
    } elseif (($enCurso = Instalacion::credencialesEnCurso()) !== null) {
        // El asistente llegó al paso 2 y dejó ahí las credenciales.
        Config::establecerEnMemoria([
            'bd_host'    => $enCurso['host'],
            'bd_puerto'  => (int) $enCurso['puerto'],
            'bd_nombre'  => $enCurso['nombre'],
            'bd_usuario' => $enCurso['usuario'],
            'bd_clave'   => $enCurso['clave'],
            'bd_prefijo' => $enCurso['prefijo'],
        ]);
        Bd::establecerPrefijo((string) $enCurso['prefijo']);
        $origen = 'config/instalacion.php';
    } elseif ($opcion('bd-nombre', '') !== '') {
        Config::establecerEnMemoria([
            'bd_host'    => (string) $opcion('bd-host', 'localhost'),
            'bd_puerto'  => (int) ($opcion('bd-puerto', '3306') ?: 3306),
            'bd_nombre'  => (string) $opcion('bd-nombre', ''),
            'bd_usuario' => (string) $opcion('bd-usuario', ''),
            'bd_clave'   => (string) $opcion('bd-clave', ''),
            'bd_prefijo' => (string) $opcion('bd-prefijo', 'evt_'),
        ]);
        Bd::establecerPrefijo((string) $opcion('bd-prefijo', 'evt_'));
        $origen = 'los argumentos --bd-*';
    } else {
        $morir('No hay de dónde sacar los datos de conexión: no existe config/config.php, '
            . 'tampoco config/instalacion.php, y no se pasaron --bd-nombre y compañía. '
            . 'Añádelos, o ejecuta la instalación completa (mira --ayuda).');
    }

    $paso('Conectando con los datos de ' . $origen);
    try {
        Bd::reiniciar();
        Bd::conectar();
    } catch (\Throwable $e) {
        $morir('No se pudo conectar: ' . $e->getMessage());
    }
    $bien('Conexión correcta con ' . Config::obtener('bd_nombre'));

    date_default_timezone_set((string) Config::obtener('zona_horaria', 'America/Bogota'));

    /* ---- Qué falta ---------------------------------------------------- */

    Instalacion::olvidar();
    $d = Instalacion::diagnostico();
    $linea('  ' . $d['tablas'] . ' de ' . $d['esperadas'] . ' tablas · '
        . $d['administradores'] . ' administradores · ' . $d['eventos'] . ' eventos');

    /* ---- Lo que le falte al esquema ------------------------------------
       Con --esquema, reparar también pone la base al día: crea las tablas que
       falten y agrega las columnas nuevas. Solo agrega; no borra ni cambia
       nada de lo que ya hay, así que se puede correr con el evento en curso.

       Es lo que hace falta al actualizar el código de la plataforma copiando
       archivos: los archivos suben, pero la base no se entera sola. Lo mismo
       está en el panel, para quien no tiene consola.                       */

    if ($bandera('esquema')) {
        [$pendiente, $motivo] = Esquema::revisionPendiente();
        if (!$pendiente) {
            $bien('El esquema ya estaba al día (versión ' . Esquema::VERSION . ')');
        } else {
            $paso('Actualizando el esquema: ' . $motivo);
            try {
                // Lo mismo que hacen el botón del panel y la actualización
                // automática: esquema, carpetas, marca y bitácora.
                $hechas = Actualizacion::aplicar('consola');
            } catch (\Throwable $e) {
                $morir('No se pudo actualizar el esquema: ' . $e->getMessage());
            }
            foreach ($hechas as $h) {
                $linea('    · ' . $h['tabla'] . ' ' . $h['accion'] . ': ' . $h['detalle']);
            }
            $bien('Esquema en la versión ' . Esquema::VERSION);
        }
        // olvidar() antes de volver a preguntar: el diagnóstico se guarda la
        // primera vez que se pide, y sin esto devolvía el de antes de
        // actualizar. La consola creaba las tablas que faltaban y a renglón
        // seguido moría con «Faltan 1 tablas», así que quien actualizaba veía
        // un error justo cuando todo había salido bien.
        Instalacion::olvidar();
        $d = Instalacion::diagnostico();
    }

    if ($d['faltantes']) {
        $morir('Faltan ' . count($d['faltantes']) . ' tablas (' . implode(', ', array_slice($d['faltantes'], 0, 5))
            . '). Añade --esquema para crearlas sin tocar lo que ya hay, o ejecuta la '
            . 'instalación completa con --modo=actualizar.');
    }

    /* ---- La cuenta administradora ------------------------------------- */

    $claveNueva = '';
    if ($d['administradores'] === 0) {
        $correo = mb_strtolower(trim((string) $opcion('admin-correo', '')));
        $nombre = trim((string) $opcion('admin-nombre', ''));
        $clave  = (string) $opcion('admin-clave', '');

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($nombre) < 5) {
            $morir('No hay ninguna cuenta administradora y falta con qué crearla. '
                . 'Añade --admin-correo y --admin-nombre (y --admin-clave, o se genera una).');
        }
        if ($clave !== '' && mb_strlen($clave) < 12) {
            $morir('--admin-clave debe tener 12 caracteres o más.');
        }
        if ($clave === '') {
            $clave = $generarClave();
            $claveNueva = $clave;
        }

        // La llave de cifrado tiene que existir antes de crear a nadie, y si ya
        // había una no se toca: cambiarla vuelve ilegible todo lo guardado.
        if ((string) Config::obtener('llave_cifrado', '') === '') {
            Config::establecerEnMemoria(['llave_cifrado' => $resolverLlave()] + Config::todo());
        }

        $paso('Creando la cuenta administradora');
        try {
            $usuarioId = Usuario::asegurarAdministrador($correo, $nombre, Cripto::hashClave($clave));
        } catch (\Throwable $e) {
            $morir('No se pudo crear la cuenta: ' . $e->getMessage());
        }
        $bien('Cuenta ' . $correo . ' lista, con el id ' . $usuarioId);
    } else {
        $bien('Ya hay ' . $d['administradores'] . ' cuenta(s) administradora(s); no se toca ninguna');
    }

    /* ---- El evento ----------------------------------------------------- */

    if ($d['eventos'] === 0) {
        $nombreEvento = trim((string) $opcion('evento', ''));
        $inicioEvento = (string) $opcion('inicio', date('Y-m-d'));
        if (mb_strlen($nombreEvento) >= 3 && Evento::fechaValida($inicioEvento)) {
            $paso('Creando el evento y sus jornadas');
            try {
                $eventoId = Evento::crear([
                    'nombre'       => $nombreEvento,
                    'dependencia'  => (string) $opcion('dependencia', 'Secretaría TIC, Innovación y Gobierno Abierto'),
                    'sede'         => (string) $opcion('sede', ''),
                    'fecha_inicio' => $inicioEvento,
                    'jornadas'     => max(1, min(30, (int) ($opcion('dias', '3') ?: 3))),
                    'estado'       => 'abierto',
                    'activo'       => true,
                    'preset'       => (string) $opcion('preset', 'tic-nocturno'),
                    'tipografia'   => (string) $opcion('tipografia', 'tecnologica'),
                ]);
            } catch (\Throwable $e) {
                $morir('No se pudo crear el evento: ' . $e->getMessage());
            }
            $bien('Evento «' . $nombreEvento . '» creado, id ' . $eventoId);
        } else {
            $linea($color('  ! No hay ningún evento y no se pasó --evento. El panel abrirá igual; '
                . 'crea el evento desde Eventos, o vuelve a ejecutar esto con --evento e --inicio.', 'aviso'));
        }
    } else {
        $bien('Ya hay ' . $d['eventos'] . ' evento(s); no se crea ninguno');
    }

    /* ---- Y por último la configuración -------------------------------- */

    $paso('Escribiendo config/config.php');
    $configuracion = ['instalado' => true, 'version' => APP_VERSION, 'llave_cifrado' => $resolverLlave()]
        + Config::todo() + [
        'zona_horaria'       => 'America/Bogota',
        'url_base'           => rtrim((string) $opcion('url', ''), '/'),
        'correo_remitente'   => 'no-responder@localhost',
        'correo_nombre'      => 'Eventos TIC',
        'modo_correo'        => function_exists('mail') ? 'php' : 'registro',
        'exigir_2fa_admin'   => !$bandera('sin-2fa'),
        'proxies_confiables' => [],
        'depurar'            => false,
        'instalado_en'       => date('c'),
    ];
    if (!Config::escribir($configuracion)) {
        $morir('No se pudo escribir config/config.php. Lo demás sí quedó hecho: dale permiso de '
            . 'escritura a config/ y vuelve a ejecutar esto mismo, que no duplicará nada.');
    }
    @unlink(RAIZ . '/config/instalacion.php');
    $bien('Configuración escrita');

    Instalacion::olvidar();
    $final = Instalacion::diagnostico();

    $linea();
    $linea($color($final['completa'] ? 'Instalación reparada.' : 'Reparación incompleta.', 'fuerte'));
    if (!$final['completa']) {
        $linea('  ' . $final['motivo']);
    }
    if ($claveNueva !== '') {
        $linea();
        $linea('  Contraseña generada (se muestra una sola vez):');
        $linea($color('    ' . $claveNueva, 'fuerte'));
    }
    $linea();
    $linea('  Comprueba el estado con:');
    $linea($color('    php herramientas/cuenta.php estado', 'tenue'));
    $linea();
    exit($final['completa'] ? 0 : 1);
}

/* =========================================================================
   Instalación completa
   ========================================================================= */

if ($bandera('ayuda') || $bandera('help')) {
    // La ayuda es el comentario de cabecera de este mismo archivo: así no hay
    // dos textos que puedan contradecirse.
    preg_match('/\/\*\*(.*?)\*\//s', (string) file_get_contents(__FILE__), $m);
    foreach (explode("\n", $m[1] ?? '') as $l) {
        $linea(rtrim((string) preg_replace('/^\s*\*\s?/', '', $l)));
    }
    exit(0);
}

Config::cargar();
if (Config::instalado() && !$bandera('forzar')) {
    $linea($color('La plataforma ya está instalada.', 'aviso'));
    $linea('  Para volver a instalar encima, añade --forzar. Para ver el estado:');
    $linea($color('    php herramientas/cuenta.php estado', 'tenue'));
    $linea();
    exit(0);
}

$bd = [
    'host'    => (string) $opcion('bd-host', 'localhost'),
    'puerto'  => (int) ($opcion('bd-puerto', '3306') ?: 3306),
    'nombre'  => (string) $opcion('bd-nombre', ''),
    'usuario' => (string) $opcion('bd-usuario', ''),
    'clave'   => (string) $opcion('bd-clave', ''),
    'prefijo' => (string) $opcion('bd-prefijo', 'evt_'),
];

$adminCorreo = mb_strtolower(trim((string) $opcion('admin-correo', '')));
$adminNombre = trim((string) $opcion('admin-nombre', ''));
$adminClave  = (string) $opcion('admin-clave', '');
$claveGenerada = $adminClave === '';

$evento   = trim((string) $opcion('evento', ''));
$inicio   = (string) $opcion('inicio', date('Y-m-d'));
$jornadas = max(1, min(30, (int) ($opcion('dias', '3') ?: 3)));
$url      = rtrim((string) $opcion('url', ''), '/');

/* ---- Validación, toda junta y antes de tocar nada --------------------- */

$faltan = [];
if ($bd['nombre'] === '')  { $faltan[] = '--bd-nombre'; }
if ($bd['usuario'] === '') { $faltan[] = '--bd-usuario'; }
if (!filter_var($adminCorreo, FILTER_VALIDATE_EMAIL)) { $faltan[] = '--admin-correo (válido)'; }
if (mb_strlen($adminNombre) < 5) { $faltan[] = '--admin-nombre'; }
if (mb_strlen($evento) < 3) { $faltan[] = '--evento'; }
if ($url === '' || !preg_match('#^https?://#', $url)) { $faltan[] = '--url (con http:// o https://)'; }
if (!Evento::fechaValida($inicio)) { $faltan[] = '--inicio (AAAA-MM-DD)'; }
if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $bd['prefijo'])) { $faltan[] = '--bd-prefijo (letras minúsculas)'; }
if (!$claveGenerada && mb_strlen($adminClave) < 12) { $faltan[] = '--admin-clave de 12 caracteres o más'; }

if ($faltan) {
    $morir('Faltan datos o son inválidos: ' . implode(', ', $faltan) . '.'
        . PHP_EOL . '  Usa --ayuda para ver un ejemplo completo.');
}

/* ---- Requisitos del servidor ------------------------------------------ */

$paso('Comprobando el servidor');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        $morir("Falta la extensión de PHP «{$extension}», que es obligatoria.");
    }
}
if (PHP_VERSION_ID < 80100) {
    $morir('Se requiere PHP 8.1 o superior; este es ' . PHP_VERSION . '.');
}
foreach (['config', 'almacen/logos', 'almacen/fotos', 'almacen/documentos',
          'almacen/respaldos', 'almacen/registro'] as $relativa) {
    $ruta = RAIZ . '/' . $relativa;
    if (!is_dir($ruta)) {
        @mkdir($ruta, 0750, true);
    }
    if (!is_dir($ruta) || !is_writable($ruta)) {
        $morir("La carpeta $relativa/ no existe o no se puede escribir en ella.");
    }
}
$bien('PHP ' . PHP_VERSION . ', extensiones y permisos correctos');

/* ---- Conexión --------------------------------------------------------- */

$paso('Conectando a la base de datos');
[$ok, $mensaje] = Bd::probar($bd);
if (!$ok) {
    $morir($mensaje);
}
Config::establecerEnMemoria([
    'bd_host' => $bd['host'], 'bd_puerto' => $bd['puerto'], 'bd_nombre' => $bd['nombre'],
    'bd_usuario' => $bd['usuario'], 'bd_clave' => $bd['clave'], 'bd_prefijo' => $bd['prefijo'],
]);
Bd::reiniciar();
Bd::conectar();
Bd::establecerPrefijo($bd['prefijo']);
$bien($mensaje);

/* ---- Tablas ----------------------------------------------------------- */

$existentes = Esquema::existentes();
$modo = (string) $opcion('modo', $existentes ? 'actualizar' : 'limpio');
if (!in_array($modo, ['limpio', 'actualizar', 'anexar'], true)) {
    $morir('El modo debe ser limpio, actualizar o anexar.');
}
if ($modo === 'limpio' && $existentes && !$bandera('forzar')) {
    $morir('El modo limpio borraría ' . count($existentes) . ' tablas que ya existen. '
        . 'Usa --modo=actualizar para conservarlas, o añade --forzar si de verdad quieres borrarlas.');
}

// La llave se decide antes de tocar el esquema: si falta y la base tiene datos
// cifrados, es mejor parar aquí que a mitad. En modo limpio los datos se borran,
// así que no hay nada que proteger.
$llave = $modo === 'limpio'
    ? ((string) Config::obtener('llave_cifrado', '') ?: Cripto::generarLlave())
    : $resolverLlave();

$paso('Aplicando el esquema en modo ' . $modo . ' (' . count($existentes) . ' tablas presentes)');
try {
    $hechas = Esquema::aplicar($modo, $existentes);
} catch (\Throwable $e) {
    $morir('No se pudo aplicar el esquema: ' . $e->getMessage());
}
$bien(count($hechas) . ' operaciones sobre el esquema · ' . count(Esquema::nombres()) . ' tablas en total');

/* ---- Configuración en memoria, para que lo demás pueda escribir ------- */

$configuracion = [
    'instalado'        => true,
    'version'          => APP_VERSION,
    'bd_host'          => $bd['host'],
    'bd_puerto'        => $bd['puerto'],
    'bd_nombre'        => $bd['nombre'],
    'bd_usuario'       => $bd['usuario'],
    'bd_clave'         => $bd['clave'],
    'bd_prefijo'       => $bd['prefijo'],
    'llave_cifrado'    => $llave,
    'zona_horaria'     => 'America/Bogota',
    'url_base'         => $url,
    'correo_remitente' => 'no-responder@' . (parse_url($url, PHP_URL_HOST) ?: 'localhost'),
    'correo_nombre'    => $evento,
    'modo_correo'      => function_exists('mail') ? 'php' : 'registro',
    'exigir_2fa_admin' => !$bandera('sin-2fa'),
    'proxies_confiables' => [],
    'depurar'          => false,
    'instalado_en'     => date('c'),
];
Config::establecerEnMemoria($configuracion);
date_default_timezone_set('America/Bogota');

/* ---- Cuenta y evento, antes de escribir la marca ---------------------- */

$paso('Creando la cuenta administradora');
if ($claveGenerada) {
    $adminClave = $generarClave();
}
try {
    $usuarioId = Usuario::asegurarAdministrador($adminCorreo, $adminNombre, Cripto::hashClave($adminClave));
} catch (\Throwable $e) {
    $morir('No se pudo crear la cuenta: ' . $e->getMessage());
}
$bien('Cuenta ' . $adminCorreo . ' lista, con el id ' . $usuarioId);

$paso('Creando el evento y sus jornadas');
try {
    $eventoId = Evento::crear([
        'nombre'       => $evento,
        'dependencia'  => (string) $opcion('dependencia', 'Secretaría TIC, Innovación y Gobierno Abierto'),
        'sede'         => (string) $opcion('sede', ''),
        'fecha_inicio' => $inicio,
        'jornadas'     => $jornadas,
        'estado'       => 'abierto',
        'activo'       => true,
        'preset'       => (string) $opcion('preset', 'tic-nocturno'),
        'tipografia'   => (string) $opcion('tipografia', 'tecnologica'),
    ]);
} catch (\Throwable $e) {
    $morir('No se pudo crear el evento: ' . $e->getMessage());
}
$bien('Evento «' . $evento . '» con ' . $jornadas . ' jornadas, id ' . $eventoId);

/* ---- Y solo ahora, la marca ------------------------------------------- */

$paso('Escribiendo config/config.php');
if (!Config::escribir($configuracion)) {
    $morir('No se pudo escribir config/config.php. La cuenta y el evento sí quedaron creados: '
        . 'arregla los permisos de config/ y vuelve a ejecutar esto mismo, que no duplicará nada.');
}
@unlink(RAIZ . '/config/instalacion.php');
$bien('Configuración escrita');

/* ---- Resumen ---------------------------------------------------------- */

$linea();
$linea($color('Instalación terminada.', 'fuerte'));
$linea();
$linea('  Panel:      ' . $color($url . '/admin/entrar', 'fuerte'));
$linea('  Usuario:    ' . $color($adminCorreo, 'fuerte'));
if ($claveGenerada) {
    $linea('  Contraseña: ' . $color($adminClave, 'fuerte'));
    $linea($color('              Se muestra una sola vez. Cámbiala al entrar.', 'tenue'));
}
$linea('  Cuentas en: ' . $color($bd['prefijo'] . 'usuario', 'fuerte'));
$linea();
if (!$bandera('sin-2fa')) {
    $linea($color('  Al entrar se pedirá vincular una aplicación de códigos: el segundo', 'tenue'));
    $linea($color('  factor es obligatorio para el rol administrador.', 'tenue'));
    $linea();
}
$linea('  Comprueba el estado cuando quieras con:');
$linea($color('    php herramientas/cuenta.php estado', 'tenue'));
$linea('  O desde el navegador, en ' . $url . '/instalar/diagnostico');
$linea();

exit(0);
