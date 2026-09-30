<?php
/**
 * Los adjuntos del expositor: la hoja de vida y la exposición.
 *
 * A diferencia de la foto, aquí el archivo se guarda tal como llegó: no hay
 * forma de volver a generar un PDF sin cambiar el documento que la persona
 * quiso enviar. Así que todo lo que protege esta subida es la comprobación de
 * qué es el archivo de verdad, y eso es lo que se prueba aquí:
 *
 *   · que un .php con la extensión cambiada no pase por PDF;
 *   · que un ZIP cualquiera no pase por presentación, ni siquiera uno al que le
 *     metieron a mano el nombre del archivo que define un PPTX;
 *   · que la ranura de la dirección pública no pueda nombrar otra columna, que
 *     es lo que impediría leer el estado o el detalle por esa vía;
 *   · y que el nombre con el que se baja no pueda romper la cabecera HTTP.
 *
 * Uso:  php pruebas/documentos.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Documento.php';

use App\Nucleo\Documento;

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

function titulo(string $t): void
{
    echo "\n$t\n" . str_repeat('─', 58) . "\n";
}

echo "Adjuntos del expositor · App\\Nucleo\\Documento\n" . str_repeat('=', 58) . "\n";

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Este PHP no tiene la extensión zip; la prueba no puede correr.\n");
    exit(1);
}

$temporales = [];

function temporal(string $extension): string
{
    global $temporales;
    $ruta = tempnam(sys_get_temp_dir(), 'doc') . '.' . $extension;
    $temporales[] = $ruta;
    return $ruta;
}

/** Un PDF mínimo pero de verdad: con la cabecera que mira libmagic. */
function pdf(string $texto = 'Hoja de vida de prueba'): string
{
    $ruta = temporal('pdf');
    file_put_contents($ruta, "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Count 0>>endobj\n"
        . "% $texto\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n");
    return $ruta;
}

/**
 * Un ZIP a medida, para poder construir los casos que no son presentaciones.
 *
 * @param array<string,string> $entradas
 */
function zip(array $entradas, string $extension = 'pptx'): string
{
    $ruta = temporal($extension);
    @unlink($ruta);
    $z = new ZipArchive();
    $z->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entradas as $nombre => $contenido) {
        $z->addFromString($nombre, $contenido);
    }
    $z->close();
    return $ruta;
}

/** El índice que declara una presentación, como lo escribe PowerPoint. */
function indicePptx(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType='
        . '"application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '</Types>';
}

function pptx(): string
{
    return zip([
        '[Content_Types].xml'  => indicePptx(),
        '_rels/.rels'          => '<Relationships/>',
        'ppt/presentation.xml' => '<p:presentation><p:sldIdLst/></p:presentation>',
        'ppt/slides/slide1.xml' => '<p:sld/>',
    ]);
}

/** Llama al detector de formato, que es privado a propósito. */
function formato(string $ruta): ?string
{
    $metodo = new ReflectionMethod(Documento::class, 'formatoReal');
    $metodo->setAccessible(true);
    return $metodo->invoke(null, $ruta);
}

/** Imita $_FILES. */
function subida(string $ruta, int $error = UPLOAD_ERR_OK, ?int $peso = null): array
{
    return [
        'name'     => basename($ruta),
        'type'     => 'application/pdf',
        'tmp_name' => $ruta,
        'error'    => $error,
        'size'     => $peso ?? (is_file($ruta) ? (int) filesize($ruta) : 0),
    ];
}

function validar(array $archivo, string $clase): ?string
{
    $metodo = new ReflectionMethod(Documento::class, 'validarSubida');
    $metodo->setAccessible(true);
    try {
        $metodo->invoke(null, $archivo, Documento::CLASES[$clase]);
        return null;
    } catch (\DomainException $e) {
        return $e->getMessage();
    }
}

/* =========================================================================
   Qué es el archivo de verdad
   ========================================================================= */
titulo('El formato lo decide el contenido, no la extensión');

comprobar('un PDF de verdad se reconoce como PDF', formato(pdf()) === 'pdf');
comprobar('un PPTX bien armado se reconoce como PPTX', formato(pptx()) === 'pptx');

