<?php
/**
 * El asistente de instalación, de principio a fin y por partes.
 *
 * Existe por lo que pasó en producción: la instalación se quedó a medias —las
 * dieciséis tablas creadas, la tabla de usuarios vacía, sin config/config.php—
 * y las dos pantallas a las que se acude en ese momento mentían. El asistente
 * respondía 500 sin decir por qué, y el diagnóstico anunciaba «sin conexión, 0
 * de 16 tablas» con las dieciséis tablas delante. Quien instalaba se quedaba
 * sin ninguna forma de averiguar qué faltaba.
 *
 * Lo que se comprueba aquí:
 *  · cada paso del asistente se pinta, en cualquier estado de la base
 *  · el diagnóstico mira la base por config/instalacion.php cuando todavía no
 *    hay config/config.php, y cuenta las tablas de verdad
 *  · un fallo antes de terminar de instalar se ve en pantalla, con archivo y
 *    línea, en vez de un «algo salió mal» sin salida
 *  · y en cuanto la instalación termina, ese detalle deja de mostrarse
 *
 * Necesita el servidor de pruebas y una base de datos vacía:
 *
 *   BASE=/cumbreAI php -S 127.0.0.1:8900 -t . pruebas/servidor.php &
 *   php pruebas/instalacion.php
 */
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

$RAIZ = dirname(__DIR__);
$BASE = getenv('BASE_PRUEBAS') ?: 'http://127.0.0.1:8900/cumbreAI';
$BD   = [
    'host'    => getenv('BD_HOST') ?: 'localhost',
    'nombre'  => getenv('BD_NOMBRE') ?: 'eventos_pruebas',
    'usuario' => getenv('BD_USUARIO') ?: 'eventos_app',
    'clave'   => getenv('BD_CLAVE') ?: 'clave_de_prueba_2026',
    'prefijo' => 'evt_',
];

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  ✗ $nombre" . ($extra !== '' ? "  → $extra" : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n";
}

/**
 * Cambia un archivo del código y espera a que el servidor lo vea.
 *
 * Con OPcache encendido —lo normal, y lo que hay en Plesk— el proceso que
 * atiende las peticiones sigue sirviendo la versión compilada hasta que pasa
 * opcache.revalidate_freq. Sin esta espera la prueba pedía la página antes de
 * que el fallo artificial existiera para el servidor, y daba 200.
 */
function reescribir(string $ruta, string $contenido): void
{
    file_put_contents($ruta, $contenido);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($ruta, true);
    }
    $espera = (int) (ini_get('opcache.revalidate_freq') ?: 2);
    sleep(max(1, $espera) + 1);
}

/* =====================================================================
   Un navegador de mentira: guarda cookies y saca el testigo del formulario
   ===================================================================== */

final class Navegador
{
    private array $cookies = [];
    private string $testigo = '';

    public function __construct(private string $base) {}

    public function ir(string $ruta, ?array $datos = null): array
    {
        $cabeceras = "User-Agent: Pruebas/1.0\r\n";
        if ($this->cookies) {
            $pares = [];
            foreach ($this->cookies as $nombre => $valor) {
                $pares[] = $nombre . '=' . $valor;
            }
            $cabeceras .= 'Cookie: ' . implode('; ', $pares) . "\r\n";
        }

        $opciones = ['http' => [
            'method'          => $datos === null ? 'GET' : 'POST',
            'header'          => $cabeceras,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'timeout'         => 20,
        ]];

        if ($datos !== null) {
            $datos['_testigo'] ??= $this->testigo;
            $cuerpo = http_build_query($datos);
            $opciones['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($cuerpo) . "\r\n";
            $opciones['http']['content'] = $cuerpo;
        }

        $html = (string) @file_get_contents($this->base . $ruta, false, stream_context_create($opciones));
        $codigo = 0;
        $destino = '';
        foreach ($http_response_header ?? [] as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $codigo = (int) $m[1];
            }
            if (stripos($linea, 'Location:') === 0) {
                $destino = trim(substr($linea, 9));
            }
            if (stripos($linea, 'Set-Cookie:') === 0) {
                [$par] = explode(';', trim(substr($linea, 11)), 2);
                [$nombre, $valor] = array_pad(explode('=', $par, 2), 2, '');
                $this->cookies[trim($nombre)] = trim($valor);
            }
        }
        if (preg_match('/name="_testigo" value="([a-f0-9]{64})"/', $html, $m)) {
            $this->testigo = $m[1];
        }

        return ['codigo' => $codigo, 'destino' => $destino, 'html' => $html];
    }

