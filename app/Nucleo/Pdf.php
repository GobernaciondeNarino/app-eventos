<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Un PDF de una página, escrito a mano.
 *
 * Para los certificados (3.21). La plataforma no usa Composer ni bibliotecas,
 * y para lo que hace falta —una imagen de fondo, otra con transparencia, texto
 * con fuentes incrustadas, unas figuras y un código QR— alcanza con esto, que
 * se lee de una sentada y no depende de nada que el Plesk no tenga.
 *
 * Las coordenadas van en puntos (1/72 de pulgada) desde la esquina de ARRIBA a
 * la izquierda, como en una página web; la conversión al sistema del PDF, que
 * cuenta desde abajo, se hace aquí dentro. En el texto, la «y» es la línea
 * base, donde se apoyan las letras.
 *
 * Imágenes: JPEG tal cual —el PDF las entiende sin tocarlas— y PNG de 8 bits
 * sin entrelazar, que es lo que deja Imagen al regenerar lo que se sube; la
 * transparencia del PNG va como máscara aparte (SMask), que es como la pide
 * el formato.
 */
final class Pdf
{
    /** @var array<int, string> el cuerpo de cada objeto, por número - 1 */
    private array $objetos = [];
    /** @var array<string, array{fuente: Fuente, recurso: string, usada: bool}> */
    private array $fuentes = [];
    /** @var array<string, array{id: int, recurso: string, ancho: int, alto: int}> */
    private array $imagenes = [];
    /** @var array<string, string> opacidad => recurso */
    private array $estados = [];
    private string $contenido = '';
    /** @var array<string, string> */
    private array $info = [];

    /** Carta horizontal: 11 × 8,5 pulgadas. */
    public const CARTA_HORIZONTAL = [792.0, 612.0];

    public function __construct(public readonly float $ancho, public readonly float $alto)
    {
    }

    /** Título, autor, asunto, palabras clave: lo que muestra el lector en «Propiedades». */
    public function metadatos(array $datos): void
    {
        foreach (['Title', 'Author', 'Subject', 'Keywords', 'Creator'] as $clave) {
            if (isset($datos[$clave]) && trim((string) $datos[$clave]) !== '') {
                $this->info[$clave] = (string) $datos[$clave];
            }
        }
    }

    /* =====================================================================
       Recursos
       ===================================================================== */

    /** Registra una fuente. Solo se incrusta si de verdad se escribe con ella. */
    public function fuente(string $clave, string $ruta): void
    {
        $this->fuentes[$clave] = [
            'fuente'  => Fuente::cargar($ruta),
            'recurso' => 'F' . (count($this->fuentes) + 1),
            'usada'   => false,
        ];
    }

    /**
     * Registra una imagen JPEG o PNG y devuelve su tamaño en píxeles.
     *
     * $cache es dónde guardar el PNG ya separado en color y transparencia:
     * hacerlo en PHP lleva unas décimas de segundo, y el mismo fondo se usa en
     * cada certificado. Si no se puede escribir, se calcula cada vez.
     *
     * @return array{0: int, 1: int}
     */
    public function imagen(string $clave, string $ruta, ?string $cache = null): array
    {
        $datos = @file_get_contents($ruta);
        if ($datos === false || $datos === '') {
            throw new \RuntimeException('No se pudo leer la imagen ' . basename($ruta) . '.');
        }

        if (str_starts_with($datos, "\xFF\xD8")) {
            $info = @getimagesizefromstring($datos);
            if ($info === false) {
                throw new \RuntimeException('La imagen ' . basename($ruta) . ' no es un JPEG válido.');
            }
            [$ancho, $alto] = [(int) $info[0], (int) $info[1]];
            $canales = (int) ($info['channels'] ?? 3);
            $espacio = match ($canales) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' };
            // Los JPEG en CMYK de Photoshop guardan los canales invertidos.
            $decodificar = $canales === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $id = $this->agregar($this->flujo(
                '/Type /XObject /Subtype /Image /Width ' . $ancho . ' /Height ' . $alto
                . ' /ColorSpace ' . $espacio . ' /BitsPerComponent 8' . $decodificar . ' /Filter /DCTDecode',
                $datos,
                false
            ));
        } elseif (str_starts_with($datos, "\x89PNG\r\n\x1A\n")) {
            $png = self::pngPreparado($datos, $ruta, $cache);
            [$ancho, $alto] = [$png['ancho'], $png['alto']];
            $mascara = '';
            if ($png['alfa'] !== null) {
                $idMascara = $this->agregar('<< /Type /XObject /Subtype /Image /Width ' . $ancho . ' /Height ' . $alto
                    . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '
                    . strlen($png['alfa']) . " >>\nstream\n" . $png['alfa'] . "\nendstream");
                $mascara = ' /SMask ' . $idMascara . ' 0 R';
            }
            $id = $this->agregar('<< /Type /XObject /Subtype /Image /Width ' . $ancho . ' /Height ' . $alto
                . ' /ColorSpace /' . $png['color'] . ' /BitsPerComponent 8 /Filter /FlateDecode' . $mascara
                . ' /Length ' . strlen($png['pixeles']) . " >>\nstream\n" . $png['pixeles'] . "\nendstream");
        } else {
            throw new \RuntimeException('La imagen ' . basename($ruta) . ' no es JPEG ni PNG.');
        }

        $this->imagenes[$clave] = [
            'id' => $id, 'recurso' => 'Im' . (count($this->imagenes) + 1), 'ancho' => $ancho, 'alto' => $alto,
        ];
        return [$ancho, $alto];
    }

