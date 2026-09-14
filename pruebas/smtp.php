<?php
/**
 * El cliente SMTP, contra un servidor de verdad.
 *
 * No se simula la red: se levanta un servidor SMTP en un puerto local —incluido
 * TLS con certificado autofirmado— y se habla con él. Es la única forma de
 * comprobar cosas que solo se rompen sobre el cable: las respuestas de varias
 * líneas, el punto doblado dentro del mensaje, o el segundo EHLO obligatorio
 * después de STARTTLS.
 *
 * Cada escenario reproduce una respuesta que dan de verdad Google o Microsoft,
 * y se comprueba además que la explicación que sale en pantalla sea la que
 * corresponde. De poco sirve detectar el 535 si lo que se le enseña al
 * administrador es «no se pudo enviar».
 *
 * Uso:  php pruebas/smtp.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require RAIZ . '/app/ayudas.php';

use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Autenticacion;
use App\Nucleo\CorreoApi;
use App\Nucleo\Mensajeria;
use App\Nucleo\Smtp;

Config::establecerEnMemoria(['llave_cifrado' => str_repeat('b', 64), 'depurar' => false]);
$_SERVER['SERVER_NAME'] = 'pruebas.narino.gov.co';

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    // Si alguien usa $ok como variable local en el guion, el contador global se
    // pisa y el resumen final dice «0 comprobaciones» aunque todas pasaran.
    if (!is_int($ok)) {
        fwrite(STDERR, "\nERROR EN LA PRUEBA: alguien sobreescribió \$ok, que es el contador.\n");
        exit(2);
    }
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  ✗ $nombre" . ($extra !== '' ? "  → " . mb_substr($extra, 0, 300) : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n";
}

/* =====================================================================
   Certificado autofirmado para los escenarios con TLS
   ===================================================================== */

$certificado = sys_get_temp_dir() . '/smtp-pruebas-' . getmypid() . '.pem';
exec('openssl req -x509 -newkey rsa:2048 -keyout ' . escapeshellarg($certificado)
    . ' -out ' . escapeshellarg($certificado) . ' -days 2 -nodes -subj "/CN=127.0.0.1" 2>/dev/null',
    $salidaCert, $estadoCert);
$hayTls = $estadoCert === 0 && is_file($certificado);
register_shutdown_function(static function () use ($certificado): void {
    @unlink($certificado);
});

/* =====================================================================
   Arrancar y parar el servidor de mentira
   ===================================================================== */

$puertoLibre = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $n, $t);
    $nombre = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) $nombre, strrpos((string) $nombre, ':') + 1);
};

/** @return array{0: resource, 1: array, 2: int} proceso, tuberías y puerto */
$levantar = static function (string $escenario) use ($puertoLibre, $certificado): array {
    $puerto = $puertoLibre();
    $orden = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(RAIZ . '/pruebas/apoyo/servidor-smtp.php')
        . ' ' . $puerto . ' ' . escapeshellarg($escenario) . ' ' . escapeshellarg($certificado);

    $tuberias = [];
    $proceso = proc_open($orden, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo lanzar el servidor de prueba.');
    }

    // Esperar a que diga «listo». Sin esto la prueba corre antes de que el
    // servidor esté escuchando y falla por una carrera, no por el código.
    stream_set_blocking($tuberias[1], false);
    $espera = microtime(true) + 10;
    $anuncio = '';
    while (microtime(true) < $espera && !str_contains($anuncio, 'listo')) {
        $anuncio .= (string) fread($tuberias[1], 64);
        usleep(20000);
    }

    return [$proceso, $tuberias, $puerto];
};

$bajar = static function (array $lanzado): void {
    [$proceso, $tuberias] = $lanzado;
    foreach ($tuberias as $tuberia) {
        if (is_resource($tuberia)) {
            fclose($tuberia);
        }
    }
    if (is_resource($proceso)) {
        proc_terminate($proceso);
        proc_close($proceso);
    }
};

echo "\nCliente SMTP\n";
echo str_repeat('─', 62) . "\n";
echo '  · TLS en las pruebas: ' . ($hayTls ? 'sí, con certificado autofirmado' : 'no (falta openssl en consola)') . "\n";

/* =====================================================================
   Envío correcto
   ===================================================================== */

titulo('Un envío que sale bien');

$lanzado = $levantar('sin-starttls');   // ofrece AUTH sin exigir cifrado
[$proceso, $tuberias, $puerto] = $lanzado;

// Dieciséis letras, como las que genera Google, pero inventadas. Una
// contraseña de verdad no entra en el repositorio ni siquiera como dato de
// prueba: lo que se sube a un repositorio se queda ahí para siempre.
$claveDeMentira = 'abcdefghijklmnop';

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', $claveDeMentira, 10, false);
$enviado = $cliente->enviar(
    'hosting@narino.gov.co',
    'alguien@narino.gov.co',
    "From: Eventos TIC <hosting@narino.gov.co>\r\nSubject: Prueba",
    "Hola.\n.punto al principio\nFin."
);
comprobar('el mensaje se entrega', $enviado, $cliente->error());
comprobar('sin código de error', $cliente->codigo() === '', $cliente->codigo());

