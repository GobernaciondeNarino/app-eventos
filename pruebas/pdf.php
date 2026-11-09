<?php
/**
 * El PDF de los certificados (3.21), por dentro.
 *
 * La plataforma escribe los PDF sin bibliotecas (App\Nucleo\Pdf, Fuente y
 * CertificadoPdf), así que aquí se comprueba lo que una biblioteca daría por
 * hecho:
 *
 *   · la fuente: los anchos, el interletrado, el paso a Windows-1252 —con lo
 *     que no cabe, transliterado— y el programa que se incrusta, con sus sumas
 *     de control;
 *   · el PNG: que los cinco filtros y los tipos de color se lean igual que los
 *     lee GD, píxel por píxel, y que lo que no se sabe leer se diga;
 *   · el archivo: que la tabla de referencias apunte a cada objeto, y que un
 *     lector de verdad (pdftotext, si está instalado) saque el texto;
 *   · el certificado: los textos de cada caso —una jornada, todas, expositor,
 *     sin ciudad, sin horas, «el evento»— y que un nombre o un título muy
 *     largos no rompan la hoja.
 *
 * Uso:  php pruebas/pdf.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }
    }
});
require RAIZ . '/app/ayudas.php';

use App\Modelos\Certificado;
use App\Nucleo\CertificadoPdf;
use App\Nucleo\Fuente;
use App\Nucleo\Pdf;

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
 * Lo que escribe cada arreglo TJ de un PDF de la plataforma, en UTF-8 y en
 * orden: una línea de texto, una palabra de un párrafo o una letra del sello.
 *
 * @return array<int, string>
 */
function piezasDe(string $pdf): array
{
    $piezas = [];
    $desde = 0;
    while (preg_match('/<<([^<>]*)>>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE, $desde)) {
        $inicio = $m[0][1] + strlen($m[0][0]);
        $largo = preg_match('/\/Length (\d+)/', $m[1][0], $l) ? (int) $l[1] : 0;
        $desde = $inicio + $largo;
        if (!str_contains($m[1][0], '/FlateDecode') || str_contains($m[1][0], '/Image') || str_contains($m[1][0], '/Length1')) {
            continue;
        }
        $contenido = @gzuncompress(substr($pdf, $inicio, $largo));
        preg_match_all('/\[((?:<[0-9a-f]*>\s*-?\d*\s*)*)\]\s*TJ/', (string) $contenido, $arreglos);
        foreach ($arreglos[1] as $arreglo) {
            preg_match_all('/<([0-9a-f]*)>/', $arreglo, $hex);
            $piezas[] = (string) mb_convert_encoding(implode('', array_map('hex2bin', $hex[1])), 'UTF-8', 'Windows-1252');
        }
    }
    return $piezas;
}

/** Todo el texto de un PDF de la plataforma, seguido. */
function textoDe(string $pdf): string
{
    return implode('', piezasDe($pdf));
}

function sinEspacios(string $texto): string
{
    return (string) preg_replace('/\s+/u', '', $texto);
}

/** Cada objeto empieza donde dice la tabla de referencias. */
function bienArmado(string $pdf): bool
{
    if (!str_starts_with($pdf, '%PDF-1.4') || !preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m)) {
        return false;
    }
    $xref = (int) $m[1];
    if (!preg_match('/^xref\n0 (\d+)\n0000000000 65535 f \n/', substr($pdf, $xref, 60), $c)) {
        return false;
    }
    $tabla = substr($pdf, $xref + strlen("xref\n0 {$c[1]}\n"), 20 * (int) $c[1]);
    for ($i = 1; $i < (int) $c[1]; $i++) {
        if (!str_starts_with(substr($pdf, (int) substr($tabla, 20 * $i, 10), 16), "$i 0 obj")) {
            return false;
        }
    }
    return str_contains($pdf, '/Root ') && str_contains($pdf, '/Type /Catalog');
}

$fuentes = RAIZ . '/app/Recursos/fuentes/';

/* =========================================================================
   La fuente
   ========================================================================= */
titulo('La fuente');