    /* =====================================================================
       Dibujo
       ===================================================================== */

    public function dibujarImagen(string $clave, float $x, float $y, float $ancho, float $alto): void
    {
        $imagen = $this->imagenes[$clave] ?? null;
        if ($imagen === null) {
            throw new \LogicException('Imagen no registrada: ' . $clave);
        }
        $this->contenido .= 'q ' . self::n($ancho) . ' 0 0 ' . self::n($alto) . ' ' . self::n($x) . ' '
            . self::n($this->alto - $y - $alto) . ' cm /' . $imagen['recurso'] . " Do Q\n";
    }

    /** Rectángulo, con las esquinas redondeadas si $radio > 0. */
    public function rectangulo(float $x, float $y, float $ancho, float $alto, ?string $relleno, ?string $trazo = null,
                               float $grosor = 1.0, float $radio = 0.0): void
    {
        $y0 = $this->alto - $y - $alto;
        if ($radio <= 0) {
            $ruta = self::n($x) . ' ' . self::n($y0) . ' ' . self::n($ancho) . ' ' . self::n($alto) . " re\n";
        } else {
            $r = min($radio, $ancho / 2, $alto / 2);
            $k = 0.5523 * $r;   // el punto de control de un cuarto de círculo
            [$x1, $y1, $x2, $y2] = [$x, $y0, $x + $ancho, $y0 + $alto];
            $ruta = self::n($x1 + $r) . ' ' . self::n($y1) . " m\n"
                . self::n($x2 - $r) . ' ' . self::n($y1) . " l\n"
                . self::curva($x2 - $r + $k, $y1, $x2, $y1 + $r - $k, $x2, $y1 + $r)
                . self::n($x2) . ' ' . self::n($y2 - $r) . " l\n"
                . self::curva($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2)
                . self::n($x1 + $r) . ' ' . self::n($y2) . " l\n"
                . self::curva($x1 + $r - $k, $y2, $x1, $y2 - $r + $k, $x1, $y2 - $r)
                . self::n($x1) . ' ' . self::n($y1 + $r) . " l\n"
                . self::curva($x1, $y1 + $r - $k, $x1 + $r - $k, $y1, $x1 + $r, $y1)
                . "h\n";
        }
        $this->pintar($ruta, $relleno, $trazo, $grosor);
    }

    public function circulo(float $cx, float $cy, float $radio, ?string $relleno, ?string $trazo = null, float $grosor = 1.0): void
    {
        $y = $this->alto - $cy;
        $k = 0.5523 * $radio;
        $ruta = self::n($cx + $radio) . ' ' . self::n($y) . " m\n"
            . self::curva($cx + $radio, $y + $k, $cx + $k, $y + $radio, $cx, $y + $radio)
            . self::curva($cx - $k, $y + $radio, $cx - $radio, $y + $k, $cx - $radio, $y)
            . self::curva($cx - $radio, $y - $k, $cx - $k, $y - $radio, $cx, $y - $radio)
            . self::curva($cx + $k, $y - $radio, $cx + $radio, $y - $k, $cx + $radio, $y)
            . "h\n";
        $this->pintar($ruta, $relleno, $trazo, $grosor);
    }