$transcripcion = $cliente->transcripcion();
comprobar('la transcripción registra el saludo', str_contains($transcripcion, '220 mentira.example'));
comprobar('y el EHLO', str_contains($transcripcion, 'EHLO pruebas.narino.gov.co'));
comprobar('la contraseña NO aparece en la transcripción',
    !str_contains($transcripcion, $claveDeMentira)
    && !str_contains($transcripcion, base64_encode($claveDeMentira)),
    $transcripcion);
comprobar('en su lugar se anota que iba ahí',
    str_contains($transcripcion, '[contraseña en base64]'), $transcripcion);

$recibido = @file_get_contents(sys_get_temp_dir() . '/smtp-recibido-' . $puerto . '.txt');
comprobar('el servidor recibió el mensaje', is_string($recibido) && $recibido !== '');
comprobar('con la cabecera de asunto', is_string($recibido) && str_contains($recibido, 'Subject: Prueba'));
comprobar('la línea que empieza por punto llegó entera',
    is_string($recibido) && str_contains($recibido, "\n.punto al principio"),
    'el cliente tiene que doblar ese punto; si no, el servidor lo lee como fin de mensaje');
comprobar('y el mensaje no se cortó ahí',
    is_string($recibido) && str_contains($recibido, 'Fin.'),
    'sin doblar el punto, todo lo que va detrás se pierde');
@unlink(sys_get_temp_dir() . '/smtp-recibido-' . $puerto . '.txt');
$bajar($lanzado);

/* =====================================================================
   Credenciales rechazadas: el caso más frecuente
   ===================================================================== */

titulo('Contraseña rechazada (535-5.7.8)');

$lanzado = $levantar('auth-535');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'la-normal-no-vale', 10, false);
comprobar('no se autentica', !$cliente->comprobar());
comprobar('el código es 535', $cliente->codigo() === '535', $cliente->codigo());
comprobar('el error trae el texto del servidor',
    str_contains($cliente->error(), 'Username and Password not accepted'), $cliente->error());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación habla de la contraseña de aplicación',
    str_contains($pistas, 'contraseña de aplicación'), $pistas);
comprobar('y de que el usuario lleve el dominio',
    str_contains($pistas, 'dirección completa'), $pistas);
comprobar('y avisa de que Workspace puede tenerlas bloqueadas',
    str_contains($pistas, 'consola de administración'), $pistas);
$bajar($lanzado);

/* =====================================================================
   Google pidiendo contraseña de aplicación
   ===================================================================== */

titulo('Google pide contraseña de aplicación (534-5.7.9)');

$lanzado = $levantar('auth-534');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'x', 10, false);
comprobar('falla', !$cliente->comprobar());
comprobar('el código es 534', $cliente->codigo() === '534', $cliente->codigo());
comprobar('la respuesta de varias líneas se lee entera',
    str_contains($cliente->transcripcion(), 'InvalidSecondFactor'), $cliente->transcripcion());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación menciona la verificación en dos pasos',
    str_contains($pistas, 'verificación en dos pasos'), $pistas);
$bajar($lanzado);

/* =====================================================================
   Remitente no permitido
   ===================================================================== */

titulo('Remitente no permitido (550-5.7.1)');

$lanzado = $levantar('relay-550');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'x', 10, false);
$enviado = $cliente->enviar('otro@ajeno.example', 'alguien@narino.gov.co', 'Subject: x', 'cuerpo');
comprobar('no se envía', !$enviado);
comprobar('el código es 550', $cliente->codigo() === '550', $cliente->codigo());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación habla del alias verificado',
    str_contains($pistas, 'alias verificado'), $pistas);
$bajar($lanzado);

/* =====================================================================
   STARTTLS pedido pero no ofrecido
   ===================================================================== */

titulo('Se pide STARTTLS y el servidor no lo ofrece');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'x', 10, false);
comprobar('no se conecta', !$cliente->comprobar());
comprobar('y lo dice con las dos alternativas de puerto',
    str_contains($cliente->error(), '465') && str_contains($cliente->error(), '587'),
    $cliente->error());
$bajar($lanzado);

/* =====================================================================
   STARTTLS de verdad
   ===================================================================== */

if ($hayTls) {
    titulo('STARTTLS con cifrado real');

    $lanzado = $levantar('starttls');
    [$proceso, $tuberias, $puerto] = $lanzado;

    $cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'clave', 10, false);
    comprobar('se conecta, cifra y se autentica', $cliente->comprobar(), $cliente->error());

    $t = $cliente->transcripcion();
    comprobar('el canal quedó cifrado', str_contains($t, '[canal cifrado con TLS]'), $t);
    comprobar('se vuelve a saludar después de cifrar',
        substr_count($t, 'EHLO pruebas.narino.gov.co') === 2,
        'sin el segundo EHLO, AUTH no está anunciado y la autenticación falla');
    $bajar($lanzado);

    titulo('Un certificado que no se puede verificar');

    $lanzado = $levantar('starttls');
    [$proceso, $tuberias, $puerto] = $lanzado;

    // Ahora exigiendo verificación: el certificado es autofirmado, debe fallar.
    $cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'clave', 10, true);
    comprobar('con verificación activa, se rechaza', !$cliente->comprobar());
    $pistas = implode(' ', Correo::explicar('', $cliente->error()));
    comprobar('y se explica que se puede desactivar para un servidor interno',
        str_contains($pistas, 'certificado propio'), $pistas);
    $bajar($lanzado);

    titulo('SSL directo, como el puerto 465');

    $lanzado = $levantar('ssl');
    [$proceso, $tuberias, $puerto] = $lanzado;

    $cliente = new Smtp('127.0.0.1', $puerto, 'ssl', 'hosting@narino.gov.co', 'clave', 10, false);
    comprobar('se conecta cifrando desde el primer byte', $cliente->comprobar(), $cliente->error());
    $bajar($lanzado);
}