$regular = Fuente::cargar($fuentes . 'Montserrat-Regular.ttf');
$negrita = Fuente::cargar($fuentes . 'Montserrat-ExtraBold.ttf');
comprobar('se lee el nombre PostScript y las medidas', $regular->nombre === 'Montserrat-Regular'
    && $negrita->nombre === 'Montserrat-ExtraBold' && $regular->unidades === 1000
    && $regular->ascendente > 800 && $regular->descendente < -100 && $regular->altoMayusculas > 600
    && $negrita->peso === 800 && $regular->peso === 400, $regular->nombre . ' ' . $regular->ascendente);
comprobar('tiene ancho cada letra del español', count(array_filter(
    array_map(static fn(string $l): int => $regular->anchos[ord(Fuente::aCp1252($l))] ?? 0,
        ['a', 'á', 'é', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'ü', 'Ü', '¿', '¡', '«', '»', '—', '€', 'º']),
    static fn(int $a): bool => $a > 0
)) === 17);
comprobar('la negrita es más ancha que la regular', $negrita->ancho('MARÍA', 20) > $regular->ancho('MARÍA', 20));
comprobar('el ancho escala con el tamaño', abs($regular->ancho('Pasto', 20) - 2 * $regular->ancho('Pasto', 10)) < 0.001);
$av = $negrita->kern[ord('A')][ord('V')] ?? 0;
comprobar('el interletrado de la fuente: «AV» se junta', $av < 0
    && $negrita->ancho('AV', 10) < $negrita->ancho('A', 10) + $negrita->ancho('V', 10), (string) $av);
comprobar('y se puede apagar al medir', abs($negrita->ancho('AV', 10, 0, false)
    - ($negrita->ancho('A', 10) + $negrita->ancho('V', 10))) < 0.001);
comprobar('el espaciado se suma entre letras, no después de la última',
    abs($regular->ancho('abc', 10, 2.0) - ($regular->ancho('abc', 10) + 4.0)) < 0.001);

titulo('De UTF-8 a Windows-1252');
comprobar('el español pasa entero', Fuente::aCp1252('Ñandú «Kamëntšá» — 15 € ¿sí?')
    === "\xD1and\xFA \xABKam\xEBnt\x9A\xE1\xBB \x97 15 \x80 \xBFs\xED?");
comprobar('las letras con el acento aparte se juntan', Fuente::aCp1252("Jose\u{0301} Mun\u{0303}oz") === "Jos\xE9 Mu\xF1oz");
comprobar('lo que no está en la tabla se translitera, y lo que sí está se conserva', Fuente::aCp1252('Łukasz Dvořák') === "Lukasz Dvor\xE1k",
    bin2hex(Fuente::aCp1252('Łukasz Dvořák')));
comprobar('lo que no tiene equivalente queda como «?»', Fuente::aCp1252('Ana 🎉') === 'Ana ?');
comprobar('los saltos de línea y los controles son espacios', Fuente::aCp1252("Uno\nDos\tTres\x01") === 'Uno Dos Tres ');

titulo('El programa que se incrusta');
$programa = $regular->programa();
$cuantas = unpack('n', $programa, 4)[1];
$etiquetas = [];
for ($i = 0; $i < $cuantas; $i++) {
    $etiquetas[] = substr($programa, 12 + 16 * $i, 4);
}
$suma = 0;
$relleno = $programa . str_repeat("\0", (4 - strlen($programa) % 4) % 4);
foreach (unpack('N*', $relleno) as $palabra) {
    $suma = ($suma + $palabra) & 0xFFFFFFFF;
}
comprobar('lleva las tablas que pide el PDF, en orden, y no el interletrado',
    $etiquetas === array_values(array_filter(['OS/2', 'cmap', 'cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post', 'prep'],
        static fn(string $t): bool => in_array($t, $etiquetas, true)))
    && in_array('glyf', $etiquetas, true) && in_array('loca', $etiquetas, true) && !in_array('kern', $etiquetas, true),
    implode(',', $etiquetas));
comprobar('y la suma de control de todo el archivo es la de la especificación', $suma === 0xB1B0AFBA, dechex($suma));
comprobar('pesa menos que el archivo, que trae el interletrado', strlen($programa) < filesize($fuentes . 'Montserrat-Regular.ttf') * 0.6,
    strlen($programa) . ' de ' . filesize($fuentes . 'Montserrat-Regular.ttf'));

/* =========================================================================
   PNG
   ========================================================================= */
titulo('PNG: los cinco filtros y los tipos de color');