    /** @param array<int, array{0: float, 1: float}> $puntos */
    public function poligono(array $puntos, ?string $relleno, ?string $trazo = null, float $grosor = 1.0): void
    {
        $ruta = '';
        foreach (array_values($puntos) as $i => [$px, $py]) {
            $ruta .= self::n($px) . ' ' . self::n($this->alto - $py) . ($i === 0 ? " m\n" : " l\n");
        }
        $this->pintar($ruta . "h\n", $relleno, $trazo, $grosor);
    }

    /**
     * Muchos rectángulos del mismo color en una sola figura: los módulos de un
     * código QR, que así se pintan de una vez y sin rayas entre ellos.
     *
     * @param array<int, array{0: float, 1: float, 2: float, 3: float}> $rectangulos x, y, ancho, alto
     */
    public function rectangulos(array $rectangulos, string $color): void
    {
        $ruta = '';
        foreach ($rectangulos as [$x, $y, $ancho, $alto]) {
            $ruta .= self::n($x) . ' ' . self::n($this->alto - $y - $alto) . ' ' . self::n($ancho) . ' '
                . self::n($alto) . " re\n";
        }
        $this->pintar($ruta, $color, null, 0.0);
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, string $color, float $grosor = 1.0): void
    {
        $this->pintar(self::n($x1) . ' ' . self::n($this->alto - $y1) . ' m ' . self::n($x2) . ' '
            . self::n($this->alto - $y2) . " l\n", null, $color, $grosor);
    }

    /** Lo que se dibuje hasta restaurar() sale con esta opacidad (0 a 1). */
    public function opacidad(float $alfa): void
    {
        $clave = sprintf('%.2F', max(0.0, min(1.0, $alfa)));
        $this->estados[$clave] ??= 'GS' . (count($this->estados) + 1);
        $this->contenido .= '/' . $this->estados[$clave] . " gs\n";
    }

    public function guardar(): void
    {
        $this->contenido .= "q\n";
    }

    public function restaurar(): void
    {
        $this->contenido .= "Q\n";
    }

    /* =====================================================================
       Texto
       ===================================================================== */

    /** Ancho de un texto en UTF-8, en puntos. */
    public function ancho(string $clave, float $tamano, string $texto, float $espaciado = 0.0): float
    {
        return $this->datosFuente($clave)->ancho(Fuente::aCp1252($texto), $tamano, $espaciado);
    }

    /**
     * El tamaño más grande, entre $minimo y $maximo, con el que el texto cabe
     * en $anchoMaximo. Si ni con el mínimo cabe, el mínimo.
     */
    public function tamanoQueCabe(string $clave, string $texto, float $anchoMaximo, float $maximo, float $minimo,
                                  float $espaciadoPorPunto = 0.0): float
    {
        $tamano = $maximo;
        while ($tamano > $minimo
            && $this->ancho($clave, $tamano, $texto, $espaciadoPorPunto * $tamano) > $anchoMaximo) {
            $tamano -= 0.5;
        }
        return max($minimo, $tamano);
    }

    /**
     * Escribe una línea. $x es el borde izquierdo, el centro o el borde derecho
     * según 'alinear'; $y, la línea base. Devuelve el ancho escrito.
     *
     * @param array{color?: string, alinear?: string, espaciado?: float} $opciones
     */
    public function texto(string $clave, float $tamano, float $x, float $y, string $texto, array $opciones = []): float
    {
        $fuente = $this->datosFuente($clave);
        $crudo = Fuente::aCp1252($texto);
        $espaciado = (float) ($opciones['espaciado'] ?? 0.0);
        $ancho = $fuente->ancho($crudo, $tamano, $espaciado);
        $inicio = match ($opciones['alinear'] ?? 'izquierda') {
            'centro'  => $x - $ancho / 2,
            'derecha' => $x - $ancho,
            default   => $x,
        };
        $this->contenido .= 'BT ' . self::color($opciones['color'] ?? '#000000', false)
            . '/' . $this->fuentes[$clave]['recurso'] . ' ' . self::n($tamano) . ' Tf '
            // Siempre se escribe: el espaciado es parte del estado gráfico y
            // sigue vigente en los textos de después si no se vuelve a poner.
            . self::n($espaciado) . ' Tc '
            . self::n($inicio) . ' ' . self::n($this->alto - $y) . ' Td '
            . self::tj($fuente, $crudo) . " ET\n";
        return $ancho;
    }