/* =====================================================================
   Un servidor que no responde
   ===================================================================== */

titulo('Un servidor que se queda callado');

$lanzado = $levantar('mudo');
[$proceso, $tuberias, $puerto] = $lanzado;

$comienzo = microtime(true);
$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', '', '', 2, false);
comprobar('falla en vez de colgarse', !$cliente->comprobar());
$tardanza = microtime(true) - $comienzo;
comprobar('respeta la espera configurada', $tardanza < 8, sprintf('tardó %.1f s', $tardanza));
comprobar('y dice que no respondió',
    str_contains($cliente->error(), 'no respondió') || str_contains($cliente->error(), 'cerró'),
    $cliente->error());
$bajar($lanzado);

/* =====================================================================
   Puerto cerrado
   ===================================================================== */

titulo('Puerto cerrado');

$cliente = new Smtp('127.0.0.1', $puertoLibre(), 'ninguna', '', '', 3, false);
comprobar('no se conecta', !$cliente->comprobar());
$pistas = implode(' ', Correo::explicar('', $cliente->error()));
comprobar('la explicación sugiere probar el otro puerto',
    str_contains($pistas, '465') || str_contains($pistas, '587'), $pistas);
comprobar('y menciona el cortafuegos de salida',
    str_contains($pistas, 'cortafuegos') || str_contains($pistas, 'cerrada'), $pistas);

/* =====================================================================
   La explicación siempre dice algo útil
   ===================================================================== */

titulo('Nunca se responde con un «no se pudo enviar» a secas');

foreach ([
    ['535', 'Username and Password not accepted'],
    ['534', '5.7.9 Application-specific password required'],
    ['', '5.7.14 Please log in via your web browser'],
    ['550', '5.4.5 Daily user sending limit exceeded'],
    ['421', '4.7.0 Try again later'],
    ['', 'Connection refused'],
    ['', 'un fallo que nadie ha visto nunca'],
] as [$codigo, $mensaje]) {
    $pistas = Correo::explicar($codigo, $mensaje);
    comprobar('«' . mb_substr($mensaje, 0, 42) . '…» tiene explicación', count($pistas) >= 2);
}

$pistas = implode(' ', Correo::explicar('', 'lo que sea'));
comprobar('y todas recuerdan lo del SPF del dominio', str_contains($pistas, 'SPF'), $pistas);

/* =====================================================================
   Varias direcciones: IPv4 primero, IPv6 de reserva
   ===================================================================== */

titulo('Cuando el nombre resuelve a varias direcciones');

// Un nombre que resuelve a IPv4 y a IPv6 a la vez. localhost lo hace en
// cualquier sistema: 127.0.0.1 y ::1.
$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('localhost', $puerto, 'ninguna', '', '', 5, false);
$abierto = $cliente->comprobar();
$t = $cliente->transcripcion();

comprobar('se conecta aunque una de las direcciones no sirva', $abierto, $cliente->error());
comprobar('la transcripción dice a qué dirección se conectó',
    str_contains($t, 'conectando a tcp://127.0.0.1:') || str_contains($t, 'conectando a tcp://[::1]:'),
    $t);
comprobar('y deja ver el nombre entre paréntesis, para no perderse',
    str_contains($t, '(localhost)'), $t);
$bajar($lanzado);

// El servidor de mentira escucha solo en 127.0.0.1, así que ::1 falla. Si el
// cliente probara IPv6 primero y se rindiera, esto no abriría.
titulo('El primer intento falla y se prueba el siguiente');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('localhost', $puerto, 'ninguna', '', '', 5, false);
comprobar('no se rinde con la primera dirección que falle', $cliente->comprobar(), $cliente->error());
$bajar($lanzado);

/* =====================================================================
   «Network is unreachable»: el fallo que se confunde con un puerto cerrado
   ===================================================================== */

titulo('Network is unreachable');

$pistas = implode(' ', Correo::explicar('', 'stream_socket_client(): Network is unreachable'));
comprobar('se distingue de un puerto cerrado',
    str_contains($pistas, 'no es un puerto cerrado') || str_contains($pistas, 'no encontró ruta'),
    $pistas);
comprobar('se apunta a IPv6 como causa habitual', str_contains($pistas, 'IPv6'), $pistas);
comprobar('se ofrece la casilla de solo IPv4', str_contains($pistas, 'solo IPv4'), $pistas);
comprobar('se remite al diagnóstico de red', str_contains($pistas, 'salida de red'), $pistas);
comprobar('y se recuerda que mail() local es la salida de emergencia',
    str_contains($pistas, 'mail()'), $pistas);

comprobar('«No route to host» se trata igual',
    str_contains(implode(' ', Correo::explicar('', 'No route to host')), 'IPv6'));