/** Una imagen con de todo: degradados, ruido y bordes, para que libpng use todos los filtros. */
function imagenVariada(int $ancho, int $alto, bool $alfa): \GdImage
{
    mt_srand(7);
    $im = imagecreatetruecolor($ancho, $alto);
    imagealphablending($im, false);
    imagesavealpha($im, $alfa);
    for ($y = 0; $y < $alto; $y++) {
        for ($x = 0; $x < $ancho; $x++) {
            $r = ($x * 3) % 256;
            $g = ($y * 5 + ($y % 7 === 0 ? mt_rand(0, 255) : 0)) % 256;
            $b = ($x * $y) % 256;
            $a = $alfa ? (int) (($x + $y) % 128) : 0;
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, $a));
        }
    }
    return $im;
}

function comoPng(\GdImage $im, int $filtros = PNG_ALL_FILTERS): string
{
    ob_start();
    imagepng($im, null, 6, $filtros);
    return (string) ob_get_clean();
}

function igualQueGd(\GdImage $im, array $png, bool $conAlfa): bool
{
    $ancho = imagesx($im);
    for ($y = 0; $y < imagesy($im); $y += 3) {
        for ($x = 0; $x < $ancho; $x += 2) {
            $c = imagecolorat($im, $x, $y);
            if (!imageistruecolor($im)) {
                $c = imagecolorsforindex($im, $c);
                $c = ($c['alpha'] << 24) | ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
            }
            $i = ($y * $ancho + $x) * 3;
            if (substr($png['pixeles'], $i, 3) !== chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF)) {
                return false;
            }
            if ($conAlfa) {
                // GD guarda 0 (opaco) a 127; el PNG, 255 (opaco) a 0.
                $esperado = 255 - (int) round((($c >> 24) & 0x7F) * 255 / 127);
                if (abs(ord($png['alfa'][$y * $ancho + $x]) - $esperado) > 2) {
                    return false;
                }
            }
        }
    }
    return true;
}

$variada = imagenVariada(97, 61, true);
foreach (['ninguno' => PNG_FILTER_NONE, 'resta' => PNG_FILTER_SUB, 'arriba' => PNG_FILTER_UP,
          'promedio' => PNG_FILTER_AVG, 'Paeth' => PNG_FILTER_PAETH, 'todos' => PNG_ALL_FILTERS] as $nombre => $filtro) {
    $png = Pdf::leerPng(comoPng($variada, $filtro));
    comprobar("RGBA con el filtro «{$nombre}»: igual que GD, píxel por píxel",
        $png['ancho'] === 97 && $png['alto'] === 61 && $png['color'] === 'DeviceRGB' && $png['alfa'] !== null
        && igualQueGd($variada, $png, true));
}
$opaca = imagenVariada(64, 40, false);
$png = Pdf::leerPng(comoPng($opaca));
comprobar('RGB sin transparencia: sin máscara', $png['alfa'] === null && igualQueGd($opaca, $png, false));

$paleta = imagecreate(50, 30);
$fondoPaleta = imagecolorallocate($paleta, 250, 240, 230);
$tinta = imagecolorallocate($paleta, 16, 41, 81);
imagecolortransparent($paleta, $fondoPaleta);
imagefilledrectangle($paleta, 10, 5, 40, 25, $tinta);
$png = Pdf::leerPng(comoPng($paleta));
comprobar('paleta con color transparente: a RGB, y su transparencia a máscara',
    $png['color'] === 'DeviceRGB' && $png['alfa'] !== null
    && substr($png['pixeles'], (15 * 50 + 20) * 3, 3) === "\x10\x29\x51"
    && $png['alfa'][15 * 50 + 20] === "\xFF" && $png['alfa'][0] === "\x00");

// Un PNG en escala de grises, armado a mano: GD no los escribe.
$filas = '';
for ($y = 0; $y < 4; $y++) {
    $filas .= "\x00" . chr(10 * $y) . chr(100) . chr(200) . chr(255 - $y);
}
$trozo = static fn(string $tipo, string $datos): string => pack('N', strlen($datos)) . $tipo . $datos
    . pack('N', crc32($tipo . $datos));
$gris = "\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 4, 4, 8, 0, 0, 0, 0))
    . $trozo('IDAT', (string) gzcompress($filas)) . $trozo('IEND', '');
