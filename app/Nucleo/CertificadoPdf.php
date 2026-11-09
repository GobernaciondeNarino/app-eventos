<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * El diseño del certificado, en una hoja carta horizontal.
 *
 * Recibe los textos ya redactados (App\Modelos\Certificado) y solo decide dónde
 * va cada cosa: el fondo a sangre, el título, quién certifica, el nombre dentro
 * de su recuadro, el texto, la firma, el código QR para verificarlo y, para
 * quien asistió a todas las jornadas, el sello dorado.
 *
 * Las posiciones están pensadas para el fondo de la Cumbre (3.21): el logo
 * ocupa la parte de arriba, los logos institucionales la de abajo y las
 * esquinas de abajo tienen las ondas; el texto va en la franja blanca del medio.
 * Sin fondo cargado, la hoja sale en blanco con un marco del color principal,
 * y sin la imagen del recuadro, el recuadro se dibuja.
 */
final class CertificadoPdf
{
    private const ANCHO = 792.0;
    private const ALTO = 612.0;
    private const CENTRO = 396.0;

    /** La columna del texto: ancho y borde izquierdo. */
    private const COLUMNA = 560.0;
    private const IZQUIERDA = 116.0;

    private const BASE_TITULO = 160.0;
    private const BASE_ENTIDAD = 184.0;
    private const ARRIBA_RECUADRO = 208.0;
    private const ANCHO_RECUADRO = 520.0;
    private const ALTO_MAXIMO_RECUADRO = 86.0;
    /** El texto y la línea de expedición, entre el documento y la firma. */
    private const ALTO_CUERPO = 68.0;
    private const BASE_EXPEDICION = 392.0;

    /** La firma, el QR y el sello comparten la franja de abajo. */
    private const ARRIBA_FIRMA = 404.0;
    private const QR_X = 664.0;
    private const QR_Y = 386.0;
    private const QR_LADO = 64.0;
    private const SELLO_X = 122.0;
    private const SELLO_Y = 418.0;
    private const SELLO_RADIO = 38.0;

    private const TEXTO = '#2B3A55';
    private const SUAVE = '#5B6B85';
    private const ORO = '#F7C531';
    private const ORO_OSCURO = '#C99A12';

    /** @param array<string, mixed> $d los textos y las imágenes; ver App\Modelos\Certificado::documento() */
    public static function generar(array $d): string
    {
        $pdf = new Pdf(self::ANCHO, self::ALTO);
        $pdf->metadatos((array) ($d['metadatos'] ?? []));
        $fuentes = RAIZ . '/app/Recursos/fuentes/Montserrat-';
        foreach (['regular' => 'Regular', 'semi' => 'SemiBold', 'negrita' => 'Bold', 'extra' => 'ExtraBold'] as $clave => $peso) {
            $pdf->fuente($clave, $fuentes . $peso . '.ttf');
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['color'] ?? '')) ? (string) $d['color'] : '#102951';

        $conFondo = self::fondo($pdf, (string) ($d['fondo'] ?? ''), $color);
        // Sin fondo no hay logo arriba: va el nombre del evento, para que la
        // hoja diga de qué es aunque nadie haya cargado el diseño.
        if (!$conFondo && trim((string) ($d['encabezado'] ?? '')) !== '') {
            $encabezado = mb_strtoupper(trim((string) $d['encabezado']));
            $tam = $pdf->tamanoQueCabe('negrita', $encabezado, self::COLUMNA, 13, 8, 0.18);
            $pdf->texto('negrita', $tam, self::CENTRO, 92, $encabezado,
                ['alinear' => 'centro', 'color' => $color, 'espaciado' => 0.18 * $tam]);
            $pdf->linea(self::CENTRO - 40, 108, self::CENTRO + 40, 108, self::ORO, 2.2);
        }

        // Título y quién certifica.
        $titulo = (string) $d['titulo'];
        $pdf->texto('extra', $pdf->tamanoQueCabe('extra', $titulo, self::COLUMNA, 25, 16, 0.045),
            self::CENTRO, self::BASE_TITULO, $titulo,
            ['alinear' => 'centro', 'color' => $color, 'espaciado' => 0.045 * 25]);
        $entidad = [['fuente' => 'regular', 'texto' => (string) $d['entidad']]];
        $tamEntidad = 10.5;
        while ($tamEntidad > 8.5 && $pdf->renglones($entidad, self::COLUMNA, $tamEntidad) > 2) {
            $tamEntidad -= 0.5;
        }
        $pdf->parrafo($entidad, self::IZQUIERDA, self::BASE_ENTIDAD, self::COLUMNA, $tamEntidad, $tamEntidad * 1.36,
            'centro', self::TEXTO);