    /**
     * Escribe una línea girada $grados en sentido contrario a las agujas del
     * reloj, empezando en ($x, $y). Para el texto en círculo del sello.
     *
     * @param array{color?: string} $opciones
     */
    public function textoGirado(string $clave, float $tamano, float $x, float $y, float $grados, string $texto, array $opciones = []): void
    {
        $fuente = $this->datosFuente($clave);
        $rad = deg2rad($grados);
        [$cos, $sen] = [cos($rad), sin($rad)];
        $this->contenido .= 'BT ' . self::color($opciones['color'] ?? '#000000', false)
            . '/' . $this->fuentes[$clave]['recurso'] . ' ' . self::n($tamano) . ' Tf 0 Tc '
            . self::n($cos) . ' ' . self::n($sen) . ' ' . self::n(-$sen) . ' ' . self::n($cos) . ' '
            . self::n($x) . ' ' . self::n($this->alto - $y) . ' Tm '
            . self::tj($fuente, Fuente::aCp1252($texto)) . " ET\n";
    }

    /**
     * Un párrafo con tramos de distintas fuentes —«asistió a la jornada de la
     * **Cumbre…**»—, cortado en renglones de $anchoMaximo. Devuelve el alto
     * que ocupó, de la primera línea base a la última, más un renglón.
     *
     * @param array<int, array{fuente: string, texto: string, color?: string}> $tramos
     */
    public function parrafo(array $tramos, float $x, float $y, float $anchoMaximo, float $tamano, float $interlinea,
                            string $alinear = 'centro', string $color = '#000000'): float
    {
        $renglones = $this->cortar($tramos, $anchoMaximo, $tamano);
        $base = $y;
        foreach ($renglones as $renglon) {
            $ancho = array_sum(array_column($renglon, 'ancho'));
            $cursor = match ($alinear) {
                'centro'  => $x + ($anchoMaximo - $ancho) / 2,
                'derecha' => $x + $anchoMaximo - $ancho,
                default   => $x,
            };
            foreach ($renglon as $pedazo) {
                $this->texto($pedazo['fuente'], $tamano, $cursor, $base, $pedazo['texto'],
                    ['color' => $pedazo['color'] ?? $color]);
                $cursor += $pedazo['ancho'];
            }
            $base += $interlinea;
        }
        return count($renglones) * $interlinea;
    }

    /** Cuántos renglones ocupa un párrafo, sin escribirlo. */
    public function renglones(array $tramos, float $anchoMaximo, float $tamano): int
    {
        return count($this->cortar($tramos, $anchoMaximo, $tamano));
    }

    /* =====================================================================
       Salida
       ===================================================================== */