$png = Pdf::leerPng($gris);
comprobar('escala de grises', $png['color'] === 'DeviceGray' && $png['alfa'] === null
    && $png['pixeles'] === "\x00\x64\xC8\xFF\x0A\x64\xC8\xFE\x14\x64\xC8\xFD\x1E\x64\xC8\xFC");

// Menos de ocho bits por muestra: cada byte trae varias, y los filtros
// comparan con el byte anterior, no con el píxel.
$png = Pdf::leerPng("\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 10, 2, 1, 0, 0, 0, 0))
    . $trozo('IDAT', (string) gzcompress("\x00\xAA\x80" . "\x01\x0F\xF1")) . $trozo('IEND', ''));
comprobar('grises de 1 bit, con el filtro «resta» sobre bytes', $png['pixeles']
    === "\xFF\x00\xFF\x00\xFF\x00\xFF\x00\xFF\x00" . "\x00\x00\x00\x00\xFF\xFF\xFF\xFF\x00\x00", bin2hex($png['pixeles']));
$png = Pdf::leerPng("\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 3, 1, 4, 0, 0, 0, 0))
    . $trozo('IDAT', (string) gzcompress("\x00\x0F\x80")) . $trozo('IEND', ''));
comprobar('grises de 4 bits, estirados hasta 255', $png['pixeles'] === "\x00\xFF\x88", bin2hex($png['pixeles']));
$png = Pdf::leerPng("\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 2, 1, 16, 2, 0, 0, 0))
    . $trozo('IDAT', (string) gzcompress("\x00\x12\x34\x56\x78\x9A\xBC\xFF\x00\x00\xFF\x80\x80")) . $trozo('IEND', ''));
comprobar('color de 16 bits: el byte alto de cada muestra', $png['pixeles'] === "\x12\x56\x9A\xFF\x00\x80", bin2hex($png['pixeles']));
$paleta2 = imagecreate(9, 3);
$claro = imagecolorallocate($paleta2, 255, 250, 240);
$oscuro = imagecolorallocate($paleta2, 16, 41, 81);
imagesetpixel($paleta2, 4, 1, $oscuro);
$png = Pdf::leerPng(comoPng($paleta2));
comprobar('paleta de dos colores, que GD escribe con 1 bit', substr($png['pixeles'], (9 + 4) * 3, 3) === "\x10\x29\x51"
    && substr($png['pixeles'], 0, 3) === "\xFF\xFA\xF0" && strlen($png['pixeles']) === 9 * 3 * 3);

$entrelazado = "\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 4, 4, 8, 0, 0, 0, 1))
    . $trozo('IDAT', (string) gzcompress($filas)) . $trozo('IEND', '');