        // El nombre, en su recuadro.
        $abajoRecuadro = self::recuadro($pdf, $d, $color);
        if ((string) ($d['documento'] ?? '') !== '') {
            $pdf->texto('semi', 9.5, self::CENTRO, $abajoRecuadro + 15, (string) $d['documento'],
                ['alinear' => 'centro', 'color' => self::TEXTO, 'espaciado' => 0.4]);
        }

        // El texto: lo que hizo la persona. Se achica si es largo —el título
        // de una exposición puede tener doscientas letras— hasta que quepa.
        $cuerpo = [];
        foreach ((array) $d['cuerpo'] as $tramo) {
            $cuerpo[] = ['fuente' => !empty($tramo['negrita']) ? 'negrita' : 'regular', 'texto' => (string) $tramo['texto']];
        }
        $tam = 12.0;
        while ($tam > 8.5 && $pdf->renglones($cuerpo, self::COLUMNA, $tam) * $tam * 1.4 > self::ALTO_CUERPO) {
            $tam -= 0.5;
        }
        $pdf->parrafo($cuerpo, self::IZQUIERDA, $abajoRecuadro + 40, self::COLUMNA, $tam, $tam * 1.4, 'centro', self::TEXTO);

        if ((string) ($d['expedicion'] ?? '') !== '') {
            $pdf->texto('regular', 8.5, self::CENTRO, self::BASE_EXPEDICION, (string) $d['expedicion'],
                ['alinear' => 'centro', 'color' => self::SUAVE]);
        }

        if (!empty($d['firma']) && trim((string) ($d['firma']['nombre'] ?? '')) !== '') {
            self::firma($pdf, (array) $d['firma'], $color);
        }
        if (!empty($d['sello'])) {
            self::sello($pdf, (array) $d['sello'], $color);
        }
        if ((string) ($d['qr'] ?? '') !== '') {
            self::verificacion($pdf, $d, $color);
        }

        // El ejemplo que descarga el administrador lleva una marca de agua
        // cruzada: con datos inventados no puede pasar por uno de verdad.
        if (!empty($d['muestra'])) {
            $pdf->guardar();
            $pdf->opacidad(0.13);
            $marca = 'EJEMPLO · SIN VALIDEZ';
            $tam = 64.0;
            $ancho = $pdf->ancho('extra', $tam, $marca);
            $angulo = deg2rad(22);
            $pdf->textoGirado('extra', $tam, self::CENTRO - cos($angulo) * $ancho / 2,
                self::ALTO / 2 + sin($angulo) * $ancho / 2 + 20, 22, $marca, ['color' => '#C0392B']);
            $pdf->restaurar();
        }

