<?php
/**
 * El segundo factor, contra los vectores del RFC 6238.
 *
 * Si esto estuviera mal, ningún administrador podría entrar: el segundo factor
 * es obligatorio para ese rol y no hay forma de saltárselo desde el navegador.
 * Los vectores del apéndice B del RFC son la única comprobación que no depende
 * de la propia implementación.
 *
 * Uso:  php pruebas/totp.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Totp.php';

use App\Nucleo\Totp;

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
        echo "  ✗ $nombre" . ($extra ? "  → $extra" : '') . "\n";
    }
}

echo "Segundo factor (TOTP)\n" . str_repeat('=', 52) . "\n\n";

/* ------------------------------------------------------------------------
   Vectores del RFC 6238, apéndice B
   ------------------------------------------------------------------------
   El secreto es la cadena ASCII "12345678901234567890". El RFC publica los
   códigos de ocho dígitos; la plataforma usa seis, que son los seis últimos.
   ------------------------------------------------------------------------ */
echo "Vectores del RFC 6238\n";

$codificar = new ReflectionMethod(Totp::class, 'base32Codificar');
$codificar->setAccessible(true);
$secreto = (string) $codificar->invoke(null, '12345678901234567890');

comprobar('el secreto de prueba se codifica en base32',
    $secreto === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secreto);

foreach ([
    [59,          '287082'],
    [1111111109,  '081804'],
    [1111111111,  '050471'],
    [1234567890,  '005924'],
    [2000000000,  '279037'],
    [20000000000, '353130'],
] as [$momento, $esperado]) {
    $obtenido = Totp::codigoActual($secreto, $momento);
    comprobar(sprintf('T=%-12d → %s', $momento, $esperado), $obtenido === $esperado, $obtenido);
}

/* ------------------------------------------------------------------------
   base32 de ida y vuelta
   ------------------------------------------------------------------------ */
echo "\nCodificación base32\n";

$decodificar = new ReflectionMethod(Totp::class, 'base32Decodificar');
$decodificar->setAccessible(true);

$idaYVuelta = true;
for ($i = 1; $i <= 40; $i++) {
    $crudo = random_bytes($i);
    if ((string) $decodificar->invoke(null, (string) $codificar->invoke(null, $crudo)) !== $crudo) {
        // Con longitudes que no son múltiplo de 5 el base32 lleva relleno; lo
        // que importa es que los bytes originales estén al principio.
        $vuelta = (string) $decodificar->invoke(null, (string) $codificar->invoke(null, $crudo));
        if (!str_starts_with($vuelta, $crudo)) {
            $idaYVuelta = false;
            break;
        }
    }
}
comprobar('ida y vuelta con longitudes de 1 a 40 bytes', $idaYVuelta);

comprobar('un secreto con espacios se lee igual',
    Totp::codigoActual(Totp::formatear($secreto), 59) === '287082');
comprobar('un secreto en minúsculas también',
    Totp::codigoActual(strtolower($secreto), 59) === '287082');
// Un secreto sin una sola letra del alfabeto base32 no da código.
comprobar('un secreto sin nada aprovechable devuelve vacío',
    Totp::codigoActual('¡¡¡ !!! ¿?¿', 59) === '');
// Y uno con basura mezclada no revienta: se queda con lo que sí es base32 y
// simplemente no cuadrará con ninguna aplicación, que es lo correcto.
comprobar('un secreto con basura mezclada no revienta',
    preg_match('/^\d{6}$/', Totp::codigoActual('¡¡¡no-es-base32!!!', 59)) === 1);

/* ------------------------------------------------------------------------
   Verificación
   ------------------------------------------------------------------------ */
echo "\nVerificación\n";

$ahora = Totp::codigoActual($secreto);
comprobar('el código de ahora vale', Totp::verificar($secreto, $ahora));
comprobar('con espacios de por medio también', Totp::verificar($secreto, chunk_split($ahora, 3, ' ')));