try {
    Pdf::leerPng($entrelazado);
    $rechazo = '';
} catch (\RuntimeException $e) {
    $rechazo = $e->getMessage();
}
comprobar('un PNG entrelazado se rechaza diciendo por qué', str_contains($rechazo, 'sin entrelazar'), $rechazo);
try {
    Pdf::leerPng("\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 4, 4, 8, 2, 0, 0, 0)) . $trozo('IEND', ''));
    $rechazo = '';
} catch (\RuntimeException $e) {
    $rechazo = $e->getMessage();
}
comprobar('uno sin datos, también', str_contains($rechazo, 'incompleto'), $rechazo);
try {
    Pdf::leerPng("\x89PNG\r\n\x1A\n" . $trozo('IHDR', pack('NNCCCCC', 4, 4, 4, 2, 0, 0, 0))
        . $trozo('IDAT', (string) gzcompress(str_repeat("\x00", 40))) . $trozo('IEND', ''));
    $rechazo = '';
} catch (\RuntimeException $e) {
    $rechazo = $e->getMessage();
}
comprobar('y uno con una profundidad que la norma no admite (color de 4 bits)', str_contains($rechazo, 'profundidad'), $rechazo);

/* =========================================================================
   El archivo
   ========================================================================= */
titulo('El archivo');

$tmp = sys_get_temp_dir() . '/evtic-pdf-' . getmypid();
@mkdir($tmp);
$jpg = $tmp . '/fondo.jpg';
imagejpeg(imagenVariada(240, 180, false), $jpg, 80);
$pngAlfa = $tmp . '/recuadro.png';
imagepng(imagenVariada(120, 30, true), $pngAlfa);

$pdf = new Pdf(...Pdf::CARTA_HORIZONTAL);
$pdf->metadatos(['Title' => 'Certificado de asistencia — Ñandú Pérez', 'Author' => 'Gobernación de Nariño']);
$pdf->fuente('r', $fuentes . 'Montserrat-Regular.ttf');
$pdf->fuente('n', $fuentes . 'Montserrat-Bold.ttf');
$pdf->fuente('sinusar', $fuentes . 'Montserrat-SemiBold.ttf');
$pdf->imagen('fondo', $jpg);
$cache = $tmp . '/recuadro.cache';
$pdf->imagen('recuadro', $pngAlfa, $cache);
$pdf->dibujarImagen('fondo', 0, 0, 792, 612);
$pdf->dibujarImagen('recuadro', 100, 100, 400, 100);
$ancho = $pdf->texto('n', 20, 396, 150, 'MARÍA FERNANDA', ['alinear' => 'centro', 'color' => '#102951', 'espaciado' => 0.5]);
$alto = $pdf->parrafo([['fuente' => 'r', 'texto' => 'asistió a la jornada del 13 de noviembre de 2026 de la '],
    ['fuente' => 'n', 'texto' => 'Cumbre Tecnológica IA Nariño 2026'], ['fuente' => 'r', 'texto' => ', en San Juan de Pasto.']],
    116, 200, 300, 12, 17);
$pdf->rectangulo(10, 10, 50, 20, '#FFFFFF', '#000000', 1, 4);
$pdf->circulo(400, 400, 30, '#F7C531');
$pdf->guardar();
$pdf->opacidad(0.3);
$pdf->textoGirado('n', 30, 200, 500, 25, 'EJEMPLO');
$pdf->restaurar();
$salida = $pdf->salida();
comprobar('la tabla de referencias apunta a cada objeto', bienArmado($salida));
comprobar('las fuentes usadas van incrustadas y la que no se usó, no', substr_count($salida, '/FontFile2') === 2
    && str_contains($salida, '+Montserrat-Regular') && str_contains($salida, '+Montserrat-Bold')
    && !str_contains($salida, 'Montserrat-SemiBold'));
comprobar('el JPEG va tal cual y el PNG con su máscara', str_contains($salida, '/DCTDecode')
    && str_contains($salida, '/SMask') && str_contains($salida, file_get_contents($jpg)));
comprobar('la transparencia pedida, como estado gráfico', str_contains($salida, '/CA 0.30 /ca 0.30'));
comprobar('los metadatos en UTF-16, que admite cualquier letra', str_contains($salida, '/Title <FEFF')
    && str_contains($salida, strtoupper(bin2hex((string) mb_convert_encoding('Ñandú', 'UTF-16BE', 'UTF-8')))));
$texto = textoDe($salida);
comprobar('el texto se escribe con su interletrado y se puede leer de vuelta', str_contains($texto, 'MARÍA FERNANDA')
    && str_contains(sinEspacios($texto), sinEspacios('Cumbre Tecnológica IA Nariño 2026')) && str_contains($texto, 'EJEMPLO'));
comprobar('el párrafo se corta en renglones del ancho pedido', $alto >= 3 * 17 && $alto <= 5 * 17, (string) $alto);
comprobar('la línea centrada mide lo que dice la fuente, con su espaciado', abs($ancho
    - Fuente::cargar($fuentes . 'Montserrat-Bold.ttf')->ancho(Fuente::aCp1252('MARÍA FERNANDA'), 20, 0.5)) < 0.001);
comprobar('el PNG preparado queda guardado para la próxima vez', is_file($cache));
$t = microtime(true);
(new Pdf(100, 100))->imagen('otra', $pngAlfa, $cache);
comprobar('y la segunda vez se lee de ahí', microtime(true) - $t < 0.05);
file_put_contents($cache, serialize(['huella' => 'otra', 'ancho' => 1, 'alto' => 1, 'color' => 'DeviceRGB', 'pixeles' => '', 'alfa' => null]));
$otra = new Pdf(100, 100);
[$a, $b] = $otra->imagen('otra', $pngAlfa, $cache);
comprobar('si la caché es de otra imagen, se descarta', $a === 120 && $b === 30);

if (trim((string) shell_exec('command -v pdftotext 2>/dev/null')) !== '') {
    file_put_contents($tmp . '/prueba.pdf', $salida);
    $leido = (string) shell_exec('pdftotext ' . escapeshellarg($tmp . '/prueba.pdf') . ' - 2>&1');
    comprobar('un lector de verdad (pdftotext) lo abre y saca el texto con tildes', str_contains($leido, 'MARÍA FERNANDA')
        && str_contains($leido, 'Cumbre Tecnológica') && !str_contains($leido, 'Syntax Error'), mb_substr($leido, 0, 120));
} else {
    echo "  · pdftotext no está instalado: se omite la lectura con un lector de verdad\n";
}

/* =========================================================================
   El certificado
   ========================================================================= */
titulo('Los textos del certificado');

$config = [
    'nombre_evento' => 'la Cumbre Tecnológica IA Nariño 2026', 'entidad' => Certificado::ENTIDAD_DE_FABRICA,
    'ciudad' => 'San Juan de Pasto', 'horas' => [1 => 8.0, 2 => 8.0], 'expositores' => true, 'color' => '#102951',
    'firma_nombre' => '', 'firma_cargo' => '', 'firma_imagen' => '', 'fondo_imagen' => '', 'texto_imagen' => '',
];
$evento = ['id' => 1, 'nombre' => 'Cumbre Tecnológica IA Nariño 2026', 'dependencia' => 'Secretaría TIC'];
$persona = ['id' => 1, 'nombre' => "  maría   fernanda\tzambrano ", 'tipo_documento' => 'CC', 'evento_id' => 1];
$jornadas = static fn(array $asistio, array $horas = [8.0, 8.0], array $fechas = ['2026-11-13', '2026-11-14']): array => [
    'jornadas'  => array_map(static fn(int $i): array => ['id' => $i + 1, 'numero' => $i + 1, 'fecha' => $fechas[$i],
        'asistio' => $asistio[$i], 'horas' => $horas[$i]], array_keys($fechas)),
    'asistidas' => count(array_filter($asistio)),
    'total'     => count($fechas),
    'completa'  => count($fechas) > 1 && count(array_filter($asistio)) === count($fechas),
    'horas'     => array_sum(array_map(static fn(bool $a, float $h): float => $a ? $h : 0.0, $asistio, $horas)),
];
$cuerpo = static fn(array $t): string => implode('', array_column($t['cuerpo'], 'texto'));
$emision = ['codigo' => 'K7M29QXRHT4P', 'emitido_en' => '2026-11-15 10:00:00'];

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true, true]), 'emision' => $emision],
    $persona, $evento, $config, '1085234567');