    public function olvidarCookies(): void
    {
        $this->cookies = [];
        $this->testigo = '';
    }

    /** Deja una cookie puesta como si viniera de una visita anterior. */
    public function plantarCookie(string $nombre, string $valor): void
    {
        $this->cookies[$nombre] = $valor;
    }
}

function h1(string $html): string
{
    return preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $m)
        ? trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1])))
        : '';
}

function texto(string $html): string
{
    $limpio = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html);
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($limpio)));
}

/* =====================================================================
   Utilidades del entorno de prueba
   ===================================================================== */

function pdo(array $bd): PDO
{
    return new PDO(
        "mysql:host={$bd['host']};dbname={$bd['nombre']};charset=utf8mb4",
        $bd['usuario'],
        $bd['clave'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function vaciarBase(array $bd): void
{
    $conexion = pdo($bd);
    $conexion->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tablas = $conexion->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $tabla) {
        $conexion->exec('DROP TABLE IF EXISTS `' . $tabla . '`');
    }
    $conexion->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function contarTablas(array $bd): int
{
    return (int) pdo($bd)->query('SHOW TABLES')->rowCount();
}

function limpiarConfig(string $raiz): void
{
    foreach (['config.php', 'instalacion.php', '.llave-asistente'] as $archivo) {
        @unlink($raiz . '/config/' . $archivo);
    }
    clearstatcache();
}

echo "\nAsistente de instalación\n";
echo str_repeat('─', 62) . "\n";

try {
    pdo($BD);
} catch (Throwable $e) {
    echo "\n  No hay base de datos de pruebas: " . $e->getMessage() . "\n";
    echo "  Crea «{$BD['nombre']}» y vuelve a intentarlo.\n\n";
    exit(1);
}

$copiaConfig = is_file($RAIZ . '/config/config.php')
    ? (string) file_get_contents($RAIZ . '/config/config.php')
    : null;

$restaurar = static function () use ($RAIZ, $copiaConfig): void {
    limpiarConfig($RAIZ);
    if ($copiaConfig !== null) {
        file_put_contents($RAIZ . '/config/config.php', $copiaConfig);
        @chmod($RAIZ . '/config/config.php', 0640);
    }
};
register_shutdown_function($restaurar);

vaciarBase($BD);
limpiarConfig($RAIZ);

$navegador = new Navegador($BASE);

/* ---------------------------------------------------------------------
   Pasos 1 a 3: hasta crear las tablas
   --------------------------------------------------------------------- */

titulo('Del paso 1 al 3');

$r = $navegador->ir('/instalar');
comprobar('sin instalar, /instalar abre el asistente', $r['codigo'] === 200 && h1($r['html']) !== '',
    'código ' . $r['codigo']);

$r = $navegador->ir('/instalar', ['accion' => 'paso1']);
comprobar('el paso 1 pasa al 2', $r['codigo'] === 303 && str_contains($r['destino'], 'paso=2'),
    $r['codigo'] . ' ' . $r['destino']);

$navegador->ir('/instalar?paso=2');
$r = $navegador->ir('/instalar', [
    'accion'     => 'paso2',
    'bd_host'    => $BD['host'],
    'bd_puerto'  => '3306',
    'bd_nombre'  => $BD['nombre'],
    'bd_usuario' => $BD['usuario'],
    'bd_clave'   => $BD['clave'],
    'bd_prefijo' => $BD['prefijo'],
]);
comprobar('el paso 2 conecta y pasa al 3', $r['codigo'] === 303 && str_contains($r['destino'], 'paso=3'),
    $r['codigo'] . ' ' . $r['destino']);
comprobar('la contraseña de la base queda en config/instalacion.php y no en la cookie',
    is_file($RAIZ . '/config/instalacion.php'));
comprobar('y config/config.php sigue sin existir: instalar no ha terminado',
    !is_file($RAIZ . '/config/config.php'));

$navegador->ir('/instalar?paso=3');
$r = $navegador->ir('/instalar', ['accion' => 'paso3', 'modo' => 'limpio', 'confirmar_limpio' => '1']);
comprobar('el paso 3 crea las tablas y pasa al 4', $r['codigo'] === 303 && str_contains($r['destino'], 'paso=4'),
    $r['codigo'] . ' ' . $r['destino']);

// El número sale del esquema y no de una constante escrita a mano: cada vez
// que se agregaba una tabla, esta prueba fallaba sin que hubiera nada roto.
//
// Se lee el archivo del esquema directamente. Esta prueba es de caja negra
// —habla con el servidor por HTTP— y no carga la aplicación, pero
// Esquema::nombres() no toca la base ni ninguna otra clase: es un arreglo.
if (!defined('EVENTOS_TIC')) {
    define('EVENTOS_TIC', true);
}
require_once $RAIZ . '/app/Esquema.php';
$esperadas = count(App\Esquema::nombres());

$tablas = contarTablas($BD);
comprobar("las $esperadas tablas están creadas", $tablas === $esperadas, "hay $tablas");

/* ---------------------------------------------------------------------
   El estado exacto en que se quedó la instalación de producción
   --------------------------------------------------------------------- */

titulo('Instalación parada entre el paso 3 y el 5');

$r = $navegador->ir('/instalar/diagnostico');
$cuerpo = texto($r['html']);
comprobar('el diagnóstico responde', $r['codigo'] === 200, 'código ' . $r['codigo']);
comprobar("cuenta las $esperadas tablas aunque no exista config/config.php",
    str_contains($cuerpo, "$esperadas de $esperadas"),
    'decía: ' . (preg_match('/\d+ de \d+/', $cuerpo, $m) ? $m[0] : 'nada'));
comprobar('dice que la conexión salió de config/instalacion.php',
    str_contains($cuerpo, 'config/instalacion.php (instalación en curso)'));
comprobar('y explica que falta la cuenta administradora',
    str_contains($cuerpo, 'tabla de usuarios está vacía'));
comprobar('sin filtrar la contraseña de la base de datos',
    !str_contains($r['html'], $BD['clave']));

titulo('Cada paso se pinta, con las tablas ya creadas');

foreach ([1 => 'Comprobación', 2 => 'Conexión', 3 => 'Tablas', 4 => 'Cuenta', 5 => 'Primer evento', 6 => 'terminada'] as $paso => $esperado) {
    $r = $navegador->ir('/instalar?paso=' . $paso);
    comprobar("paso $paso: " . mb_strtolower($esperado),
        $r['codigo'] === 200 && stripos(h1($r['html']), $esperado) !== false,
        $r['codigo'] . ' «' . h1($r['html']) . '»');
}

titulo('Y también sin la cookie del proceso');

$otro = new Navegador($BASE);
foreach ([1, 2, 3, 4, 5, 6] as $paso) {
    $r = $otro->ir('/instalar?paso=' . $paso);
    comprobar("paso $paso responde 200 sin estado previo", $r['codigo'] === 200, 'código ' . $r['codigo']);
}

/* ---------------------------------------------------------------------
   Con cookies de sesión de una instalación anterior
   ---------------------------------------------------------------------
   El navegador de quien ya intentó instalar guarda las cookies de sesión de
   entonces. Si después se hace una instalación limpia —borrar
   config/config.php y vaciar la base—, esas cookies siguen viajando.

   El asistente pinta un testigo CSRF en cada formulario; el testigo preguntaba
   por la sesión, la sesión consultaba la base, y la base no estaba configurada
   (paso 1) o no tenía todavía la tabla «sesion» (paso 3). Resultado: 500 en el
   asistente, y solo en ese navegador, lo que lo hacía parecer un fallo del
   servidor. Pasó en producción, dos veces.
   --------------------------------------------------------------------- */

titulo('Con cookies de sesión de una instalación anterior');

$conRestos = new Navegador($BASE);
$conRestos->plantarCookie('evtic_admin', str_repeat('a', 64));
$conRestos->plantarCookie('evtic_asis', str_repeat('b', 64));

foreach ([1, 2, 3, 4, 5, 6] as $paso) {
    $r = $conRestos->ir('/instalar?paso=' . $paso);
    comprobar("paso $paso no revienta con una sesión muerta encima",
        $r['codigo'] === 200, 'código ' . $r['codigo']);
}

$r = $conRestos->ir('/instalar/diagnostico');
comprobar('el diagnóstico tampoco', $r['codigo'] === 200, 'código ' . $r['codigo']);

$r = $conRestos->ir('/');
comprobar('y la portada sigue redirigiendo al asistente, no a un error',
    $r['codigo'] === 303, 'código ' . $r['codigo']);

/* ---------------------------------------------------------------------
   Un fallo antes de terminar tiene que verse
   --------------------------------------------------------------------- */

titulo('Un fallo durante la instalación se explica en pantalla');

$ruta = $RAIZ . '/app/Controladores/Instalador.php';
$original = (string) file_get_contents($ruta);
$averiado = str_replace(
    "    private function requisitos(): array\n    {\n        \$lista = [];",
    "    private function requisitos(): array\n    {\n        throw new \\RuntimeException('fallo de prueba a propósito');\n        \$lista = [];",
    $original
);
comprobar('se pudo preparar el fallo artificial', $averiado !== $original);

reescribir($ruta, $averiado);
try {
    $r = $navegador->ir('/instalar');
    $cuerpo = texto($r['html']);
    comprobar('responde 500', $r['codigo'] === 500, 'código ' . $r['codigo']);
    comprobar('dice la clase y el mensaje de la excepción',
        str_contains($cuerpo, 'RuntimeException') && str_contains($cuerpo, 'fallo de prueba a propósito'));
    comprobar('dice el archivo y la línea',
        str_contains($cuerpo, 'app/Controladores/Instalador.php:'));
    comprobar('sin revelar la ruta absoluta del servidor',
        !str_contains($r['html'], $RAIZ . '/app'));
    comprobar('y ofrece volver al asistente', str_contains($cuerpo, 'Volver al asistente'));
} finally {
    reescribir($ruta, $original);
}

/* ---------------------------------------------------------------------
   Pasos 4 y 5: terminar
   --------------------------------------------------------------------- */

titulo('Del paso 4 al final');

$navegador->ir('/instalar?paso=4');
$r = $navegador->ir('/instalar', [
    'accion'    => 'paso4',
    'ad_nombre' => 'María Fernanda Rojas',
    'ad_correo' => 'admin.pruebas@narino.gov.co',
    'ad_clave'  => 'ClaveDePrueba2026!',
    'ad_clave2' => 'ClaveDePrueba2026!',
]);
comprobar('el paso 4 acepta la cuenta y pasa al 5', $r['codigo'] === 303 && str_contains($r['destino'], 'paso=5'),
    $r['codigo'] . ' ' . $r['destino']);
comprobar('la contraseña del administrador no viaja en la cookie del asistente',
    !str_contains(print_r($_COOKIE, true) . $r['html'], 'ClaveDePrueba2026!'));

$navegador->ir('/instalar?paso=5');
$r = $navegador->ir('/instalar', [
    'accion'         => 'paso5',
    'ev_nombre'      => 'Cumbre de Inteligencia Artificial',
    'ev_dependencia' => 'Secretaría TIC',
    'ev_sede'        => 'Pasto',
    'ev_inicio'      => date('Y-m-d', time() + 86400 * 7),
    'ev_dias'        => '3',
    'preset'         => 'tic-nocturno',
    'tipografia'     => 'tecnologica',
]);
comprobar('el paso 5 termina y lleva al resumen',
    $r['codigo'] === 303 && str_contains($r['destino'], '/instalar/listo'),
    $r['codigo'] . ' ' . $r['destino']);

$r = $navegador->ir('/instalar/listo');
comprobar('el resumen se ve después de terminar', $r['codigo'] === 200 && stripos(h1($r['html']), 'terminada') !== false,
    $r['codigo'] . ' «' . h1($r['html']) . '»');

comprobar('config/config.php ya existe', is_file($RAIZ . '/config/config.php'));
comprobar('y config/instalacion.php se borró: la contraseña no se queda duplicada',
    !is_file($RAIZ . '/config/instalacion.php'));

$conexion = pdo($BD);

/* La cuenta que se escribió en el paso 4, mirada campo por campo. Es lo que se
   quedó sin crear en producción, así que no basta con contar filas. */
$cuenta = $conexion->query("SELECT * FROM evt_usuario WHERE correo = 'admin.pruebas@narino.gov.co'")
    ->fetch(PDO::FETCH_ASSOC);

comprobar('evt_usuario tiene la cuenta que se escribió en el paso 4', is_array($cuenta));
comprobar('con el nombre tal cual se tecleó',
    ($cuenta['nombre'] ?? '') === 'María Fernanda Rojas', (string) ($cuenta['nombre'] ?? '—'));
comprobar('con rol administrador', ($cuenta['rol'] ?? '') === 'administrador', (string) ($cuenta['rol'] ?? '—'));
comprobar('activa', ($cuenta['estado'] ?? '') === 'activo', (string) ($cuenta['estado'] ?? '—'));
comprobar('sin obligación de cambiar la contraseña: la eligió quien instaló',
    (int) ($cuenta['debe_cambiar'] ?? 1) === 0);
comprobar('la contraseña quedó en hash, no en claro',
    !str_contains((string) ($cuenta['clave_hash'] ?? ''), 'ClaveDePrueba2026!'));
comprobar('y ese hash verifica la contraseña del paso 4',
    password_verify('ClaveDePrueba2026!', (string) ($cuenta['clave_hash'] ?? '')));
comprobar('el hash pide un solo hilo: la compilación de libsodium no admite más',
    !str_starts_with((string) ($cuenta['clave_hash'] ?? ''), '$argon2')
    || str_contains((string) ($cuenta['clave_hash'] ?? ''), ',p=1$'),
    (string) ($cuenta['clave_hash'] ?? '—'));

// Y que con esa cuenta se pueda entrar de verdad, que es para lo que existe.
$puerta = new Navegador($BASE);
$puerta->ir('/admin/entrar');
$r = $puerta->ir('/admin/entrar', [
    'correo' => 'admin.pruebas@narino.gov.co',
    'clave'  => 'ClaveDePrueba2026!',
]);
comprobar('con esa cuenta se entra al panel',
    $r['codigo'] === 303 && !str_contains($r['destino'], 'entrar'),
    $r['codigo'] . ' ' . $r['destino']);

$r = $puerta->ir('/admin');
comprobar('y el panel responde', $r['codigo'] === 200 || $r['codigo'] === 303, 'código ' . $r['codigo']);

comprobar('una contraseña equivocada no entra',
    (static function () use ($BASE): bool {
        $n = new Navegador($BASE);
        $n->ir('/admin/entrar');
        $x = $n->ir('/admin/entrar', ['correo' => 'admin.pruebas@narino.gov.co', 'clave' => 'otraCosaLarga2026']);
        return $x['codigo'] !== 303 || str_contains($x['destino'], 'entrar');
    })());
comprobar('evt_evento tiene el evento', (int) $conexion->query('SELECT COUNT(*) FROM evt_evento')->fetchColumn() === 1);
comprobar('evt_evento_dia tiene una jornada por día',
    (int) $conexion->query('SELECT COUNT(*) FROM evt_evento_dia')->fetchColumn() === 3);
comprobar('evt_evento_tema tiene el tema del evento',
    (int) $conexion->query('SELECT COUNT(*) FROM evt_evento_tema')->fetchColumn() === 1);
comprobar('evt_migracion anota la versión del esquema',
    (int) $conexion->query('SELECT COUNT(*) FROM evt_migracion')->fetchColumn() >= 1);
comprobar('evt_bitacora deja constancia de la instalación',
    (int) $conexion->query("SELECT COUNT(*) FROM evt_bitacora WHERE accion = 'instalacion'")->fetchColumn() >= 1);

/* ---------------------------------------------------------------------
   Terminada la instalación, se acabaron los detalles en pantalla
   --------------------------------------------------------------------- */

titulo('Instalada, el detalle técnico deja de mostrarse');

$original = (string) file_get_contents($RAIZ . '/app/Controladores/Publico.php');
$averiado = preg_replace(
    '/(public function inicio\(Peticion \$peticion\): void\s*\{)/',
    "$1\n        throw new \\RuntimeException('secreto que no debe verse');",
    $original,
    1
);
comprobar('se pudo preparar el segundo fallo artificial', is_string($averiado) && $averiado !== $original);

reescribir($RAIZ . '/app/Controladores/Publico.php', (string) $averiado);
try {
    $limpio = new Navegador($BASE);
    $r = $limpio->ir('/');
    $cuerpo = texto($r['html']);
    comprobar('responde 500', $r['codigo'] === 500, 'código ' . $r['codigo']);
    comprobar('no dice el mensaje de la excepción', !str_contains($cuerpo, 'secreto que no debe verse'));
    comprobar('ni la clase, ni el archivo, ni la traza',
        !str_contains($cuerpo, 'RuntimeException') && !str_contains($cuerpo, 'Publico.php'));
    comprobar('solo el mensaje de siempre', str_contains($cuerpo, 'Algo salió mal'));
} finally {
    reescribir($RAIZ . '/app/Controladores/Publico.php', $original);
}

/* ---------------------------------------------------------------------
   Y el asistente queda cerrado
   --------------------------------------------------------------------- */

titulo('El asistente se cierra solo');

$forastero = new Navegador($BASE);
$r = $forastero->ir('/instalar');
comprobar('/instalar ya no deja reinstalar', $r['codigo'] !== 200 || stripos($r['html'], 'paso1') === false,
    'código ' . $r['codigo']);

$r = $forastero->ir('/instalar/diagnostico');
comprobar('y el diagnóstico pasa a exigir sesión de administrador',
    $r['codigo'] === 303 || $r['codigo'] === 302 || $r['codigo'] === 403,
    'código ' . $r['codigo']);

/* ---------------------------------------------------------------------
   Terminar desde la consola una instalación cortada a mitad
   --------------------------------------------------------------------- */

titulo('«--reparar» termina lo que el asistente dejó a medias');

// El estado exacto en que se quedó producción: las tablas creadas, la tabla de
// usuarios vacía, sin config/config.php y con las credenciales del paso 2.
$conexion = pdo($BD);
$conexion->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['evt_usuario', 'evt_evento_dia', 'evt_evento_tema', 'evt_evento'] as $tabla) {
    $conexion->exec('DELETE FROM ' . $tabla);
}
$conexion->exec('SET FOREIGN_KEY_CHECKS = 1');
@unlink($RAIZ . '/config/config.php');
@unlink($RAIZ . '/config/.llave-asistente');
file_put_contents($RAIZ . '/config/instalacion.php', "<?php\nreturn " . var_export([
    'host'    => $BD['host'],
    'puerto'  => 3306,
    'nombre'  => $BD['nombre'],
    'usuario' => $BD['usuario'],
    'clave'   => $BD['clave'],
    'prefijo' => $BD['prefijo'],
], true) . ";\n");
clearstatcache();

$salida = [];
$estado = 0;
exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($RAIZ . '/herramientas/instalar.php')
    . ' --reparar --sin-2fa'
    . ' --admin-correo=' . escapeshellarg('reparada@narino.gov.co')
    . ' --admin-nombre=' . escapeshellarg('Cuenta Reparada Prueba')
    . ' --admin-clave=' . escapeshellarg('ClaveReparada2026!')
    . ' --evento=' . escapeshellarg('Evento Reparado')
    . ' --inicio=' . escapeshellarg(date('Y-m-d', time() + 86400 * 14))
    . ' --dias=2'
    . ' --url=' . escapeshellarg('https://tic.narino.gov.co/cumbreAI')
    . ' 2>&1', $salida, $estado);
$texto = implode("\n", $salida);

comprobar('termina bien', $estado === 0, "salida $estado: " . mb_substr($texto, 0, 200));
comprobar('toma la conexión de config/instalacion.php',
    str_contains($texto, 'config/instalacion.php'), $texto);
comprobar('no vuelve a crear las tablas', !str_contains($texto, 'esquema'), $texto);
comprobar('crea la cuenta que faltaba',
    (int) pdo($BD)->query("SELECT COUNT(*) FROM evt_usuario WHERE correo = 'reparada@narino.gov.co'")->fetchColumn() === 1);
comprobar('crea el evento que faltaba',
    (int) pdo($BD)->query('SELECT COUNT(*) FROM evt_evento')->fetchColumn() === 1);
comprobar('con sus dos jornadas',
    (int) pdo($BD)->query('SELECT COUNT(*) FROM evt_evento_dia')->fetchColumn() === 2);
comprobar('escribe config/config.php', is_file($RAIZ . '/config/config.php'));
comprobar('y borra config/instalacion.php', !is_file($RAIZ . '/config/instalacion.php'));

$r = (new Navegador($BASE))->ir('/admin/entrar');
comprobar('el panel vuelve a abrirse', $r['codigo'] === 200, 'código ' . $r['codigo']);

// Repetirlo no puede duplicar nada.
exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($RAIZ . '/herramientas/instalar.php')
    . ' --reparar 2>&1', $salida2, $estado2);
comprobar('repetirlo no duplica la cuenta ni el evento',
    $estado2 === 0
    && (int) pdo($BD)->query('SELECT COUNT(*) FROM evt_usuario')->fetchColumn() === 1
    && (int) pdo($BD)->query('SELECT COUNT(*) FROM evt_evento')->fetchColumn() === 1,
    implode("\n", $salida2));

echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos ? 1 : 0);