$php = temporal('pdf');
file_put_contents($php, "<?php system(\$_GET['c']); ?>\n");
comprobar('un .php renombrado a .pdf se rechaza', formato($php) === null);

$html = temporal('pdf');
file_put_contents($html, "<html><body><script>alert(1)</script></body></html>");
comprobar('un HTML con nombre de PDF se rechaza', formato($html) === null);

$jpg = temporal('pdf');
file_put_contents($jpg, "\xFF\xD8\xFF\xE0" . str_repeat('x', 200));
comprobar('una imagen con nombre de PDF se rechaza', formato($jpg) === null);

$vacio = temporal('pdf');
file_put_contents($vacio, '');
comprobar('un archivo vacío se rechaza', formato($vacio) === null);

// El truco más obvio: %PDF- en medio del archivo en vez de al principio.
$tarde = temporal('pdf');
file_put_contents($tarde, str_repeat("A", 600) . "%PDF-1.4\ntrailer<</Root 1 0 R>>\n%%EOF");
comprobar('la cabecera %PDF- tiene que estar al principio', formato($tarde) === null);

/* =========================================================================
   El ZIP que dice ser una presentación
   ========================================================================= */
titulo('Un PPTX es un ZIP, y eso es justo lo que hay que comprobar');

comprobar('un ZIP con archivos cualquiera se rechaza',
    formato(zip(['leeme.txt' => 'hola', 'foto.jpg' => 'x'])) === null);

// Este es el caso importante: alguien que sabe cómo se llama la pieza y la mete
// en un ZIP normal. Sin mirar el índice del paquete, pasaría.
comprobar('un ZIP con ppt/presentation.xml pero sin declararlo se rechaza',
    formato(zip([
        'ppt/presentation.xml' => '<p:presentation/>',
        'carga.php'            => '<?php system($_GET["c"]);',
    ])) === null);

comprobar('un ZIP que declara la presentación pero no la trae se rechaza',
    formato(zip(['[Content_Types].xml' => indicePptx()])) === null);

comprobar('un DOCX se rechaza: no es una presentación',
    formato(zip([
        '[Content_Types].xml'   => '<Types><Override PartName="/word/document.xml" ContentType='
            . '"application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml'     => '<w:document/>',
    ], 'docx')) === null);

comprobar('un XLSX se rechaza: no es una presentación',
    formato(zip([
        '[Content_Types].xml' => '<Types><Override PartName="/xl/workbook.xml" ContentType='
            . '"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        'xl/workbook.xml'     => '<workbook/>',
    ], 'xlsx')) === null);

// Del paquete se lee un solo archivo y solo si el índice dice que es pequeño.
// Un ZIP de unos kilobytes puede llevar dentro un XML de varios gigas; sin este
// tope, descomprimirlo a ciegas se lleva la memoria del servidor.
comprobar('un índice desproporcionado se rechaza sin descomprimirlo',
    formato(zip([
        '[Content_Types].xml'  => indicePptx() . str_repeat('<!-- relleno -->', 60000),
        'ppt/presentation.xml' => '<p:presentation/>',
    ])) === null);

$roto = temporal('pptx');
file_put_contents($roto, "PK\x03\x04" . str_repeat("\x00", 100));
comprobar('un ZIP corrupto se rechaza sin caerse', formato($roto) === null);

/* =========================================================================
   Peso y errores de la subida
   ========================================================================= */
titulo('El tope de peso y los errores del navegador');

// validarSubida() acaba en is_uploaded_file(), que es false para cualquier
// archivo que no llegó por HTTP. Así que «pasó el tope» aquí significa que llegó
// hasta esa última barrera: si hubiera fallado por peso, el mensaje sería otro.
// De paso queda comprobado el orden, que importa: el peso se mira antes de
// tocar el archivo.
$hastaElFinal = static fn(?string $mensaje): bool
    => $mensaje !== null && str_contains($mensaje, 'subida válida');

comprobar('un archivo dentro del tope pasa la comprobación de peso',
    $hastaElFinal(validar(subida(pdf(), UPLOAD_ERR_OK, 1024), 'hoja_vida')));