// Las pistas se pintan tal cual, en HTML y en la consola: los asteriscos de
// markdown salen literales y quedan feos en las dos.
$conAsteriscos = [];
foreach ([['535', 'Username and Password not accepted'], ['534', '5.7.9'], ['550', '5.7.1'],
          ['', 'Connection refused'], ['', 'Network is unreachable'], ['421', '4.7.0'],
          ['550', '5.4.5'], ['', 'lo que sea']] as [$c, $m]) {
    foreach (Correo::explicar($c, $m) as $pista) {
        if (str_contains($pista, '**')) {
            $conAsteriscos[] = mb_substr($pista, 0, 60);
        }
    }
}
comprobar('ninguna explicación lleva asteriscos de markdown',
    $conAsteriscos === [], implode(' | ', $conAsteriscos));

/* =====================================================================
   Diagnóstico de red
   ===================================================================== */

titulo('Diagnóstico de la salida de red');

$puerto = $puertoLibre();
$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puertoVivo] = $lanzado;

$red = Correo::diagnosticoDeRed('localhost', [$puertoVivo, $puerto]);

comprobar('resuelve el nombre', $red['ipv4'] !== [] || $red['ipv6'] !== [],
    json_encode($red['ipv4']) . ' / ' . json_encode($red['ipv6']));
comprobar('prueba los puertos que se le piden', count($red['intentos']) >= 2, (string) count($red['intentos']));

$abre = array_values(array_filter($red['intentos'],
    static fn(array $i): bool => $i['ok'] && $i['puerto'] === $puertoVivo));
comprobar('el puerto con servidor detrás sale como abierto', $abre !== []);

$cierra = array_values(array_filter($red['intentos'],
    static fn(array $i): bool => !$i['ok'] && $i['puerto'] === $puerto));
comprobar('el puerto sin nada detrás sale como cerrado', $cierra !== []);
comprobar('con el motivo que dio el sistema', ($cierra[0]['error'] ?? '') !== '');
comprobar('y se mide cuánto tardó', ($abre[0]['ms'] ?? -1) >= 0);

comprobar('el resumen dice algo accionable', mb_strlen($red['resumen']) > 40, $red['resumen']);
comprobar('se informa del estado de mail() en el servidor',
    isset($red['local']['mail()']) && $red['local']['mail()'] !== '');
comprobar('y de sendmail_path', isset($red['local']['sendmail_path']));
$bajar($lanzado);

titulo('Un nombre que no existe');

$red = Correo::diagnosticoDeRed('no-existe-de-verdad.invalid', [587]);
comprobar('no revienta', is_array($red));
comprobar('no encuentra direcciones', $red['ipv4'] === [] && $red['ipv6'] === []);
comprobar('y lo dice en el resumen',
    str_contains($red['resumen'], 'no se pudo resolver'), $red['resumen']);

/* =====================================================================
   El relé de la propia máquina
   -------------------------------------------------------------------------
   Es la salida cuando el proveedor bloquea la salida SMTP: 127.0.0.1 no es
   tráfico saliente. En el servidor de la Gobernación los tres puertos hacia
   internet responden «rechazado» al instante y WordPress sigue enviando desde
   ahí, lo que solo puede ser por el correo local.
   ===================================================================== */

titulo('Servidor de correo de la propia máquina');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puertoVivo] = $lanzado;

// El diagnóstico prueba 127.0.0.1 en 25, 587 y 465. Ninguno estará vivo aquí,
// pero la estructura tiene que salir igual.
$red = Correo::diagnosticoDeRed('localhost', [$puertoVivo]);

comprobar('se prueba el relé local', !empty($red['relayLocal']));
comprobar('en los tres puertos de correo',
    count($red['relayLocal'] ?? []) === 3, (string) count($red['relayLocal'] ?? []));
comprobar('cada uno con su resultado',
    isset($red['relayLocal'][0]['ok'], $red['relayLocal'][0]['puerto'], $red['relayLocal'][0]['error']));
$puertosLocales = array_column($red['relayLocal'], 'puerto');
comprobar('los puertos son 25, 587 y 465', $puertosLocales === [25, 587, 465],
    implode(', ', $puertosLocales));
$bajar($lanzado);

// Ahora con un servidor de mentira escuchando de verdad en 127.0.0.1, para
// comprobar que se detecta y que se lee su saludo.
titulo('Con el relé local respondiendo');

$falso = stream_socket_server('tcp://127.0.0.1:0', $n, $t);
$nombreFalso = stream_socket_get_name($falso, false);
$puertoFalso = (int) substr((string) $nombreFalso, strrpos((string) $nombreFalso, ':') + 1);
fclose($falso);

$lanzadoLocal = $levantar('sin-starttls');
[$procesoLocal, $tuberiasLocal, $puertoLocal] = $lanzadoLocal;

// diagnosticoDeRed prueba puertos fijos, así que aquí se comprueba la pieza
// suelta: que un puerto con servidor de correo detrás devuelva su saludo.
$numero = 0;
$texto = '';
$socket = @stream_socket_client('tcp://127.0.0.1:' . $puertoLocal, $numero, $texto, 3);
comprobar('el relé de prueba acepta la conexión', is_resource($socket), $texto);
if (is_resource($socket)) {
    stream_set_timeout($socket, 3);
    $saludo = trim((string) fgets($socket, 512));
    comprobar('y saluda como un servidor de correo',
        str_starts_with($saludo, '220'), $saludo);
    fclose($socket);
}
$bajar($lanzadoLocal);

titulo('Un bloqueo de salida se explica como tal');