comprobar('las dos jornadas: asistencia completa, con las horas de las dos y el sello',
    $cuerpo($t) === 'asistió a las dos jornadas de la Cumbre Tecnológica IA Nariño 2026, que se realizó en San Juan de Pasto '
        . 'los días 13 y 14 de noviembre de 2026, con una intensidad de 16 horas.'
    && $t['completa'] && $t['sello'] === ['arriba' => 'Asistencia completa', 'centro' => '2/2', 'abajo' => 'Jornadas']
    && $t['titulo'] === 'CERTIFICADO DE ASISTENCIA', $cuerpo($t));
comprobar('el nombre, en mayúsculas y sin espacios de más; la cédula con sus puntos', $t['nombre'] === 'MARÍA FERNANDA ZAMBRANO'
    && $t['documento'] === 'CC 1.085.234.567');
comprobar('quién certifica, completado con «certifica que»',
    $t['entidad'] === 'La Gobernación de Nariño, a través de la Secretaría TIC, Innovación y Gobierno Abierto, certifica que');
comprobar('la expedición, con la ciudad y la fecha de emisión', $t['expedicion'] === 'Se expide en San Juan de Pasto el 15 de noviembre de 2026.');
comprobar('el nombre del evento va en negrita, sin el artículo', $t['cuerpo'][1] === ['texto' => 'de la ']
    && $t['cuerpo'][2] === ['texto' => 'Cumbre Tecnológica IA Nariño 2026', 'negrita' => true], json_encode($t['cuerpo'], JSON_UNESCAPED_UNICODE));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([false, true]), 'emision' => $emision],
    $persona, $evento, $config, '');