comprobar('la hoja de vida no admite más de 8 MB',
    str_contains((string) validar(subida(pdf(), UPLOAD_ERR_OK, 9 * 1024 * 1024), 'hoja_vida'),
        'más de 8 MB'));

comprobar('la exposición sí admite 9 MB',
    $hastaElFinal(validar(subida(pdf(), UPLOAD_ERR_OK, 9 * 1024 * 1024), 'exposicion')));

comprobar('la exposición no admite más de 25 MB',
    str_contains((string) validar(subida(pdf(), UPLOAD_ERR_OK, 26 * 1024 * 1024), 'exposicion'),
        'más de 25 MB'));

comprobar('el tope del servidor se explica con su valor',
    str_contains((string) validar(subida(pdf(), UPLOAD_ERR_INI_SIZE), 'hoja_vida'),
        (string) ini_get('upload_max_filesize')));

comprobar('una subida a medias se rechaza',
    validar(subida(pdf(), UPLOAD_ERR_PARTIAL), 'hoja_vida') !== null);

comprobar('sin carpeta temporal en el servidor se rechaza',
    validar(subida(pdf(), UPLOAD_ERR_NO_TMP_DIR), 'hoja_vida') !== null);

// La última barrera, y la que de verdad importa: un archivo que ya estaba en el
// disco del servidor no es una subida. Sin esto, un envío hecho a mano podría
// pedir que se «guardara» cualquier ruta que PHP sepa leer.
comprobar('un archivo que no llegó por HTTP se rechaza',
    str_contains((string) validar(subida(pdf()), 'hoja_vida'), 'subida válida'));

/* =========================================================================
   Qué formato admite cada ranura
   ========================================================================= */
titulo('Cada campo admite lo suyo y nada más');

comprobar('la hoja de vida solo admite PDF',
    Documento::CLASES['hoja_vida']['formatos'] === ['pdf']);
comprobar('la exposición admite PDF y PPTX',
    Documento::CLASES['exposicion']['formatos'] === ['pdf', 'pptx']);
comprobar('un PPTX no cabe en la hoja de vida',
    !in_array(formato(pptx()), Documento::CLASES['hoja_vida']['formatos'], true));
comprobar('un PDF cabe en las dos',
    in_array(formato(pdf()), Documento::CLASES['hoja_vida']['formatos'], true)
    && in_array(formato(pdf()), Documento::CLASES['exposicion']['formatos'], true));

comprobar('el texto de formatos se lee bien',
    Documento::formatosLegibles('hoja_vida') === 'PDF'
    && Documento::formatosLegibles('exposicion') === 'PDF o PPTX');
comprobar('el tope se muestra en megas',
    Documento::pesoLegible('hoja_vida') === '8 MB'
    && Documento::pesoLegible('exposicion') === '25 MB');
comprobar('el atributo accept nombra extensión y tipo',
    str_contains(Documento::aceptados('exposicion'), '.pptx')
    && str_contains(Documento::aceptados('exposicion'), 'presentationml.presentation'));

/* =========================================================================
   La ranura de la dirección pública
   ========================================================================= */
titulo('La ranura de la URL no puede nombrar otra columna');

comprobar('«hoja-de-vida» lleva a la hoja de vida',
    Documento::porRanura('hoja-de-vida') === 'hoja_vida');
comprobar('«exposicion» lleva a la exposición',
    Documento::porRanura('exposicion') === 'exposicion');

// Medios::documento() interpola la clase en el SELECT. Que porRanura() solo
// pueda devolver una de las dos es lo que hace que eso sea seguro.
foreach (['estado', 'detalle', 'hoja_vida', 'titulo', 'observacion', 'persona-id',
          'hoja-de-vida-', '', 'id'] as $intento) {
    comprobar('la ranura «' . $intento . '» no resuelve a ninguna columna',
        Documento::porRanura($intento) === null);
}

/* =========================================================================
   El nombre con el que se baja
   ========================================================================= */
titulo('El nombre de la descarga acaba en una cabecera HTTP');