$intentos = [
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 587, 'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
    ['destino' => '2607:f8b0::1',   'familia' => 'v6', 'puerto' => 587, 'ok' => false, 'ms' => 1, 'error' => 'Network is unreachable'],
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 465, 'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 25,  'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
];
$metodo = new ReflectionMethod(Correo::class, 'resumirRed');
$metodo->setAccessible(true);

$sinLocal = $metodo->invoke(null, $intentos, ['74.125.197.109'], ['2607:f8b0::1'], [
    ['puerto' => 25, 'ok' => false, 'ms' => 1, 'saludo' => '', 'error' => 'Connection refused'],
]);
comprobar('se dice que el bloqueo es a propósito',
    str_contains($sinLocal, 'a propósito') || str_contains($sinLocal, 'cortafuegos'), $sinLocal);
comprobar('y se distingue de «sin ruta» y de «filtrado»',
    str_contains($sinLocal, 'unreachable') && str_contains($sinLocal, 'filtrado'), $sinLocal);

$conLocal = $metodo->invoke(null, $intentos, ['74.125.197.109'], ['2607:f8b0::1'], [
    ['puerto' => 25, 'ok' => true, 'ms' => 1, 'saludo' => '220 servidor listo', 'error' => ''],
]);
comprobar('con relé local, se propone usarlo', str_contains($conLocal, '127.0.0.1:25'), $conLocal);
comprobar('explicando que no es tráfico saliente',
    str_contains($conLocal, 'no es tráfico saliente'), $conLocal);
comprobar('y recordando lo del dominio del remitente',
    str_contains($conLocal, 'remitente'), $conLocal);

/* =====================================================================
   «Usar solo IPv4»
   -------------------------------------------------------------------------
   La casilla existe para servidores cuyo DNS devuelve dirección IPv6 sin que
   haya ruta de salida por ahí. Se comprueba lo que de verdad importa: que con
   ella marcada NO se intente IPv6, y que sin ella IPv6 quede detrás de IPv4 y
   no delante.
   ===================================================================== */

titulo('La casilla «Usar solo IPv4»');

$direccionesDe = static function (Smtp $cliente): array {
    $m = new ReflectionMethod(Smtp::class, 'direcciones');
    $m->setAccessible(true);
    return $m->invoke($cliente);
};

// smtp.gmail.com publica A y AAAA; es el caso que motivó todo esto.
$conIpv6 = new Smtp('smtp.gmail.com', 587, 'tls', '', '', 5, true, false);
$soloV4  = new Smtp('smtp.gmail.com', 587, 'tls', '', '', 5, true, true);

$listaMixta = $direccionesDe($conIpv6);
$listaV4    = $direccionesDe($soloV4);