        return $pdf->salida();
    }

    /* =====================================================================
       Partes
       ===================================================================== */

    /**
     * El fondo, a sangre: se escala para cubrir la hoja entera y se centra, así
     * que una imagen de otra proporción pierde un poco de los bordes en vez de
     * dejar franjas blancas. Sin imagen, un marco del color principal.
     */
    private static function fondo(Pdf $pdf, string $ruta, string $color): bool
    {
        if ($ruta !== '' && is_file($ruta)) {
            [$ancho, $alto] = $pdf->imagen('fondo', $ruta);
            $escala = max(self::ANCHO / $ancho, self::ALTO / $alto);
            $pdf->dibujarImagen('fondo', (self::ANCHO - $ancho * $escala) / 2, (self::ALTO - $alto * $escala) / 2,
                $ancho * $escala, $alto * $escala);
            return true;
        }
        $pdf->rectangulo(0, 0, self::ANCHO, self::ALTO, '#FFFFFF');
        $pdf->rectangulo(22, 22, self::ANCHO - 44, self::ALTO - 44, null, $color, 2.2, 6);
        $pdf->rectangulo(30, 30, self::ANCHO - 60, self::ALTO - 60, null, Tema::aclarar($color, 0.55), 0.8, 4);
        foreach ([[22, 22], [self::ANCHO - 22, 22], [22, self::ALTO - 22], [self::ANCHO - 22, self::ALTO - 22]] as [$x, $y]) {
            $pdf->circulo($x, $y, 5, $color);
        }
        return false;
    }

    /** El recuadro con el nombre. Devuelve dónde termina, para seguir debajo. */
    private static function recuadro(Pdf $pdf, array $d, string $color): float
    {
        $ancho = self::ANCHO_RECUADRO;
        $alto = 66.0;
        $ruta = (string) ($d['fondoTexto'] ?? '');
        $conImagen = $ruta !== '' && is_file($ruta);
        if ($conImagen) {
            [$px, $py] = $pdf->imagen('recuadro', $ruta, $d['cacheTexto'] ?? null);
            $alto = $ancho * $py / max(1, $px);
            if ($alto > self::ALTO_MAXIMO_RECUADRO) {
                $alto = self::ALTO_MAXIMO_RECUADRO;
                $ancho = $alto * $px / max(1, $py);
            }
        }
        $x = self::CENTRO - $ancho / 2;
        $y = self::ARRIBA_RECUADRO;
        if ($conImagen) {
            $pdf->dibujarImagen('recuadro', $x, $y, $ancho, $alto);
        } else {
            $pdf->rectangulo($x, $y, $ancho, $alto, '#F4F8FD', Tema::aclarar($color, 0.25), 1.6, 12);
        }

        // Una línea si cabe; si no, dos, partidas donde quedan más parejas.
        $nombre = (string) $d['nombre'];
        $interior = $ancho - 56;
        $tam = $pdf->tamanoQueCabe('negrita', $nombre, $interior, 27, 15, 0.02);
        $lineas = [$nombre];
        if ($pdf->ancho('negrita', $tam, $nombre, 0.02 * $tam) > $interior) {
            $lineas = self::partirEnDos($pdf, $nombre);
            $tam = min(
                $pdf->tamanoQueCabe('negrita', $lineas[0], $interior, 19, 9, 0.02),
                $pdf->tamanoQueCabe('negrita', $lineas[1], $interior, 19, 9, 0.02)
            );
        }
        $altoMayuscula = 0.7 * $tam;
        $interlinea = $tam * 1.18;
        $primera = $y + $alto / 2 + $altoMayuscula / 2 - (count($lineas) - 1) * $interlinea / 2;
        foreach ($lineas as $i => $linea) {
            $pdf->texto('negrita', $tam, self::CENTRO, $primera + $i * $interlinea, $linea,
                ['alinear' => 'centro', 'color' => $color, 'espaciado' => 0.02 * $tam]);
        }
        return $y + $alto;
    }

    /** @return array{0: string, 1: string} */
    private static function partirEnDos(Pdf $pdf, string $texto): array
    {
        $palabras = preg_split('/\s+/u', trim($texto)) ?: [$texto];
        if (count($palabras) < 2) {
            return [$texto, ''];
        }
        $mejor = [$texto, ''];
        $diferencia = PHP_FLOAT_MAX;
        for ($i = 1; $i < count($palabras); $i++) {
            $a = implode(' ', array_slice($palabras, 0, $i));
            $b = implode(' ', array_slice($palabras, $i));
            $resta = abs($pdf->ancho('negrita', 10, $a) - $pdf->ancho('negrita', 10, $b));
            if ($resta < $diferencia) {
                $diferencia = $resta;
                $mejor = [$a, $b];
            }
        }
        return $mejor;
    }

    /** La firma: la imagen, si hay, sobre la línea; debajo el nombre y el cargo. */
    private static function firma(Pdf $pdf, array $firma, string $color): void
    {
        $linea = self::ARRIBA_FIRMA + 42;
        $imagen = (string) ($firma['imagen'] ?? '');
        if ($imagen !== '' && is_file($imagen)) {
            [$px, $py] = $pdf->imagen('firma', $imagen, $firma['cache'] ?? null);
            $alto = 40.0;
            $ancho = $alto * $px / max(1, $py);
            if ($ancho > 170) {
                $ancho = 170.0;
                $alto = $ancho * $py / max(1, $px);
            }
            // Un poco por debajo de la línea, como una firma de verdad.
            $pdf->dibujarImagen('firma', self::CENTRO - $ancho / 2, $linea - $alto + 6, $ancho, $alto);
        }
        $pdf->linea(self::CENTRO - 95, $linea, self::CENTRO + 95, $linea, Tema::aclarar($color, 0.3), 0.7);
        $pdf->texto('semi', 8.5, self::CENTRO, $linea + 11, (string) $firma['nombre'],
            ['alinear' => 'centro', 'color' => $color]);
        $cargo = trim((string) ($firma['cargo'] ?? ''));
        if ($cargo !== '') {
            $tam = $pdf->tamanoQueCabe('regular', $cargo, 240, 7.5, 6);
            $pdf->texto('regular', $tam, self::CENTRO, $linea + 21, $cargo, ['alinear' => 'centro', 'color' => self::SUAVE]);
        }
    }

    /**
     * El código QR y el código impreso, abajo a la derecha. El QR lleva a la
     * página pública que dice si el certificado es auténtico.
     */
    private static function verificacion(Pdf $pdf, array $d, string $color): void
    {
        $codigo = Qr::construir((string) $d['qr'], 'L');
        $modulos = $codigo['modulos'];
        $tamano = (int) $codigo['tamano'];
        $margen = 3.5;
        $lado = self::QR_LADO;
        $modulo = ($lado - 2 * $margen) / $tamano;

        $pdf->rectangulo(self::QR_X, self::QR_Y, $lado, $lado, '#FFFFFF', Tema::aclarar($color, 0.7), 0.6, 4);
        // Las rachas de módulos oscuros de cada fila, como un solo rectángulo.
        $rectangulos = [];
        for ($fila = 0; $fila < $tamano; $fila++) {
            $inicio = null;
            for ($col = 0; $col <= $tamano; $col++) {
                $oscuro = $col < $tamano && $modulos[$fila][$col];
                if ($oscuro && $inicio === null) {
                    $inicio = $col;
                } elseif (!$oscuro && $inicio !== null) {
                    // Un poco de solapamiento para que no queden rayas finas
                    // entre módulos vecinos al ampliar.
                    $rectangulos[] = [
                        self::QR_X + $margen + $inicio * $modulo, self::QR_Y + $margen + $fila * $modulo,
                        ($col - $inicio) * $modulo, $modulo + 0.02,
                    ];
                    $inicio = null;
                }
            }
        }
        $pdf->rectangulos($rectangulos, '#0B1A33');

        $centro = self::QR_X + $lado / 2;
        $pdf->texto('regular', 5.8, $centro, self::QR_Y + $lado + 9, 'Código de verificación',
            ['alinear' => 'centro', 'color' => self::SUAVE]);
        $pdf->texto('negrita', 8, $centro, self::QR_Y + $lado + 19, (string) $d['codigo'],
            ['alinear' => 'centro', 'color' => $color, 'espaciado' => 0.5]);
        if ((string) ($d['verificar'] ?? '') !== '') {
            $tam = $pdf->tamanoQueCabe('regular', (string) $d['verificar'], 112, 5.8, 4.5);
            $pdf->texto('regular', $tam, $centro, self::QR_Y + $lado + 27, (string) $d['verificar'],
                ['alinear' => 'centro', 'color' => self::SUAVE]);
        }
    }

    /**
     * El sello de asistencia completa: una medalla dorada con dos cintas, el
     * texto alrededor y la cuenta de jornadas en el centro.
     *
     * @param array{arriba?: string, centro?: string, abajo?: string} $sello
     */
    private static function sello(Pdf $pdf, array $sello, string $color): void
    {
        [$cx, $cy, $r] = [self::SELLO_X, self::SELLO_Y, self::SELLO_RADIO];

        // Las cintas, detrás de la medalla.
        foreach ([-1, 1] as $lado) {
            $base = $cx + $lado * 13;
            $pdf->poligono([
                [$base - 11, $cy + 18], [$base + 11, $cy + 18],
                [$base + 11 + $lado * 7, $cy + $r + 26], [$base + $lado * 5, $cy + $r + 19],
                [$base - 11 + $lado * 7, $cy + $r + 26],
            ], $color);
        }

        // La medalla: un borde dentado, el disco y un aro interior.
        $puntos = [];
        $dientes = 36;
        for ($i = 0; $i < 2 * $dientes; $i++) {
            $angulo = M_PI * $i / $dientes;
            $radio = $i % 2 === 0 ? $r + 3 : $r;
            $puntos[] = [$cx + $radio * cos($angulo), $cy + $radio * sin($angulo)];
        }
        $pdf->poligono($puntos, self::ORO_OSCURO);
        $pdf->circulo($cx, $cy, $r - 1, self::ORO);
        $pdf->circulo($cx, $cy, $r - 11, null, '#FFFFFF', 0.9);
        $pdf->circulo($cx, $cy, $r - 13.5, '#FFF3C4');

        $arriba = mb_strtoupper((string) ($sello['arriba'] ?? ''));
        $abajo = mb_strtoupper((string) ($sello['abajo'] ?? ''));
        if ($arriba !== '') {
            self::enArco($pdf, $arriba, $cx, $cy, $r - 8.2, true, $color);
        }
        if ($abajo !== '') {
            self::enArco($pdf, $abajo, $cx, $cy, $r - 3.6, false, $color);
        }
        $centro = (string) ($sello['centro'] ?? '');
        if ($centro !== '') {
            $tam = $pdf->tamanoQueCabe('extra', $centro, 2 * ($r - 17), 17, 8);
            $pdf->texto('extra', $tam, $cx, $cy + 0.35 * $tam, $centro, ['alinear' => 'centro', 'color' => $color]);
        }
        // Dos estrellas a los lados, entre el texto de arriba y el de abajo.
        foreach ([-1, 1] as $lado) {
            self::estrella($pdf, $cx + $lado * ($r - 6), $cy + 0.6, 2.6, $color);
        }
    }

    /**
     * Texto en arco, centrado arriba o abajo de un círculo. Arriba se lee de
     * izquierda a derecha por fuera; abajo también, con las letras de pie.
     */
    private static function enArco(Pdf $pdf, string $texto, float $cx, float $cy, float $radio, bool $arriba, string $color): void
    {
        $tam = 5.6;
        $espacio = 0.9;
        $letras = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $anchos = array_map(static fn(string $l): float => $pdf->ancho('negrita', $tam, $l), $letras);
        $total = (array_sum($anchos) + $espacio * (count($letras) - 1)) / $radio;   // radianes
        // Como mucho media vuelta: más, y el de arriba se junta con el de abajo.
        if ($total > M_PI * 0.92) {
            $tam *= M_PI * 0.92 / $total;
            $anchos = array_map(static fn(string $l): float => $pdf->ancho('negrita', $tam, $l), $letras);
            $total = M_PI * 0.92;
        }

        // En el sistema del PDF (y hacia arriba): el de arriba va de izquierda
        // a derecha bajando el ángulo desde 90° + la mitad; el de abajo, de
        // izquierda a derecha subiéndolo desde 270° − la mitad.
        $altoPdf = self::ALTO;
        $angulo = $arriba ? M_PI / 2 + $total / 2 : 3 * M_PI / 2 - $total / 2;
        foreach ($letras as $i => $letra) {
            $mitad = ($anchos[$i] / 2) / $radio;
            $centro = $arriba ? $angulo - $mitad : $angulo + $mitad;
            // El origen de la letra, media letra antes de su centro sobre la tangente.
            $px = $cx + $radio * cos($centro);
            $py = ($altoPdf - $cy) + $radio * sin($centro);
            if ($arriba) {
                $giro = $centro - M_PI / 2;
                $ox = $px - ($anchos[$i] / 2) * sin($centro);
                $oy = $py + ($anchos[$i] / 2) * cos($centro);
            } else {
                $giro = $centro + M_PI / 2;
                $ox = $px + ($anchos[$i] / 2) * sin($centro);
                $oy = $py - ($anchos[$i] / 2) * cos($centro);
            }
            $pdf->textoGirado('negrita', $tam, $ox, $altoPdf - $oy, rad2deg($giro), $letra, ['color' => $color]);
            $paso = ($anchos[$i] + $espacio) / $radio;
            $angulo = $arriba ? $angulo - $paso : $angulo + $paso;
        }
    }

    private static function estrella(Pdf $pdf, float $cx, float $cy, float $radio, string $color): void
    {
        $puntos = [];
        for ($i = 0; $i < 10; $i++) {
            $angulo = -M_PI / 2 + M_PI * $i / 5;
            $r = $i % 2 === 0 ? $radio : $radio * 0.45;
            $puntos[] = [$cx + $r * cos($angulo), $cy + $r * sin($angulo)];
        }
        $pdf->poligono($puntos, $color);
    }
}
