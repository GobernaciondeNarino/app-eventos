<?php
/**
 * Prueba de correo desde la consola.
 *
 * Existe por un caso muy concreto y muy real: en el servidor de la Gobernación,
 *
 *     root@servidor:~# nc -zv smtp.gmail.com 587
 *     Ncat: Connected to 74.125.197.109:587.
 *
 * conecta sin problema, y la misma conexión desde PHP responde «Connection
 * refused». La red está bien; lo que hay es un **bloqueo por usuario**. Esta
 * herramienta ejecuta la prueba desde la consola —donde el bloqueo no aplica—
 * y así separa dos preguntas que desde el navegador se confunden en una:
 *
 *   1. ¿Las credenciales sirven?           → lo responde esto.
 *   2. ¿El usuario de PHP puede salir?     → lo responde la pantalla web.
 *
 * Si aquí funciona y en la web no, no hay nada que arreglar en la aplicación:
 * hay que quitarle el bloqueo al usuario del dominio (ver --ayuda).
 *
 * Uso:
 *   php herramientas/correo.php estado
 *   php herramientas/correo.php probar
 *   php herramientas/correo.php enviar --a=alguien@narino.gov.co
 *
 * Los datos salen de config/config.php. Para probar unos distintos sin
 * guardarlos —lo recomendable con una contraseña que todavía no es definitiva—:
 *
 *   php herramientas/correo.php probar \
 *       --host=smtp.gmail.com --puerto=587 --seguridad=tls \
 *       --usuario=hosting@narino.gov.co --clave='xxxx xxxx xxxx xxxx'
 *
 * Opciones:
 *   --a=…            destinatario de la prueba de envío
 *   --host=…         --puerto=…   --seguridad=tls|ssl|ninguna
 *   --usuario=…      --clave=…    (los espacios de la clave se quitan solos)
 *   --remitente=…    por omisión, el usuario
 *   --solo-ipv4      no intentar IPv6
 *   --sin-verificar  no verificar el certificado del servidor
 *   --espera=15      segundos
 *   --guardar        si la prueba sale bien, escribe estos datos en config.php
 *
 * Con modo_correo = 'api' en config.php, «enviar --a=…» usa la API del
 * proveedor (Brevo, SendGrid o Resend) por HTTPS 443 en vez de SMTP.
 *
 * La contraseña nunca se imprime ni se registra: en la transcripción sale como
 * «[contraseña en base64]». Aun así, pasarla por la línea de órdenes la deja en
 * el historial del intérprete: conviene borrarlo después (history -c) o usar
 * --guardar una sola vez y luego trabajar sin ella.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', '3.7.1');

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

use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Smtp;

/* =====================================================================
   Argumentos y presentación
   ===================================================================== */

$argumentos = array_slice($argv, 1);
$orden = '';
$opciones = [];
foreach ($argumentos as $arg) {
    if (str_starts_with($arg, '--')) {
        [$clave, $valor] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $opciones[$clave] = $valor;
    } elseif ($orden === '') {
        $orden = $arg;
    }
}

$color = static function (string $texto, string $cual): string {
    if (getenv('NO_COLOR') !== false || !stream_isatty(STDOUT)) {
        return $texto;
    }
    return match ($cual) {
        'ok'     => "\033[32m$texto\033[0m",
        'mal'    => "\033[31m$texto\033[0m",
        'aviso'  => "\033[33m$texto\033[0m",
        'fuerte' => "\033[1m$texto\033[0m",
        'tenue'  => "\033[2m$texto\033[0m",
        default  => $texto,
    };
};

$linea = static function (string $texto = '') use ($color): void {
    echo $texto . PHP_EOL;
};
$bien = static function (string $texto) use ($linea, $color): void {
    $linea('  ' . $color('✓', 'ok') . ' ' . $texto);
};
$mal = static function (string $texto) use ($linea, $color): void {
    $linea('  ' . $color('✕', 'mal') . ' ' . $texto);
};
$aviso = static function (string $texto) use ($linea, $color): void {
    $linea('  ' . $color('▲', 'aviso') . ' ' . $texto);
};
$paso = static function (string $texto) use ($linea, $color): void {
    $linea('  ' . $color('·', 'tenue') . ' ' . $texto);
};

if ($orden === '' || isset($opciones['ayuda']) || isset($opciones['help'])) {
    preg_match('/\/\*\*(.*?)\*\//s', (string) file_get_contents(__FILE__), $m);
    foreach (explode("\n", $m[1] ?? '') as $l) {
        $linea(rtrim((string) preg_replace('/^\s*\*\s?/', '', $l)));
    }
    $linea();
    $linea($color('Si aquí funciona y en el navegador no, el bloqueo es por usuario:', 'fuerte'));
    $linea();
    $linea('  # ¿Hay una regla que rechace SMTP salvo para ciertos usuarios?');
    $linea('  iptables -L OUTPUT -n -v --line-numbers | grep -E "25|465|587"');
    $linea();
    $linea('  # ConfigServer Firewall, que es lo más común en Plesk:');
    $linea('  grep -E "^SMTP_BLOCK|^SMTP_ALLOWUSER|^SMTP_PORTS" /etc/csf/csf.conf');
    $linea('  # Para permitirlo, añade el usuario del dominio a SMTP_ALLOWUSER y:  csf -r');
    $linea();
    $linea('  # Plesk trae su propio interruptor:');
    $linea('  plesk bin server_pref --show-outgoing-messages');
    $linea();
    $linea('  # Y el usuario con el que corre PHP para este dominio:');
    $linea('  ps -o user,cmd -C php-fpm | head');
    $linea();
    exit(0);
}