comprobar('una de dos: la jornada a la que fue, sus horas y sin sello',
    $cuerpo($t) === 'asistió a la jornada del 14 de noviembre de 2026 de la Cumbre Tecnológica IA Nariño 2026, que se realizó '
        . 'en San Juan de Pasto, con una intensidad de 8 horas.' && !$t['completa'] && $t['sello'] === null
    && $t['documento'] === '' && $t['resumen'] === 'Asistencia: 1 de 2 jornadas', $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true, true, false], [8.0, 7.5, 4.0],
    ['2026-10-30', '2026-11-02', '2026-11-03']), 'emision' => $emision], $persona, $evento, $config);
comprobar('dos de tres, en meses distintos, con media hora',
    str_starts_with($cuerpo($t), 'asistió a las jornadas del 30 de octubre y 2 de noviembre de 2026 de la Cumbre')
    && str_ends_with($cuerpo($t), 'con una intensidad de 15,5 horas.'), $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true], [1.0], ['2026-11-13']), 'emision' => $emision],
    $persona, $evento, ['nombre_evento' => '', 'ciudad' => ''] + $config);
comprobar('un evento de un día, sin nombre propio ni ciudad: «al evento…», sin sello',
    $cuerpo($t) === 'asistió al evento Cumbre Tecnológica IA Nariño 2026, que se realizó el 13 de noviembre de 2026, con una intensidad de 1 hora.'
    && !$t['completa'] && $t['expedicion'] === 'Se expide el 15 de noviembre de 2026.', $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true, true], [0.0, 0.0]), 'emision' => $emision],
    $persona, $evento, ['nombre_evento' => 'el Foro TIC'] + $config);
comprobar('sin horas no se habla de horas, y «de el» se contrae', $cuerpo($t)
    === 'asistió a las dos jornadas del Foro TIC, que se realizó en San Juan de Pasto los días 13 y 14 de noviembre de 2026.', $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true, true, true, true],
    [1.0, 1.0, 1.0, 1.0], ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02']), 'emision' => $emision], $persona, $evento, $config);
comprobar('cuatro jornadas entre dos años', str_contains($cuerpo($t), 'las cuatro jornadas')
    && str_contains($cuerpo($t), 'los días 30 y 31 de diciembre de 2026 y 1 y 2 de enero de 2027') && $t['sello']['centro'] === '4/4',
    $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'expositor', 'asistencia' => $jornadas([false, false]), 'emision' => $emision,
    'exposicion' => ['id' => 9, 'titulo' => '  IA en el agro  ', 'fecha' => '2026-11-14']], $persona, $evento, $config);
comprobar('expositor: participación como conferencista, con su exposición y el día que la presentó',
    $cuerpo($t) === 'participó como conferencista en la Cumbre Tecnológica IA Nariño 2026, que se realizó en San Juan de Pasto '
        . 'los días 13 y 14 de noviembre de 2026, con la exposición «IA en el agro», presentada el 14 de noviembre.'
    && $t['titulo'] === 'CERTIFICADO DE PARTICIPACIÓN' && $t['sello'] === null, $cuerpo($t));

$t = Certificado::redactar(['tipo' => 'asistencia', 'asistencia' => $jornadas([true, true]), 'emision' => $emision],
    $persona, $evento, ['entidad' => 'La Alcaldía de Pasto hace constar y certifica que:'] + $config);
comprobar('si quien certifica ya termina en «certifica que», no se repite', $t['entidad'] === 'La Alcaldía de Pasto hace constar y certifica que:');

comprobar('los códigos se normalizan y se rechazan los imposibles', Certificado::normalizarCodigo('k7m2 9qxr-ht4p') === 'K7M29QXRHT4P'
    && Certificado::normalizarCodigo('K7M2-9QXR-HT4') === '' && Certificado::normalizarCodigo('O0I1L7M29QXR') === ''
    && Certificado::codigoLegible('K7M29QXRHT4P') === 'K7M2-9QXR-HT4P');