    public function salida(): string
    {
        // Las fuentes que se usaron, ahora que se sabe cuáles.
        $recursosFuente = '';
        foreach ($this->fuentes as $datos) {
            if ($datos['usada']) {
                $recursosFuente .= '/' . $datos['recurso'] . ' ' . $this->incrustarFuente($datos['fuente']) . ' 0 R ';
            }
        }
        $recursosImagen = '';
        foreach ($this->imagenes as $datos) {
            $recursosImagen .= '/' . $datos['recurso'] . ' ' . $datos['id'] . ' 0 R ';
        }
        $recursosEstado = '';
        foreach ($this->estados as $alfa => $recurso) {
            $id = $this->agregar('<< /Type /ExtGState /CA ' . $alfa . ' /ca ' . $alfa . ' >>');
            $recursosEstado .= '/' . $recurso . ' ' . $id . ' 0 R ';
        }

        $contenido = $this->agregar($this->flujo('', $this->contenido));
        $paginas = $this->reservar();
        $pagina = $this->agregar('<< /Type /Page /Parent ' . $paginas . ' 0 R /MediaBox [0 0 ' . self::n($this->ancho)
            . ' ' . self::n($this->alto) . '] /Resources << /ProcSet [/PDF /Text /ImageB /ImageC]'
            . ($recursosFuente !== '' ? ' /Font << ' . $recursosFuente . '>>' : '')
            . ($recursosImagen !== '' ? ' /XObject << ' . $recursosImagen . '>>' : '')
            . ($recursosEstado !== '' ? ' /ExtGState << ' . $recursosEstado . '>>' : '')
            . ' >> /Contents ' . $contenido . ' 0 R >>');
        $this->poner($paginas, '<< /Type /Pages /Kids [' . $pagina . ' 0 R] /Count 1 >>');
        $catalogo = $this->agregar('<< /Type /Catalog /Pages ' . $paginas . ' 0 R /Lang (es-CO)'
            . ' /ViewerPreferences << /DisplayDocTitle true >> >>');

        $info = $this->info + ['Producer' => 'Plataforma de Eventos TIC'];
        $diccionario = '';
        foreach ($info as $clave => $valor) {
            $diccionario .= '/' . $clave . ' ' . self::textoPdf($valor) . ' ';
        }
        $fecha = date('YmdHis') . str_replace(':', "'", date('P')) . "'";
        $diccionario .= '/CreationDate (D:' . $fecha . ') /ModDate (D:' . $fecha . ')';
        $idInfo = $this->agregar('<< ' . $diccionario . ' >>');

        $salida = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posiciones = [];
        foreach ($this->objetos as $i => $cuerpo) {
            $posiciones[$i + 1] = strlen($salida);
            $salida .= ($i + 1) . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }
        $xref = strlen($salida);
        $total = count($this->objetos) + 1;
        $salida .= "xref\n0 " . $total . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $salida .= sprintf("%010d 00000 n \n", $posiciones[$i]);
        }
        $huella = md5($salida);
        $salida .= "trailer\n<< /Size " . $total . ' /Root ' . $catalogo . ' 0 R /Info ' . $idInfo . ' 0 R'
            . ' /ID [<' . $huella . '> <' . $huella . ">] >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $salida;
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    private function agregar(string $cuerpo): int
    {
        $this->objetos[] = $cuerpo;
        return count($this->objetos);
    }

    private function reservar(): int
    {
        return $this->agregar('');
    }

    private function poner(int $id, string $cuerpo): void
    {
        $this->objetos[$id - 1] = $cuerpo;
    }

    private function flujo(string $diccionario, string $datos, bool $comprimir = true): string
    {
        if ($comprimir) {
            $datos = (string) gzcompress($datos, 6);
            $diccionario = trim($diccionario . ' /Filter /FlateDecode');
        }
        return '<< ' . ($diccionario !== '' ? $diccionario . ' ' : '') . '/Length ' . strlen($datos)
            . " >>\nstream\n" . $datos . "\nendstream";
    }

    private function datosFuente(string $clave): Fuente
    {
        if (!isset($this->fuentes[$clave])) {
            throw new \LogicException('Fuente no registrada: ' . $clave);
        }
        $this->fuentes[$clave]['usada'] = true;
        return $this->fuentes[$clave]['fuente'];
    }

    /** La fuente dentro del PDF: el programa, su descripción y su diccionario. */
    private function incrustarFuente(Fuente $fuente): int
    {
        $programa = $fuente->programa();
        $archivo = $this->agregar($this->flujo('/Length1 ' . strlen($programa), $programa));

        // Es un subconjunto de la fuente original, y la especificación pide
        // marcarlo con seis mayúsculas y un «+» delante del nombre.
        $marca = '';
        foreach (str_split(substr(md5($fuente->nombre), 0, 6)) as $c) {
            $marca .= chr(65 + hexdec($c) % 26);
        }
        $nombre = $marca . '+' . $fuente->nombre;

        $descriptor = $this->agregar('<< /Type /FontDescriptor /FontName /' . $nombre
            . ' /Flags ' . (32 + ($fuente->anguloCursiva != 0.0 ? 64 : 0))
            . ' /FontBBox [' . implode(' ', $fuente->caja) . ']'
            . ' /ItalicAngle ' . self::n($fuente->anguloCursiva)
            . ' /Ascent ' . $fuente->ascendente . ' /Descent ' . $fuente->descendente
            . ' /CapHeight ' . $fuente->altoMayusculas
            . ' /StemV ' . (int) round(50 + ($fuente->peso / 65) ** 2)
            . ' /MissingWidth ' . ($fuente->anchos[32] ?? 250)
            . ' /FontFile2 ' . $archivo . ' 0 R >>');

        $anchos = [];
        for ($codigo = 32; $codigo <= 255; $codigo++) {
            $anchos[] = $fuente->anchos[$codigo] ?? 0;
        }
        return $this->agregar('<< /Type /Font /Subtype /TrueType /BaseFont /' . $nombre
            . ' /FirstChar 32 /LastChar 255 /Widths [' . implode(' ', $anchos) . ']'
            . ' /Encoding /WinAnsiEncoding /FontDescriptor ' . $descriptor . ' 0 R >>');
    }