$linea();
$linea($color('Plataforma de Eventos TIC · prueba de correo', 'fuerte'));
$linea();

/* =====================================================================
   De dónde salen los datos
   ===================================================================== */

Config::cargar();

$deConfig = static fn(string $clave, $porOmision) => Config::obtener($clave, $porOmision);

$host       = (string) ($opciones['host'] ?? $deConfig('smtp_host', 'smtp.gmail.com'));
$puerto     = (int) ($opciones['puerto'] ?? $deConfig('smtp_puerto', 587));
$seguridad  = (string) ($opciones['seguridad'] ?? $deConfig('smtp_seguridad', 'tls'));
$usuario    = (string) ($opciones['usuario'] ?? $deConfig('smtp_usuario', ''));
$claveCruda = (string) ($opciones['clave'] ?? $deConfig('smtp_clave', ''));
$clave      = (string) preg_replace('/\s+/', '', $claveCruda);
$remitente  = (string) ($opciones['remitente'] ?? $deConfig('correo_remitente', $usuario));
$espera     = (int) ($opciones['espera'] ?? $deConfig('smtp_espera', 15));
$soloIpv4   = isset($opciones['solo-ipv4']) || (bool) $deConfig('smtp_solo_ipv4', false);
$verificar  = !isset($opciones['sin-verificar']) && (bool) $deConfig('smtp_verificar_certificado', true);

if (!in_array($seguridad, ['tls', 'ssl', 'ninguna'], true)) {
    $mal('La seguridad debe ser tls, ssl o ninguna.');
    exit(1);
}

$linea('  Servidor    ' . $host . ':' . $puerto . '  (' . $seguridad . ')');
$linea('  Usuario     ' . ($usuario !== '' ? $usuario : '(sin autenticar)'));
$linea('  Contraseña  ' . ($clave !== '' ? str_repeat('•', min(16, strlen($clave))) . '  (' . strlen($clave) . ' caracteres)' : '(ninguna)'));
$linea('  Remitente   ' . $remitente);
$linea('  IPv6        ' . ($soloIpv4 ? 'no se intenta' : 'de reserva, después de IPv4'));
$linea('  Certificado ' . ($verificar ? 'se verifica' : 'NO se verifica'));
$linea('  Como usuario ' . (function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') . ' (uid ' . posix_geteuid() . ')'
        : get_current_user()));
$linea();

/* =====================================================================
   estado · qué se ve desde aquí
   ===================================================================== */

if ($orden === 'estado') {
    $red = Correo::diagnosticoDeRed($host, [$puerto, 587, 465, 25]);

    $linea($color('DNS', 'fuerte'));
    $linea('  IPv4: ' . ($red['ipv4'] ? implode(', ', $red['ipv4']) : 'ninguna'));
    $linea('  IPv6: ' . ($red['ipv6'] ? implode(', ', $red['ipv6']) : 'ninguna'));
    $linea();

    $linea($color('Salida hacia ' . $host, 'fuerte'));
    foreach ($red['intentos'] as $i) {
        $texto = sprintf('%-5d %-3s %-40s', $i['puerto'], $i['familia'], $i['destino']);
        $i['ok'] ? $bien($texto . ' abre · ' . $i['ms'] . ' ms') : $mal($texto . ' ' . $i['error']);
    }
    $linea();

    $linea($color('Servidor de correo de esta máquina', 'fuerte'));
    foreach ($red['relayLocal'] as $r) {
        $texto = sprintf('%-14s', '127.0.0.1:' . $r['puerto']);
        $r['ok'] ? $bien($texto . ' acepta · ' . ($r['saludo'] ?: 'sin saludo')) : $mal($texto . ' ' . $r['error']);
    }
    $linea();

    $linea($color('PHP', 'fuerte'));
    foreach ($red['local'] as $clave => $valor) {
        $linea('  ' . str_pad((string) $clave, 20) . $valor);
    }
    $linea();
    $linea($color('Conclusión', 'fuerte'));
    $linea('  ' . wordwrap($red['resumen'], 76, PHP_EOL . '  '));
    $linea();
    exit(0);
}

/* =====================================================================
   probar / enviar
   ===================================================================== */

if (!in_array($orden, ['probar', 'enviar'], true)) {
    $mal('Orden desconocida: «' . $orden . '». Usa estado, probar o enviar.');
    exit(1);
}