$reflexionCodigo = new ReflectionMethod(Totp::class, 'codigo');
$reflexionCodigo->setAccessible(true);
$contador = intdiv(time(), 30);

comprobar('el del intervalo anterior vale (reloj adelantado)',
    Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador - 1)));
comprobar('el del siguiente también (reloj atrasado)',
    Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador + 1)));
comprobar('el de hace tres intervalos ya no',
    !Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador - 3)));

comprobar('un código de cinco dígitos se rechaza', !Totp::verificar($secreto, '12345'));
comprobar('un código vacío se rechaza', !Totp::verificar($secreto, ''));
comprobar('letras se rechazan', !Totp::verificar($secreto, 'abcdef'));
comprobar('el código de otro secreto no vale',
    !Totp::verificar(Totp::generarSecreto(), $ahora));

/* ------------------------------------------------------------------------
   Con el reloj del servidor corrido
   ------------------------------------------------------------------------
   Lo que pasaba en producción: el teléfono pone la hora solo, el servidor no
   siempre, y con más de un minuto de diferencia el código correcto se
   rechazaba para siempre. Ahora el desfase se detecta, se confirma con el
   código siguiente y queda aprendido.
   ------------------------------------------------------------------------ */
echo "\nReloj del servidor corrido\n";

$s = Totp::generarSecreto();
$servidor = 1_790_000_000;                      // un instante fijo: la prueba no depende de cuándo corre
$c = intdiv($servidor, 30);
$telefono = static fn(int $desfase): string => Totp::codigoActual($s, $servidor + $desfase);

$r = Totp::evaluar($s, $telefono(0), 0, 0, null, $servidor);
comprobar('en hora, entra', $r['estado'] === 'ok' && $r['deriva'] === 0, json_encode($r));

$r = Totp::evaluar($s, $telefono(0), 0, $c, null, $servidor);
comprobar('el mismo código otra vez: «repetido», no «no coincide»', $r['estado'] === 'repetido', json_encode($r));

$r = Totp::evaluar($s, $telefono(-30), 0, $c, null, $servidor);
comprobar('el del intervalo anterior al último usado también es repetido', $r['estado'] === 'repetido', json_encode($r));

// El servidor va cinco horas atrasado: el reloj puesto en hora local como si
// fuera UTC, que en Colombia son exactamente cinco horas.
$r = Totp::evaluar($s, $telefono(5 * 3600), 0, 0, null, $servidor);
comprobar('con cinco horas de desfase, pide confirmar en vez de rechazar',
    $r['estado'] === 'confirmar' && $r['deriva'] === 600, json_encode($r));
$pendiente = ['intervalo' => $r['intervalo'], 'deriva' => $r['deriva'], 'hasta' => $servidor + 300];

$r2 = Totp::evaluar($s, $telefono(5 * 3600 + 35), 0, 0, $pendiente, $servidor + 35);
comprobar('con el código siguiente, entra', $r2['estado'] === 'ok' && $r2['deriva'] === 600, json_encode($r2));

$r3 = Totp::evaluar($s, $telefono(5 * 3600), 0, 0, $pendiente, $servidor + 5);
comprobar('el mismo primer código no sirve de segundo', $r3['estado'] !== 'ok', json_encode($r3));

$vencido = ['deriva' => 600, 'intervalo' => $r['intervalo'], 'hasta' => $servidor - 1];
$r3 = Totp::evaluar($s, $telefono(5 * 3600 + 35), 0, 0, $vencido, $servidor + 35);
comprobar('pasado el plazo, el segundo código vuelve a pedir confirmación', $r3['estado'] === 'confirmar', json_encode($r3));

$r4 = Totp::evaluar($s, $telefono(5 * 3600 + 120), 600, $r2['intervalo'], null, $servidor + 120);
comprobar('con el desfase aprendido, la vez siguiente entra a la primera', $r4['estado'] === 'ok', json_encode($r4));

$r5 = Totp::evaluar($s, $telefono(0), 600, 0, null, $servidor);
comprobar('si arreglan el reloj del servidor, el código en hora sigue entrando', $r5['estado'] === 'ok', json_encode($r5));