$nombre = Documento::nombreDescarga('hoja_vida', 'María Fernanda Zambrano', 'pr7-hv-abc.pdf');
comprobar('lleva la etiqueta y el nombre de la persona',
    $nombre === 'Hoja-de-vida-Maria-Fernanda-Zambrano.pdf', $nombre);

comprobar('la exposición conserva la extensión pptx',
    Documento::nombreDescarga('exposicion', 'Ana Ruiz', 'pr7-ex-abc.pptx')
        === 'Exposicion-Ana-Ruiz.pptx');

// Lo que se guarda en la base lo escribió alguien de fuera. Si su nombre llega
// entero a Content-Disposition, una comilla o un salto de línea dejan de ser
// texto y pasan a ser estructura de la respuesta.
$sucio = Documento::nombreDescarga('hoja_vida', "Juan\r\nSet-Cookie: admin=1", 'x.pdf');
comprobar('un salto de línea en el nombre no sobrevive',
    !str_contains($sucio, "\r") && !str_contains($sucio, "\n"), $sucio);

$comillas = Documento::nombreDescarga('hoja_vida', 'Ana "la jefa" Ruiz; rm -rf /', 'x.pdf');
comprobar('las comillas y los puntos y coma tampoco',
    !str_contains($comillas, '"') && !str_contains($comillas, ';'), $comillas);

comprobar('un nombre vacío deja algo utilizable',
    preg_match('/^[A-Za-z0-9.-]+$/', Documento::nombreDescarga('hoja_vida', '', 'x.pdf')) === 1);

comprobar('un nombre solo de símbolos deja algo utilizable',
    preg_match('/^[A-Za-z0-9.-]+$/', Documento::nombreDescarga('exposicion', '@@@ ### ///', 'x.pptx')) === 1);

comprobar('un nombre larguísimo se recorta',
    strlen(Documento::nombreDescarga('exposicion', str_repeat('Wenceslao ', 60), 'x.pptx')) <= 100);

comprobar('la extensión se limpia igual que el resto',
    !str_contains(Documento::nombreDescarga('hoja_vida', 'Ana', 'x.p"df'), '"'));

/* =========================================================================
   Borrar del disco
   ========================================================================= */
titulo('Borrar no puede salir de almacen/documentos');

@mkdir(RAIZ . '/almacen/documentos', 0750, true);
$dentro = RAIZ . '/almacen/documentos/pr9999-hv-pruebadeborrado.pdf';
file_put_contents($dentro, '%PDF-1.4');
Documento::borrar(basename($dentro));
comprobar('borra el archivo que le corresponde', !is_file($dentro));

$fuera = temporal('txt');
file_put_contents($fuera, 'no me borres');
Documento::borrar('../../' . basename($fuera));
comprobar('no borra por encima de su carpeta', is_file($fuera));

Documento::borrar('');
comprobar('un nombre vacío no hace nada', true);

comprobar('el peso de un archivo que no está sale vacío',
    Documento::peso('no-existe-nada.pdf') === '');

file_put_contents($dentro, str_repeat('x', 2 * 1048576));
comprobar('el peso se redacta en megas', Documento::peso(basename($dentro)) === '2,0 MB',
    Documento::peso(basename($dentro)));
file_put_contents($dentro, str_repeat('x', 4096));
comprobar('y en kilos cuando es pequeño', Documento::peso(basename($dentro)) === '4 KB',
    Documento::peso(basename($dentro)));
@unlink($dentro);

/* =========================================================================
   Una clase que no existe
   ========================================================================= */
titulo('Un campo inventado no llega a ninguna parte');

$mensaje = null;
try {
    Documento::guardar(subida(pdf()), 'contrato', 1);
} catch (\DomainException $e) {
    $mensaje = $e->getMessage();
}
comprobar('guardar() rechaza una clase que no está en CLASES',
    $mensaje !== null && str_contains($mensaje, 'ningún campo'));

/* =========================================================================
   Limpieza
   ========================================================================= */
foreach ($temporales as $t) {
    @unlink($t);
}

echo "\n" . str_repeat('─', 58) . "\n";
echo $ok . ' comprobaciones correctas · ' . count($fallos) . " fallidas\n";
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos === [] ? 0 : 1);