titulo('La hoja');
$base = [
    'titulo' => 'CERTIFICADO DE ASISTENCIA', 'entidad' => Certificado::ENTIDAD_DE_FABRICA . ' certifica que',
    'nombre' => 'MARÍA FERNANDA ZAMBRANO', 'documento' => 'CC 1.085.234.567',
    'cuerpo' => [['texto' => 'asistió a las dos jornadas de la '], ['texto' => 'Cumbre Tecnológica IA Nariño 2026', 'negrita' => true],
                 ['texto' => ', que se realizó en San Juan de Pasto.']],
    'expedicion' => 'Se expide en San Juan de Pasto el 15 de noviembre de 2026.',
    'sello' => ['arriba' => 'Asistencia completa', 'centro' => '2/2', 'abajo' => 'Jornadas'],
    'qr' => 'https://tic.narino.gov.co/cumbreAI/v/K7M29QXRHT4P', 'codigo' => 'K7M2-9QXR-HT4P',
    'verificar' => 'tic.narino.gov.co/cumbreAI/v', 'fondo' => $jpg, 'fondoTexto' => $pngAlfa, 'cacheTexto' => $cache,
    'firma' => ['nombre' => 'Nombre de Quien Firma', 'cargo' => 'Secretario TIC', 'imagen' => $pngAlfa],
    'color' => '#102951', 'encabezado' => 'Cumbre Tecnológica IA Nariño 2026',
];
$t = microtime(true);
$completo = CertificadoPdf::generar($base);
$tiempo = microtime(true) - $t;
$texto = textoDe($completo);
comprobar('el certificado completo sale bien armado, con todo', bienArmado($completo)
    && str_contains($texto, 'CERTIFICADO DE ASISTENCIA') && str_contains($texto, 'MARÍA FERNANDA ZAMBRANO')
    && str_contains($texto, 'CC 1.085.234.567') && str_contains(sinEspacios($texto), 'ASISTENCIACOMPLETA')
    && str_contains($texto, 'K7M2-9QXR-HT4P') && str_contains($texto, 'Nombre de Quien Firma'));
comprobar('en menos de medio segundo', $tiempo < 0.5, round($tiempo * 1000) . ' ms');

$largo = CertificadoPdf::generar(['nombre' => 'MARÍA DE LOS ÁNGELES DEL PILAR ZAMBRANO BURBANO DE LA CRUZ Y ROSERO ESTUPIÑÁN',
    'cuerpo' => [['texto' => str_repeat('participó con una exposición de título muy largo sobre gobierno digital, ', 9)]]] + $base);
$textoLargo = textoDe($largo);
$nombreLargo = 'MARÍA DE LOS ÁNGELES DEL PILAR ZAMBRANO BURBANO DE LA CRUZ Y ROSERO ESTUPIÑÁN';
$renglonesNombre = array_values(array_filter(piezasDe($largo),
    static fn(string $r): bool => mb_strlen($r) > 20 && str_contains($nombreLargo, $r)));
comprobar('un nombre muy largo se parte en dos renglones parejos, y un texto muy largo se achica sin perderse',
    bienArmado($largo) && count($renglonesNombre) === 2 && implode(' ', $renglonesNombre) === $nombreLargo
    && abs(mb_strlen($renglonesNombre[0]) - mb_strlen($renglonesNombre[1])) < 12
    && substr_count(sinEspacios($textoLargo), sinEspacios('gobierno digital,')) === 9, implode(' | ', $renglonesNombre));

$sinNada = CertificadoPdf::generar(['fondo' => '', 'fondoTexto' => '', 'firma' => null, 'sello' => null, 'qr' => '',
    'documento' => ''] + $base);
$textoSin = textoDe($sinNada);
comprobar('sin fondo, recuadro, firma, sello ni QR: el marco y el nombre del evento arriba',
    bienArmado($sinNada) && !str_contains($sinNada, '/Subtype /Image') && str_contains($textoSin, 'CUMBRE TECNOLÓGICA IA NARIÑO 2026')
    && !str_contains($textoSin, 'Código de verificación') && !str_contains($textoSin, 'CC 1.085'));
$muestra = CertificadoPdf::generar(['muestra' => true] + $base);
comprobar('el ejemplo lleva la marca de agua', str_contains(textoDe($muestra), 'EJEMPLO · SIN VALIDEZ'));
comprobar('un color que no es hexadecimal de seis cifras se ignora', bienArmado(CertificadoPdf::generar(['color' => 'red; } x'] + $base)));

array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

/* ========================================================================= */
echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos ? 1 : 0);