// Modo API: no hay conexión que probar por separado, se manda y se ve qué dice.
if ((string) Config::obtener('modo_correo', 'php') === 'api' && !isset($opciones['host'])) {
    $para = (string) ($opciones['a'] ?? '');
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $mal('En modo API hace falta --a=una@direccion.valida: la única forma de probar es enviar.');
        exit(1);
    }
    $proveedor = (string) Config::obtener('api_proveedor', 'brevo');
    $paso('Enviando por la API de ' . (\App\Nucleo\CorreoApi::PROVEEDORES[$proveedor]['nombre'] ?? $proveedor));

    $enviado = Correo::enviar($para, 'Prueba desde la consola ' . date('Y-m-d H:i:s'),
        '<p>Si lees esto, la plataforma puede enviar por la API del proveedor.</p>');

    $linea();
    $linea($color('Conversación con el proveedor', 'fuerte'));
    foreach (explode("\n", Correo::ultimaTranscripcion()) as $l) {
        $linea('  ' . $l);
    }
    $linea();

    if ($enviado) {
        $bien('Aceptado. Revisa ' . $para . ', también en no deseado.');
        $linea();
        exit(0);
    }
    $mal('Falló.');
    $linea('    ' . wordwrap(Correo::ultimoError(), 74, PHP_EOL . '    '));
    $linea();
    $linea($color('Qué hacer', 'fuerte'));
    foreach (\App\Nucleo\CorreoApi::explicar('', Correo::ultimoError(), $proveedor) as $pista) {
        $linea('  · ' . wordwrap($pista, 74, PHP_EOL . '    '));
    }
    $linea();
    exit(1);
}

$destinatario = (string) ($opciones['a'] ?? '');
if ($orden === 'enviar' && !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
    $mal('Para enviar hace falta --a=una@direccion.valida');
    exit(1);
}

$cliente = new Smtp($host, $puerto, $seguridad, $usuario, $clave, $espera, $verificar, $soloIpv4);

if ($orden === 'probar') {
    $paso('Conectando y autenticando (sin enviar nada)');
    $ok = $cliente->comprobar();
} else {
    $paso('Enviando un mensaje de prueba a ' . $destinatario);
    $cuando = date('Y-m-d H:i:s');
    $cuerpo = "Content-Type: text/plain; charset=UTF-8\r\n"
        . 'To: ' . $destinatario . "\r\n"
        . 'Subject: Prueba de correo desde la consola ' . $cuando . "\r\n"
        . 'From: ' . $remitente . "\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $remitente)[1] ?? 'localhost') . '>';
    $ok = $cliente->enviar(
        $remitente,
        $destinatario,
        $cuerpo,
        "Si lees esto, la plataforma puede enviar correo por SMTP.\n\nEnviado el $cuando "
        . "desde herramientas/correo.php."
    );
}

$linea();
$linea($color('Conversación con el servidor', 'fuerte'));
foreach (explode("\n", $cliente->transcripcion()) as $l) {
    $linea('  ' . $color($l, str_starts_with($l, '→') ? 'tenue' : 'normal'));
}
$linea();

if ($ok) {
    $bien($orden === 'probar'
        ? 'Conexión y autenticación correctas. Las credenciales sirven.'
        : 'Mensaje entregado al servidor. Revisa ' . $destinatario . ', también en no deseado.');

    if (isset($opciones['guardar'])) {
        $nuevos = [
            'modo_correo'                => 'smtp',
            'smtp_host'                  => $host,
            'smtp_puerto'                => $puerto,
            'smtp_seguridad'             => $seguridad,
            'smtp_usuario'               => $usuario,
            'smtp_clave'                 => $clave,
            'smtp_espera'                => $espera,
            'smtp_solo_ipv4'             => $soloIpv4,
            'smtp_verificar_certificado' => $verificar,
            'correo_remitente'           => $remitente,
        ];
        if (Config::escribir($nuevos + Config::todo())) {
            $bien('Guardado en config/config.php.');
        } else {
            $mal('No se pudo escribir config/config.php. Revisa los permisos de config/.');
        }
    }

    $linea();
    $aviso('Si esto funciona aquí y la pantalla web sigue fallando con «Connection refused»,');
    $linea('    el problema NO son las credenciales ni la aplicación: es un bloqueo de salida');
    $linea('    SMTP para el usuario con el que corre PHP. Mira «php herramientas/correo.php --ayuda».');
    $linea();
    exit(0);
}

$mal('Falló' . ($cliente->codigo() !== '' ? ' con código ' . $cliente->codigo() : '') . '.');
$linea('    ' . wordwrap($cliente->error(), 74, PHP_EOL . '    '));
$linea();
$linea($color('Qué hacer', 'fuerte'));
foreach (Correo::explicar($cliente->codigo(), $cliente->error()) as $pista) {
    $linea('  · ' . wordwrap($pista, 74, PHP_EOL . '    '));
}
$linea();
exit(1);