$esV6 = static fn(string $d): bool => str_contains($d, ':');
$esV4 = static fn(string $d): bool => filter_var($d, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

if ($listaMixta === ['smtp.gmail.com']) {
    // Sin DNS en el entorno de pruebas no se puede comprobar esta parte.
    comprobar('el DNS no resuelve aquí; se omite la comprobación de familias', true);
} else {
    comprobar('sin la casilla, se ofrecen direcciones IPv6',
        array_filter($listaMixta, $esV6) !== [], implode(', ', $listaMixta));
    comprobar('pero IPv4 va primero',
        $esV4($listaMixta[0] ?? ''), implode(', ', $listaMixta));

    comprobar('con la casilla marcada, NO hay ninguna IPv6',
        array_filter($listaV4, $esV6) === [], implode(', ', $listaV4));
    comprobar('y sigue habiendo IPv4 que probar',
        array_filter($listaV4, $esV4) !== [], implode(', ', $listaV4));
    comprobar('la lista se acorta, no se vacía',
        count($listaV4) >= 1 && count($listaV4) <= count($listaMixta),
        count($listaV4) . ' de ' . count($listaMixta));
}

// Una dirección literal no se resuelve ni se filtra: se usa tal cual.
$literalV4 = new Smtp('192.0.2.1', 587, 'tls', '', '', 5, true, true);
comprobar('una IPv4 literal pasa tal cual', $direccionesDe($literalV4) === ['192.0.2.1']);

$literalV6 = new Smtp('2001:db8::1', 587, 'tls', '', '', 5, true, true);
comprobar('una IPv6 literal se respeta aunque esté marcada la casilla',
    $direccionesDe($literalV6) === ['2001:db8::1'],
    'si alguien la escribe a mano, es a propósito');

// Y que la casilla llegue de verdad desde la configuración hasta el cliente.
titulo('La casilla llega desde la configuración hasta el cliente');

$clienteDe = static function (): Smtp {
    $m = new ReflectionMethod(Correo::class, 'cliente');
    $m->setAccessible(true);
    return $m->invoke(null);
};
$leerCampo = static function (Smtp $c, string $nombre) {
    $p = new ReflectionProperty(Smtp::class, $nombre);
    $p->setAccessible(true);
    return $p->getValue($c);
};

Config::establecerEnMemoria(['smtp_solo_ipv4' => true, 'smtp_host' => 'smtp.gmail.com']);
comprobar('con smtp_solo_ipv4 = true, el cliente lo recibe',
    $leerCampo($clienteDe(), 'soloIpv4') === true);

Config::establecerEnMemoria(['smtp_solo_ipv4' => false]);
comprobar('y con false, también', $leerCampo($clienteDe(), 'soloIpv4') === false);

// Con la casilla puesta, la transcripción no debe mencionar ninguna IPv6.
titulo('Marcada, no aparece IPv6 en la transcripción');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;
$cliente = new Smtp('localhost', $puerto, 'ninguna', '', '', 5, false, true);
$cliente->comprobar();
$t = $cliente->transcripcion();
comprobar('no se intenta ninguna dirección entre corchetes',
    !str_contains($t, 'tcp://['), $t);
$bajar($lanzado);

/* =====================================================================
   Envío por API sobre HTTPS
   -------------------------------------------------------------------------
   Es la salida cuando el cortafuegos rechaza el SMTP saliente del usuario de
   PHP y no se puede tocar: el 443 no lo bloquea ninguna regla de correo. Cada
   proveedor quiere la petición con una forma distinta, y eso es justo lo que se
   comprueba aquí contra un servidor local que responde como ellos.
   ===================================================================== */

titulo('Envío por API');

$levantarApi = static function (string $escenario) use ($puertoLibre): array {
    $puerto = $puertoLibre();
    $orden = escapeshellcmd(PHP_BINARY) . ' '
        . escapeshellarg(RAIZ . '/pruebas/apoyo/servidor-api-correo.php')
        . ' ' . $puerto . ' ' . escapeshellarg($escenario);
    $tuberias = [];
    $proceso = proc_open($orden, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    stream_set_blocking($tuberias[1], false);
    $limite = microtime(true) + 10;
    $anuncio = '';
    while (microtime(true) < $limite && !str_contains($anuncio, 'listo')) {
        $anuncio .= (string) fread($tuberias[1], 64);
        usleep(20000);
    }
    return [$proceso, $tuberias, $puerto];
};

$remitente = ['correo' => 'hosting@narino.gov.co', 'nombre' => 'Secretaría TIC'];
$claveApi = 'clave-de-mentira-para-pruebas';

foreach (['brevo' => 'ok-201', 'sendgrid' => 'ok-202', 'resend' => 'ok-201'] as $proveedor => $escenario) {
    $lanzadoApi = $levantarApi($escenario);
    [$procesoApi, $tuberiasApi, $puertoApi] = $lanzadoApi;

    $api = new CorreoApi($proveedor, $claveApi, 8, 'http://127.0.0.1:' . $puertoApi . '/enviar');
    $aceptado = $api->enviar($remitente, 'alguien@narino.gov.co', 'Asunto con ñ y tildes',
        '<p>Hola</p>', 'Hola');

    comprobar("$proveedor: acepta el envío", $aceptado, $api->error());

    $recibido = (string) @file_get_contents(sys_get_temp_dir() . '/api-correo-' . $puertoApi . '.txt');
    comprobar("$proveedor: la petición llegó", $recibido !== '');
    comprobar("$proveedor: es un POST con JSON",
        str_contains($recibido, 'POST ') && str_contains($recibido, 'application/json'), $recibido);

    // Cada proveedor quiere la clave en una cabecera distinta.
    $esperada = $proveedor === 'brevo' ? 'api-key: ' : 'Authorization: Bearer ';
    comprobar("$proveedor: la clave va en «" . trim($esperada, ' :') . '»',
        str_contains($recibido, $esperada . $claveApi), $recibido);

    // Y el cuerpo con la forma que cada uno espera.
    $campo = match ($proveedor) {
        'brevo'    => '"htmlContent"',
        'sendgrid' => '"personalizations"',
        default    => '"html"',
    };
    comprobar("$proveedor: el cuerpo usa $campo", str_contains($recibido, $campo), $recibido);
    comprobar("$proveedor: el asunto viaja con sus tildes",
        str_contains($recibido, 'Asunto con ñ y tildes'), $recibido);
    comprobar("$proveedor: la clave NO aparece en la transcripción",
        !str_contains($api->transcripcion(), $claveApi), $api->transcripcion());
    comprobar("$proveedor: y se anota que iba tachada",
        str_contains($api->transcripcion(), '[clave tachada]'), $api->transcripcion());

    @unlink(sys_get_temp_dir() . '/api-correo-' . $puertoApi . '.txt');
    $bajar($lanzadoApi);
}

titulo('Lo que responde la API cuando algo falla');

foreach ([
    ['401', 'clave inválida', 'clave de API no es válida'],
    ['400-from', 'remitente no autorizado', 'remitente no está autorizado'],
    ['422', 'dominio sin verificar', 'no está verificado'],
    ['429', 'cupo agotado', 'cupo del plan'],
    ['500', 'fallo del proveedor', 'del proveedor'],
] as [$escenario, $descripcion, $esperado]) {
    $lanzadoApi = $levantarApi($escenario);
    [$procesoApi, $tuberiasApi, $puertoApi] = $lanzadoApi;

    $api = new CorreoApi('brevo', $claveApi, 8, 'http://127.0.0.1:' . $puertoApi . '/enviar');
    $aceptado = $api->enviar($remitente, 'alguien@narino.gov.co', 'Prueba', '<p>x</p>', 'x');

    comprobar("$descripcion: no se da por enviado", !$aceptado);
    $pistas = implode(' ', CorreoApi::explicar($api->codigo(), $api->error(), 'brevo'));
    comprobar("$descripcion: se explica qué hacer", str_contains($pistas, $esperado), $pistas);

    @unlink(sys_get_temp_dir() . '/api-correo-' . $puertoApi . '.txt');
    $bajar($lanzadoApi);
}

titulo('Casos que no llegan a salir');

$api = new CorreoApi('un-proveedor-inventado', 'x', 5);
comprobar('un proveedor desconocido se rechaza antes de conectar',
    !$api->enviar($remitente, 'a@b.co', 'x', 'x', 'x'));
comprobar('y se dice cuál era', str_contains($api->error(), 'un-proveedor-inventado'), $api->error());

$api = new CorreoApi('brevo', '', 5);
comprobar('sin clave no se intenta', !$api->enviar($remitente, 'a@b.co', 'x', 'x', 'x'));
comprobar('y se dice que falta', str_contains($api->error(), 'clave'), $api->error());

comprobar('los tres proveedores están declarados',
    array_keys(CorreoApi::PROVEEDORES) === ['brevo', 'sendgrid', 'resend']);
comprobar('cada uno con URL https',
    !array_filter(CorreoApi::PROVEEDORES,
        static fn(array $p): bool => !str_starts_with($p['url'], 'https://')));
comprobar('CorreoApi::conocido reconoce los tres y rechaza otros',
    CorreoApi::conocido('brevo') && CorreoApi::conocido('sendgrid')
    && CorreoApi::conocido('resend') && !CorreoApi::conocido('otro'));

titulo('Las órdenes para levantar el bloqueo del cortafuegos');

$ordenes = Correo::ordenesDeCortafuegos();
comprobar('se generan varios pasos', count($ordenes) >= 3, (string) count($ordenes));
$todo = implode(' ', array_column($ordenes, 'orden'));
comprobar('se busca la regla existente antes de tocar nada',
    str_contains($todo, 'iptables-save | grep owner'), $todo);
comprobar('se guarda una copia', str_contains($todo, 'reglas-antes-de-correo'), $todo);
comprobar('la excepción va con --uid-owner y por encima del rechazo',
    str_contains($todo, '-I OUTPUT 1') && str_contains($todo, '--uid-owner'), $todo);
comprobar('cubre 587 y 465, no solo el 25',
    str_contains($todo, '587,465') || str_contains($todo, '465,587'), $todo);
$uidReal = function_exists('posix_geteuid') ? (string) posix_geteuid() : '';
comprobar('lleva el UID de verdad de este proceso, no un hueco',
    $uidReal === '' || str_contains($todo, '--uid-owner ' . $uidReal), $todo);
$notas = implode(' ', array_column($ordenes, 'nota'));
comprobar('y se menciona la alternativa de nftables', str_contains($notas, 'nft '), $notas);
comprobar('y ConfigServer Firewall', str_contains($notas, 'SMTP_ALLOWUSER'), $notas);


/* =====================================================================
   Autenticación: los métodos de acceso
   -------------------------------------------------------------------------
   Existen porque atar la entrada al correo dejó a todo el mundo fuera cuando
   el correo falló. Lo que se comprueba es que nunca quede el evento sin puerta
   y que cada método haga lo suyo.
   ===================================================================== */

titulo('Métodos de acceso');

Config::establecerEnMemoria(['auth_metodos' => ['correo', 'qr'], 'auth_metodo_preferido' => 'qr']);
comprobar('se leen los métodos guardados',
    Autenticacion::activos() === ['correo', 'qr'], implode(',', Autenticacion::activos()));
comprobar('y el preferido', Autenticacion::preferido() === 'qr');

Config::establecerEnMemoria(['auth_metodos' => []]);
comprobar('sin ninguno quedan tres: el evento no puede quedarse sin puerta',
    Autenticacion::activos() === ['correo', 'qr', 'clave'], implode(',', Autenticacion::activos()));

Config::establecerEnMemoria(['auth_metodos' => ['qr', 'inventado', 42]]);
comprobar('los métodos que no existen se descartan',
    Autenticacion::activos() === ['qr'], implode(',', Autenticacion::activos()));

Config::establecerEnMemoria(['auth_metodos' => ['qr'], 'auth_metodo_preferido' => 'correo']);
comprobar('un preferido que no está activo cae al primero activo',
    Autenticacion::preferido() === 'qr');

// Una configuración corrupta cae al valor de fábrica, que son las tres puertas
// que no dependen de nadie: correo, QR y contraseña. Con solo correo, una
// instalación con el envío caído se queda sin ninguna forma de entrar, que es
// el fallo que originó todo esto.
Config::establecerEnMemoria(['auth_metodos' => 'no-es-un-arreglo']);
comprobar('una configuración corrupta cae a correo, QR y contraseña',
    Autenticacion::activos() === ['correo', 'qr', 'clave'],
    implode(',', Autenticacion::activos()));


Config::establecerEnMemoria(['auth_clave_minima' => 2]);
comprobar('el mínimo de contraseña no baja de 6', Autenticacion::claveMinima() === 6);
Config::establecerEnMemoria(['auth_clave_minima' => 500]);
comprobar('ni sube de 64', Autenticacion::claveMinima() === 64);

titulo('Teléfonos, como los escribe la gente');

foreach ([
    ['3001112233', '+573001112233', 'móvil colombiano a secas'],
    ['300 111 22 33', '+573001112233', 'con espacios'],
    ['300-111-2233', '+573001112233', 'con guiones'],
    ['+57 300 111 2233', '+573001112233', 'con indicativo'],
    ['0057 3001112233', '+573001112233', 'con 0057 delante'],
    ['(300) 111-2233', '+573001112233', 'con paréntesis'],
    ['12345', '', 'demasiado corto: se descarta'],
    ['', '', 'vacío'],
] as [$entra, $sale, $descripcion]) {
    comprobar('teléfono ' . $descripcion,
        Autenticacion::telefonoInternacional($entra) === $sale,
        Autenticacion::telefonoInternacional($entra));
}

titulo('Aviso cuando solo se puede entrar por correo');

Config::establecerEnMemoria(['auth_metodos' => ['correo'], 'modo_correo' => 'registro']);
$revision = Autenticacion::revision();
$titulos = implode(' | ', array_column($revision, 'titulo'));
comprobar('se avisa del punto único de fallo',
    str_contains($titulos, 'Solo se puede entrar por correo'), $titulos);
comprobar('y se propone el QR como respaldo',
    str_contains(implode(' ', array_column($revision, 'arreglo')), 'QR'), $titulos);

Config::establecerEnMemoria(['auth_metodos' => ['whatsapp'], 'wa_proveedor' => '', 'wa_token' => '']);
$revision = Autenticacion::revision();
comprobar('un método encendido y sin configurar es bloqueante',
    array_filter($revision, static fn(array $r): bool => $r['estado'] === 'fail') !== []);

titulo('Mensajería por WhatsApp y SMS');

comprobar('los proveedores de WhatsApp están declarados',
    array_keys(Mensajeria::PROVEEDORES['whatsapp']) === ['meta', 'twilio']);
comprobar('y los de SMS', array_keys(Mensajeria::PROVEEDORES['sms']) === ['twilio', 'generico']);
comprobar('conocido() acierta',
    Mensajeria::conocido('whatsapp', 'meta') && Mensajeria::conocido('sms', 'generico')
    && !Mensajeria::conocido('whatsapp', 'generico') && !Mensajeria::conocido('otro', 'meta'));

foreach ([
    ['whatsapp', 'meta', 'ok-201', '"messaging_product"', 'Authorization: Bearer '],
    ['whatsapp', 'twilio', 'ok-201', 'whatsapp%3A', 'Authorization: Basic '],
    ['sms', 'twilio', 'ok-201', 'Body=', 'Authorization: Basic '],
    ['sms', 'generico', 'ok-201', 'text=', 'Authorization: Bearer '],
] as [$canal, $proveedor, $escenario, $enCuerpo, $enCabecera]) {
    $lanzadoApi = $levantarApi($escenario);
    [$procesoApi, $tuberiasApi, $puertoApi] = $lanzadoApi;
    $url = 'http://127.0.0.1:' . $puertoApi . '/enviar';

    $m = new Mensajeria($canal, $proveedor, 'token-de-mentira', '+573009998877', 'AC0123456789', 8, $url);
    $mandado = $m->enviar('3001112233', 'Tu código es 123456');

    comprobar("$canal/$proveedor: envía", $mandado, $m->error());
    $recibido = (string) @file_get_contents(sys_get_temp_dir() . '/api-correo-' . $puertoApi . '.txt');
    comprobar("$canal/$proveedor: el cuerpo lleva $enCuerpo", str_contains($recibido, $enCuerpo), $recibido);
    comprobar("$canal/$proveedor: autentica con «" . trim($enCabecera) . '»',
        str_contains($recibido, $enCabecera), $recibido);
    comprobar("$canal/$proveedor: el token no aparece en la transcripción",
        !str_contains($m->transcripcion(), 'token-de-mentira'), $m->transcripcion());
    @unlink(sys_get_temp_dir() . '/api-correo-' . $puertoApi . '.txt');
    $bajar($lanzadoApi);
}

titulo('Mensajería: lo que no debe intentarse');

$m = new Mensajeria('sms', 'twilio', 'x', '+57300', 'AC1', 5);
comprobar('un teléfono imposible se rechaza antes de conectar',
    !$m->enviar('12', 'hola'));
comprobar('y se explica el formato', str_contains($m->error(), 'diez dígitos'), $m->error());

$m = new Mensajeria('whatsapp', 'inventado', 'x', '', '', 5);
comprobar('un proveedor desconocido se rechaza', !$m->enviar('3001112233', 'hola'));

$m = new Mensajeria('whatsapp', 'meta', '', '', '123', 5);
comprobar('sin token no se intenta', !$m->enviar('3001112233', 'hola'));

$m = new Mensajeria('whatsapp', 'meta', 'tok', '', '', 5);
comprobar('sin identificador de número tampoco', !$m->enviar('3001112233', 'hola'));

$pistas = implode(' ', Mensajeria::explicar('400', 'template not found', 'whatsapp', 'meta'));
comprobar('se explica lo de la plantilla de WhatsApp',
    str_contains($pistas, 'plantilla aprobada'), $pistas);
comprobar('sin asteriscos de markdown', !str_contains($pistas, '**'), $pistas);
$pistas = implode(' ', Mensajeria::explicar('401', 'unauthorized', 'sms', 'twilio'));
comprobar('y lo de la credencial', str_contains($pistas, 'credencial no vale'), $pistas);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos ? 1 : 0);