    /**
     * Corta los tramos en renglones. Cada palabra conserva su fuente; los
     * espacios del final de un renglón no cuentan para centrarlo.
     *
     * @return array<int, array<int, array{fuente: string, texto: string, ancho: float, color?: string}>>
     */
    private function cortar(array $tramos, float $anchoMaximo, float $tamano): array
    {
        // Palabras sueltas, cada una con el espacio que la sigue.
        $palabras = [];
        foreach ($tramos as $tramo) {
            $partes = preg_split('/(\s+)/u', (string) $tramo['texto'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            for ($i = 0; $i < count($partes); $i += 2) {
                $palabra = $partes[$i];
                $espacio = isset($partes[$i + 1]) ? ' ' : '';
                if ($palabra === '' && $espacio === '') {
                    continue;
                }
                $palabras[] = ['fuente' => $tramo['fuente'], 'texto' => $palabra, 'espacio' => $espacio]
                    + (isset($tramo['color']) ? ['color' => $tramo['color']] : []);
            }
        }

        $renglones = [];
        $actual = [];
        $ancho = 0.0;
        foreach ($palabras as $palabra) {
            $anchoPalabra = $this->ancho($palabra['fuente'], $tamano, $palabra['texto']);
            $anchoEspacio = $palabra['espacio'] !== '' ? $this->ancho($palabra['fuente'], $tamano, ' ') : 0.0;
            if ($actual !== [] && $ancho + $anchoPalabra > $anchoMaximo) {
                $renglones[] = $actual;
                $actual = [];
                $ancho = 0.0;
            }
            $actual[] = $palabra + ['ancho' => $anchoPalabra, 'anchoEspacio' => $anchoEspacio];
            $ancho += $anchoPalabra + $anchoEspacio;
        }
        if ($actual !== []) {
            $renglones[] = $actual;
        }

        // Cada palabra con su espacio, salvo la última del renglón.
        $salida = [];
        foreach ($renglones as $renglon) {
            $ultimo = count($renglon) - 1;
            $pedazos = [];
            foreach ($renglon as $i => $palabra) {
                $conEspacio = $i < $ultimo && $palabra['espacio'] !== '';
                $pedazos[] = [
                    'fuente' => $palabra['fuente'],
                    'texto'  => $palabra['texto'] . ($conEspacio ? ' ' : ''),
                    'ancho'  => $palabra['ancho'] + ($conEspacio ? $palabra['anchoEspacio'] : 0.0),
                ] + (isset($palabra['color']) ? ['color' => $palabra['color']] : []);
            }
            $salida[] = $pedazos;
        }
        return $salida;
    }

    private function pintar(string $ruta, ?string $relleno, ?string $trazo, float $grosor): void
    {
        if ($relleno === null && $trazo === null) {
            return;
        }
        $this->contenido .= 'q ' . ($relleno !== null ? self::color($relleno, false) : '')
            . ($trazo !== null ? self::color($trazo, true) . self::n($grosor) . ' w ' : '') . "\n"
            . $ruta . ($relleno !== null && $trazo !== null ? 'B' : ($relleno !== null ? 'f' : 'S')) . " Q\n";
    }

    /** El texto con el interletrado de la fuente, como un arreglo TJ. */
    private static function tj(Fuente $fuente, string $crudo): string
    {
        $partes = [];
        $actual = '';
        $largo = strlen($crudo);
        for ($i = 0; $i < $largo; $i++) {
            $actual .= $crudo[$i];
            if ($i + 1 < $largo) {
                $ajuste = $fuente->kern[ord($crudo[$i])][ord($crudo[$i + 1])] ?? 0;
                if ($ajuste !== 0) {
                    $partes[] = '<' . bin2hex($actual) . '> ' . (-$ajuste);
                    $actual = '';
                }
            }
        }
        if ($actual !== '') {
            $partes[] = '<' . bin2hex($actual) . '>';
        }
        return '[' . implode(' ', $partes) . '] TJ';
    }

    private static function curva(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string
    {
        return self::n($x1) . ' ' . self::n($y1) . ' ' . self::n($x2) . ' ' . self::n($y2) . ' '
            . self::n($x3) . ' ' . self::n($y3) . " c\n";
    }

    /** «#1B2A6B» → «0.106 0.165 0.420 rg» (o RG para el trazo). */
    private static function color(string $hex, bool $trazo): string
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }
        $canales = array_map(static fn(string $c): string => self::n(hexdec($c) / 255),
            str_split($hex, 2));
        return implode(' ', $canales) . ($trazo ? ' RG ' : ' rg ');
    }

    /** Un número como lo escribe el PDF: punto decimal y sin ceros de más. */
    private static function n(float $valor): string
    {
        $texto = rtrim(rtrim(sprintf('%.3F', $valor), '0'), '.');
        return $texto === '-0' || $texto === '' ? '0' : $texto;
    }

    /** Texto de los metadatos, en UTF-16 con su marca, que es lo que admite cualquier letra. */
    private static function textoPdf(string $texto): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($texto, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /* =====================================================================
       PNG
       ===================================================================== */

    /**
     * El PNG separado en color y transparencia, comprimidos, listos para el
     * PDF. De la caché si está y es del mismo archivo.
     *
     * @return array{ancho: int, alto: int, color: string, pixeles: string, alfa: ?string}
     */
    private static function pngPreparado(string $datos, string $ruta, ?string $cache): array
    {
        $huella = md5($datos);
        if ($cache !== null && is_file($cache)) {
            $guardado = @unserialize((string) @file_get_contents($cache), ['allowed_classes' => false]);
            if (is_array($guardado) && ($guardado['huella'] ?? '') === $huella) {
                return $guardado;
            }
        }

        $png = self::leerPng($datos, basename($ruta));
        $preparado = [
            'huella'  => $huella,
            'ancho'   => $png['ancho'],
            'alto'    => $png['alto'],
            'color'   => $png['color'],
            'pixeles' => (string) gzcompress($png['pixeles'], 6),
            'alfa'    => $png['alfa'] !== null ? (string) gzcompress($png['alfa'], 6) : null,
        ];
        if ($cache !== null) {
            $temporal = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temporal, serialize($preparado), LOCK_EX) !== false) {
                @chmod($temporal, 0640);
                @rename($temporal, $cache);
            }
            @unlink($temporal);
        }
        return $preparado;
    }