// Unos segundos de más, que antes también fallaban siempre.
$r6 = Totp::evaluar($s, $telefono(75), 0, 0, null, $servidor);
comprobar('75 segundos de desfase se confirman en vez de fallar', $r6['estado'] === 'confirmar', json_encode($r6));

// El teléfono iba adelantado, se entró así, y después lo pusieron en hora.
$ultimoAdelantado = $c + 20;
$r7 = Totp::evaluar($s, $telefono(0), 20, $ultimoAdelantado, null, $servidor);
comprobar('un teléfono que se puso en hora no queda bloqueado: pide confirmar',
    $r7['estado'] === 'confirmar' && !empty($r7['retroceso']), json_encode($r7));
$r8 = Totp::evaluar($s, $telefono(30), 20, $ultimoAdelantado,
    ['intervalo' => $r7['intervalo'], 'deriva' => $r7['deriva'], 'hasta' => $servidor + 300, 'retroceso' => true],
    $servidor + 30);
comprobar('y con el código siguiente entra, por debajo del último aceptado',
    $r8['estado'] === 'ok' && !empty($r8['retroceso']), json_encode($r8));

// Pero un código viejo, visto hace diez minutos, no se convierte en entrada.
$r9 = Totp::evaluar($s, $telefono(-600), 0, $c, null, $servidor);
comprobar('un código de hace diez minutos, ya superado, no sirve', $r9['estado'] === 'no', json_encode($r9));

$r10 = Totp::evaluar($s, $telefono(0), 0, $c + 100000, null, $servidor);
comprobar('un último aceptado imposible —más allá de la búsqueda— no bloquea', $r10['estado'] === 'ok', json_encode($r10));

$r11 = Totp::evaluar($s, $telefono(13 * 3600), 0, 0, null, $servidor);
comprobar('más allá de doce horas ya no se busca', $r11['estado'] === 'no', json_encode($r11));

$inicio = microtime(true);
Totp::evaluar($s, '000000', 0, 0, null, $servidor);
$ms = (microtime(true) - $inicio) * 1000;
comprobar('recorrer las doce horas a cada lado es rápido (< 100 ms)', $ms < 100, round($ms, 1) . ' ms');

[$cuanto, $hacia] = Totp::describirDeriva(600);
comprobar('el desfase se describe en palabras', $cuanto === '5 horas' && $hacia === 'atrasado', "$cuanto $hacia");
[$cuanto, $hacia] = Totp::describirDeriva(-8);
comprobar('y hacia el otro lado', $cuanto === '4 minutos' && $hacia === 'adelantado', "$cuanto $hacia");

/* ------------------------------------------------------------------------
   El URI que va en el QR de alta
   ------------------------------------------------------------------------ */
echo "\nURI de alta\n";

$uri = Totp::uri($secreto, 'aerazo@narino.gov.co', 'Cumbre Tecnológica CIOS Nariño');
comprobar('empieza por otpauth://totp/', str_starts_with($uri, 'otpauth://totp/'));
comprobar('lleva el secreto sin transformar', str_contains($uri, 'secret=' . $secreto));
comprobar('lleva el emisor escapado', str_contains($uri, 'issuer=Cumbre%20Tecnol%C3%B3gica%20CIOS%20Nari%C3%B1o'));
comprobar('declara algoritmo, dígitos y periodo',
    str_contains($uri, 'algorithm=SHA1') && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'));
comprobar('la cuenta va escapada', str_contains($uri, rawurlencode('aerazo@narino.gov.co')));

$secretoNuevo = Totp::generarSecreto();
comprobar('un secreto nuevo tiene 32 caracteres', strlen($secretoNuevo) === 32, (string) strlen($secretoNuevo));
comprobar('y solo usa el alfabeto base32', (bool) preg_match('/^[A-Z2-7]+$/', $secretoNuevo));

echo "\n" . str_repeat('─', 52) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
