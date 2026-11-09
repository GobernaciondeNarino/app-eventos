<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Una fuente TrueType, leída para escribir con ella en un PDF.
 *
 * Lo justo para el certificado (3.21) y sin bibliotecas: se lee el archivo
 * .ttf, se sacan los anchos de cada letra, el interletrado y las medidas que
 * pide el PDF, y se entrega el programa de la fuente para incrustarlo.
 *
 * La codificación es Windows-1252 (WinAnsiEncoding en el PDF): el español
 * entero —tildes, eñe, diéresis, «comillas»— con un byte por letra, que todos
 * los lectores de PDF entienden sin tablas extra. Lo que no está en esa tabla
 * se translitera («ł» → «l») antes de escribirlo. Ver aCp1252().
 *
 * Las fuentes que trae la plataforma están en app/Recursos/fuentes, recortadas
 * a esos caracteres por herramientas/preparar-fuentes-pdf.py.
 */
final class Fuente
{
    /** Las tablas que necesita un lector de PDF para pintar los glifos. */
    private const TABLAS_DEL_PDF = ['cmap', 'cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'OS/2', 'post', 'prep'];

    /** Los 32 de 0x80 a 0x9F, que es donde Windows-1252 se aparta de Latin-1. */
    private const CP1252_ALTOS = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
        0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
        0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
        0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /** @var array<string, self> una por archivo y petición */
    private static array $cargadas = [];

    private string $datos;
    /** @var array<string, array{0:int, 1:int}> etiqueta => [desplazamiento, largo] */
    private array $tablas = [];

    public string $nombre = '';
    public int $unidades = 1000;
    /** @var array<int, int> código 1252 => ancho, en milésimas de eme */
    public array $anchos = [];
    /** @var array<int, array<int, int>> código izquierdo => [código derecho => ajuste], en milésimas */
    public array $kern = [];
    public int $ascendente = 0;
    public int $descendente = 0;
    public int $altoMayusculas = 0;
    /** @var array{0:int,1:int,2:int,3:int} */
    public array $caja = [0, 0, 0, 0];
    public float $anguloCursiva = 0.0;
    public int $peso = 400;

    private function __construct(string $ruta)
    {
        $datos = @file_get_contents($ruta);
        if ($datos === false || strlen($datos) < 12) {
            throw new \RuntimeException('No se pudo leer la fuente ' . basename($ruta) . '.');
        }
        $this->datos = $datos;
        $this->leerDirectorio();
        $this->leer();
    }

    public static function cargar(string $ruta): self
    {
        return self::$cargadas[$ruta] ??= new self($ruta);
    }

    /**
     * Ancho de un texto ya pasado a 1252, en puntos.
     *
     * $espaciado es el espacio extra entre letras, en puntos, como el «Tc» del
     * PDF: se suma después de cada letra menos la última, que es lo que se ve.
     */
    public function ancho(string $texto, float $tamano, float $espaciado = 0.0, bool $conKern = true): float
    {
        $total = 0;
        $largo = strlen($texto);
        for ($i = 0; $i < $largo; $i++) {
            $codigo = ord($texto[$i]);
            $total += $this->anchos[$codigo] ?? $this->anchos[32] ?? 250;
            if ($conKern && $i + 1 < $largo) {
                $total += $this->kern[$codigo][ord($texto[$i + 1])] ?? 0;
            }
        }
        return $total * $tamano / 1000 + max(0, $largo - 1) * $espaciado;
    }

    /**
     * El programa de la fuente para incrustarlo en el PDF: solo las tablas que
     * hacen falta para pintar. El interletrado ya se aplicó al escribir, así
     * que su tabla —la mitad del archivo— no viaja en cada certificado.
     */
    public function programa(): string
    {
        $etiquetas = array_values(array_filter(
            self::TABLAS_DEL_PDF,
            fn(string $t): bool => isset($this->tablas[$t])
        ));
        sort($etiquetas, SORT_STRING);

        $cuantas = count($etiquetas);
        $potencia = 1;
        $exponente = 0;
        while ($potencia * 2 <= $cuantas) {
            $potencia *= 2;
            $exponente++;
        }
        $cabecera = pack('Nnnnn', 0x00010000, $cuantas, $potencia * 16, $exponente, $cuantas * 16 - $potencia * 16);

        $directorio = '';
        $cuerpo = '';
        $desplazamiento = 12 + 16 * $cuantas;
        foreach ($etiquetas as $etiqueta) {
            [$desde, $largo] = $this->tablas[$etiqueta];
            $tabla = substr($this->datos, $desde, $largo);
            if ($etiqueta === 'head') {
                // checkSumAdjustment se vuelve a calcular abajo.
                $tabla = substr_replace($tabla, "\0\0\0\0", 8, 4);
            }
            $directorio .= $etiqueta . pack('NNN', self::sumaDeControl($tabla), $desplazamiento, $largo);
            $relleno = str_repeat("\0", (4 - $largo % 4) % 4);
            $cuerpo .= $tabla . $relleno;
            $desplazamiento += $largo + strlen($relleno);
        }
        $fuente = $cabecera . $directorio . $cuerpo;

        // El ajuste de la tabla head, para que la suma de todo el archivo dé
        // 0xB1B0AFBA, como pide la especificación.
        $ajuste = (0xB1B0AFBA - self::sumaDeControl($fuente)) & 0xFFFFFFFF;
        $posicionHead = strpos($directorio, 'head');
        if ($posicionHead !== false) {
            $inicioHead = unpack('N', substr($directorio, $posicionHead + 8, 4))[1];
            $fuente = substr_replace($fuente, pack('N', $ajuste), $inicioHead + 8, 4);
        }
        return $fuente;
    }

    /**
     * UTF-8 a Windows-1252, que es como se escribe en el PDF.
     *
     * Lo que no está en la tabla se translitera: «Łukasz» sale «Lukasz» y no
     * «?ukasz». Las letras con el acento como carácter aparte —así llegan a
     * veces desde un teléfono— se juntan antes en su forma de un solo carácter.
     */
    public static function aCp1252(string $texto): string
    {
        $texto = self::componer($texto);
        $texto = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{2028}\x{2029}]+/u', ' ', $texto) ?? '';
        $altos = array_flip(self::CP1252_ALTOS);
        $salida = '';
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
            $punto = mb_ord($letra, 'UTF-8');
            if ($punto === false) {
                continue;
            }
            if ($punto < 0x80 || ($punto >= 0xA0 && $punto <= 0xFF)) {
                $salida .= chr($punto);
            } elseif (isset($altos[$punto])) {
                $salida .= chr($altos[$punto]);
            } else {
                $salida .= self::transliterar($letra);
            }
        }
        return $salida;
    }

    /* =====================================================================
       Lectura del archivo
       ===================================================================== */

    private function leerDirectorio(): void
    {
        $version = $this->u32(0);
        if ($version !== 0x00010000 && $version !== 0x74727565) {
            throw new \RuntimeException('La fuente no es TrueType (las OpenType con contornos CFF no sirven aquí).');
        }
        $cuantas = $this->u16(4);
        for ($i = 0; $i < $cuantas; $i++) {
            $registro = 12 + 16 * $i;
            $etiqueta = substr($this->datos, $registro, 4);
            $this->tablas[$etiqueta] = [$this->u32($registro + 8), $this->u32($registro + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap', 'maxp'] as $necesaria) {
            if (!isset($this->tablas[$necesaria])) {
                throw new \RuntimeException('A la fuente le falta la tabla ' . $necesaria . '.');
            }
        }
    }

    private function leer(): void
    {
        $head = $this->tablas['head'][0];
        $this->unidades = $this->u16($head + 18);
        $escala = 1000 / $this->unidades;
        $this->caja = [
            (int) round($this->i16($head + 36) * $escala), (int) round($this->i16($head + 38) * $escala),
            (int) round($this->i16($head + 40) * $escala), (int) round($this->i16($head + 42) * $escala),
        ];

        $hhea = $this->tablas['hhea'][0];
        $this->ascendente = (int) round($this->i16($hhea + 4) * $escala);
        $this->descendente = (int) round($this->i16($hhea + 6) * $escala);
        $metricas = $this->u16($hhea + 34);

        $this->altoMayusculas = $this->ascendente;
        if (isset($this->tablas['OS/2'])) {
            $os2 = $this->tablas['OS/2'][0];
            $this->peso = $this->u16($os2 + 4);
            if ($this->u16($os2) >= 2 && $this->tablas['OS/2'][1] >= 90) {
                $this->altoMayusculas = (int) round($this->i16($os2 + 88) * $escala);
            }
        }
        if (isset($this->tablas['post'])) {
            $post = $this->tablas['post'][0];
            $this->anguloCursiva = $this->i16($post + 4) + $this->u16($post + 6) / 65536;
        }

        // Anchos de avance de todos los glifos.
        $glifos = $this->u16($this->tablas['maxp'][0] + 4);
        $hmtx = $this->tablas['hmtx'][0];
        $avances = [];
        $ultimo = 0;
        for ($g = 0; $g < $glifos; $g++) {
            if ($g < $metricas) {
                $ultimo = $this->u16($hmtx + 4 * $g);
            }
            $avances[$g] = $ultimo;
        }

        // De cada código 1252, su glifo.
        $deUnicode = $this->leerCmap();
        $glifoDe = [];
        for ($codigo = 32; $codigo <= 255; $codigo++) {
            $punto = self::CP1252_ALTOS[$codigo] ?? ($codigo >= 0x80 && $codigo < 0xA0 ? null : $codigo);
            $glifo = $punto !== null ? ($deUnicode[$punto] ?? 0) : 0;
            $glifoDe[$codigo] = $glifo;
            $this->anchos[$codigo] = (int) round(($avances[$glifo] ?? 0) * $escala);
        }

        $this->nombre = $this->leerNombre();
        $this->leerKern($glifoDe, $escala);
    }

    /** @return array<int, int> punto Unicode => glifo, solo de los que importan */
    private function leerCmap(): array
    {
        $cmap = $this->tablas['cmap'][0];
        $subtablas = $this->u16($cmap + 2);
        $elegida = null;
        for ($i = 0; $i < $subtablas; $i++) {
            $registro = $cmap + 4 + 8 * $i;
            $plataforma = $this->u16($registro);
            $codificacion = $this->u16($registro + 2);
            $desde = $cmap + $this->u32($registro + 4);
            if ($this->u16($desde) !== 4) {
                continue;
            }
            if ($plataforma === 3 && $codificacion === 1) {
                $elegida = $desde;
                break;
            }
            if ($plataforma === 0) {
                $elegida ??= $desde;
            }
        }
        if ($elegida === null) {
            throw new \RuntimeException('La fuente no tiene un mapa de caracteres Unicode (cmap formato 4).');
        }

        $buscados = array_merge(range(32, 126), range(0xA0, 0xFF), array_values(self::CP1252_ALTOS));
        $segmentos = $this->u16($elegida + 6) / 2;
        $finales = $elegida + 14;
        $iniciales = $finales + 2 * $segmentos + 2;
        $deltas = $iniciales + 2 * $segmentos;
        $rangos = $deltas + 2 * $segmentos;

        $mapa = [];
        foreach ($buscados as $punto) {
            for ($s = 0; $s < $segmentos; $s++) {
                if ($this->u16($finales + 2 * $s) < $punto) {
                    continue;
                }
                $inicio = $this->u16($iniciales + 2 * $s);
                if ($inicio > $punto) {
                    break;
                }
                $delta = $this->i16($deltas + 2 * $s);
                $rango = $this->u16($rangos + 2 * $s);
                if ($rango === 0) {
                    $glifo = ($punto + $delta) & 0xFFFF;
                } else {
                    $glifo = $this->u16($rangos + 2 * $s + $rango + 2 * ($punto - $inicio));
                    if ($glifo !== 0) {
                        $glifo = ($glifo + $delta) & 0xFFFF;
                    }
                }
                if ($glifo !== 0) {
                    $mapa[$punto] = $glifo;
                }
                break;
            }
        }
        return $mapa;
    }

    /** El nombre PostScript, que es como el PDF llama a la fuente. */
    private function leerNombre(): string
    {
        if (!isset($this->tablas['name'])) {
            return 'Fuente';
        }
        $name = $this->tablas['name'][0];
        $cuantos = $this->u16($name + 2);
        $textos = $name + $this->u16($name + 4);
        $encontrado = '';
        for ($i = 0; $i < $cuantos; $i++) {
            $registro = $name + 6 + 12 * $i;
            if ($this->u16($registro + 6) !== 6) {
                continue;
            }
            $crudo = substr($this->datos, $textos + $this->u16($registro + 10), $this->u16($registro + 8));
            $encontrado = $this->u16($registro) === 3 ? (string) mb_convert_encoding($crudo, 'UTF-8', 'UTF-16BE') : $crudo;
            if ($encontrado !== '') {
                break;
            }
        }
        $limpio = preg_replace('/[^A-Za-z0-9_-]/', '', $encontrado) ?? '';
        return $limpio !== '' ? $limpio : 'Fuente';
    }

    /** @param array<int, int> $glifoDe código 1252 => glifo */
    private function leerKern(array $glifoDe, float $escala): void
    {
        if (!isset($this->tablas['kern'])) {
            return;
        }
        $codigosDe = [];
        foreach ($glifoDe as $codigo => $glifo) {
            if ($glifo !== 0) {
                $codigosDe[$glifo][] = $codigo;
            }
        }

        $kern = $this->tablas['kern'][0];
        if ($this->u16($kern) !== 0) {
            return;     // la versión de Apple, que estas fuentes no usan
        }
        $subtablas = $this->u16($kern + 2);
        $desde = $kern + 4;
        for ($t = 0; $t < $subtablas; $t++) {
            $largo = $this->u16($desde + 2);
            $cobertura = $this->u16($desde + 4);
            // Formato 0 y horizontal; las otras no se usan en un texto así.
            if (($cobertura >> 8) === 0 && ($cobertura & 1) === 1) {
                $pares = $this->u16($desde + 6);
                $registro = $desde + 14;
                for ($i = 0; $i < $pares; $i++, $registro += 6) {
                    $izquierda = $codigosDe[$this->u16($registro)] ?? null;
                    $derecha = $codigosDe[$this->u16($registro + 2)] ?? null;
                    if ($izquierda === null || $derecha === null) {
                        continue;
                    }
                    $valor = (int) round($this->i16($registro + 4) * $escala);
                    foreach ($izquierda as $a) {
                        foreach ($derecha as $b) {
                            $this->kern[$a][$b] = $valor;
                        }
                    }
                }
            }
            // Una tabla con más de 10 900 pares no cabe en el largo de 16
            // bits: entonces el largo miente y se calcula con los pares.
            $desde += max($largo, 14 + 6 * $this->u16($desde + 6));
        }
    }

    /* =====================================================================
       Utilidades
       ===================================================================== */

    private static function sumaDeControl(string $datos): int
    {
        $datos .= str_repeat("\0", (4 - strlen($datos) % 4) % 4);
        $suma = 0;
        foreach (unpack('N*', $datos) ?: [] as $palabra) {
            $suma = ($suma + $palabra) & 0xFFFFFFFF;
        }
        return $suma;
    }

    /** Las letras con el acento aparte, juntas en un solo carácter. */
    private static function componer(string $texto): string
    {
        if (class_exists(\Normalizer::class)) {
            $compuesto = \Normalizer::normalize($texto, \Normalizer::FORM_C);
            return is_string($compuesto) ? $compuesto : $texto;
        }
        // Sin la extensión intl: las combinaciones del español, a mano.
        static $tabla = null;
        $tabla ??= (static function (): array {
            $marcas = ["\u{0301}" => ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú', 'A' => 'Á', 'E' => 'É', 'I' => 'Í', 'O' => 'Ó', 'U' => 'Ú'],
                       "\u{0303}" => ['n' => 'ñ', 'N' => 'Ñ', 'a' => 'ã', 'o' => 'õ', 'A' => 'Ã', 'O' => 'Õ'],
                       "\u{0308}" => ['u' => 'ü', 'U' => 'Ü', 'e' => 'ë', 'E' => 'Ë', 'i' => 'ï', 'o' => 'ö', 'a' => 'ä'],
                       "\u{0300}" => ['a' => 'à', 'e' => 'è', 'o' => 'ò', 'A' => 'À', 'E' => 'È', 'O' => 'Ò'],
                       "\u{0327}" => ['c' => 'ç', 'C' => 'Ç']];
            $pares = [];
            foreach ($marcas as $marca => $letras) {
                foreach ($letras as $base => $junta) {
                    $pares[$base . $marca] = $junta;
                }
            }
            return $pares;
        })();
        return strtr($texto, $tabla);
    }

    private static function transliterar(string $letra): string
    {
        $comillas = [
            "\u{2032}" => "'", "\u{2033}" => '"', "\u{2010}" => '-', "\u{2011}" => '-', "\u{2212}" => '-',
            "\u{2009}" => ' ', "\u{200A}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{202F}" => "\xA0",
        ];
        if (isset($comillas[$letra])) {
            return $comillas[$letra];
        }
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $letra);
            if (is_string($ascii) && $ascii !== '' && !str_contains($ascii, '?')) {
                return $ascii;
            }
        }
        return '?';
    }

    private function u16(int $en): int
    {
        return unpack('n', $this->datos, $en)[1];
    }

    private function i16(int $en): int
    {
        $v = $this->u16($en);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $en): int
    {
        return unpack('N', $this->datos, $en)[1];
    }
}