    /**
     * Lee un PNG de 8 bits sin entrelazar: escala de grises, color, paleta,
     * con o sin transparencia. Devuelve los píxeles sin filtros.
     *
     * @return array{ancho: int, alto: int, color: string, pixeles: string, alfa: ?string}
     */
    public static function leerPng(string $datos, string $nombre = 'la imagen'): array
    {
        $pos = 8;
        $largoTotal = strlen($datos);
        $cabecera = null;
        $paleta = '';
        $transparencia = '';
        $comprimido = '';
        while ($pos + 8 <= $largoTotal) {
            $largo = unpack('N', $datos, $pos)[1];
            $tipo = substr($datos, $pos + 4, 4);
            $cuerpo = substr($datos, $pos + 8, $largo);
            $pos += 12 + $largo;
            if ($tipo === 'IHDR') {
                $cabecera = unpack('Nancho/Nalto/Cbits/Ccolor/Ccompresion/Cfiltro/Centrelazado', $cuerpo);
            } elseif ($tipo === 'PLTE') {
                $paleta = $cuerpo;
            } elseif ($tipo === 'tRNS') {
                $transparencia = $cuerpo;
            } elseif ($tipo === 'IDAT') {
                $comprimido .= $cuerpo;
            } elseif ($tipo === 'IEND') {
                break;
            }
        }
        if ($cabecera === null || $comprimido === '') {
            throw new \RuntimeException('El PNG ' . $nombre . ' está incompleto.');
        }
        $canales = match ($cabecera['color']) { 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4, default => 0 };
        if ($canales === 0) {
            throw new \RuntimeException('El PNG ' . $nombre . ' usa un tipo de color desconocido.');
        }
        // Las profundidades que admite cada tipo de color, según la norma.
        $bits = (int) $cabecera['bits'];
        $admitidos = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
        if (!in_array($bits, $admitidos[$cabecera['color']], true)) {
            throw new \RuntimeException('El PNG ' . $nombre . ' tiene una profundidad de color que no existe.');
        }
        if ($cabecera['entrelazado'] !== 0) {
            throw new \RuntimeException('El PNG ' . $nombre . ' tiene que estar sin entrelazar.');
        }

        $crudo = @gzuncompress($comprimido);
        [$ancho, $alto] = [(int) $cabecera['ancho'], (int) $cabecera['alto']];
        $paso = (int) ceil($ancho * $canales * $bits / 8);
        // Los filtros comparan con el byte del píxel anterior; con menos de
        // ocho bits por píxel, con el byte anterior.
        $vecino = max(1, intdiv($canales * $bits, 8));
        if ($crudo === false || strlen($crudo) < ($paso + 1) * $alto) {
            throw new \RuntimeException('El PNG ' . $nombre . ' está dañado.');
        }

        $pixeles = '';
        $alfa = '';
        $anterior = array_fill(0, $paso, 0);
        for ($y = 0, $p = 0; $y < $alto; $y++, $p += $paso + 1) {
            $filtro = ord($crudo[$p]);
            $fila = array_values(unpack('C*', substr($crudo, $p + 1, $paso)) ?: []);
            switch ($filtro) {
                case 0:
                    break;
                case 1:
                    for ($i = $vecino; $i < $paso; $i++) {
                        $fila[$i] = ($fila[$i] + $fila[$i - $vecino]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $paso; $i++) {
                        $fila[$i] = ($fila[$i] + $anterior[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $paso; $i++) {
                        $a = $i >= $vecino ? $fila[$i - $vecino] : 0;
                        $fila[$i] = ($fila[$i] + (($a + $anterior[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $paso; $i++) {
                        $a = $i >= $vecino ? $fila[$i - $vecino] : 0;
                        $b = $anterior[$i];
                        $c = $i >= $vecino ? $anterior[$i - $vecino] : 0;
                        $estimado = $a + $b - $c;
                        $pa = abs($estimado - $a);
                        $pb = abs($estimado - $b);
                        $pc = abs($estimado - $c);
                        $fila[$i] = ($fila[$i] + (($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c))) & 0xFF;
                    }
                    break;
                default:
                    throw new \RuntimeException('El PNG ' . $nombre . ' tiene un filtro desconocido.');
            }
            $anterior = $fila;

            // Todo a una muestra de 8 bits por canal: de 16 se toma el byte
            // alto; de 1, 2 o 4 se separan los bits, y los grises se estiran
            // hasta 255 (en la paleta son índices y se dejan como están).
            if ($bits === 16) {
                $fila = array_values(array_filter($fila, static fn(int $i): bool => $i % 2 === 0, ARRAY_FILTER_USE_KEY));
            } elseif ($bits < 8) {
                $muestras = [];
                $mascara = (1 << $bits) - 1;
                $escala = $cabecera['color'] === 0 ? 255 / $mascara : 1;
                foreach ($fila as $byte) {
                    for ($corrimiento = 8 - $bits; $corrimiento >= 0; $corrimiento -= $bits) {
                        $muestras[] = (int) round((($byte >> $corrimiento) & $mascara) * $escala);
                    }
                }
                $fila = array_slice($muestras, 0, $ancho * $canales);
            }
            $binario = pack('C*', ...$fila);

            switch ($cabecera['color']) {
                case 6:     // RGBA
                case 4:     // gris con alfa
                    $grupos = str_split($binario, $canales);
                    $pixeles .= implode('', array_map(static fn(string $g): string => substr($g, 0, -1), $grupos));
                    $alfa .= implode('', array_map(static fn(string $g): string => $g[strlen($g) - 1], $grupos));
                    break;
                case 3:     // paleta: se pasa a RGB, y su transparencia a alfa
                    foreach ($fila as $indice) {
                        $pixeles .= substr($paleta, 3 * $indice, 3);
                        $alfa .= $transparencia !== '' && $indice < strlen($transparencia) ? $transparencia[$indice] : "\xFF";
                    }
                    break;
                default:
                    $pixeles .= $binario;
            }
        }

        // Una transparencia que no transparenta nada no hace falta.
        $conAlfa = $alfa !== '' && strspn($alfa, "\xFF") !== strlen($alfa);
        return [
            'ancho'   => $ancho,
            'alto'    => $alto,
            'color'   => in_array($cabecera['color'], [0, 4], true) ? 'DeviceGray' : 'DeviceRGB',
            'pixeles' => $pixeles,
            'alfa'    => $conAlfa ? $alfa : null,
        ];
    }
}
