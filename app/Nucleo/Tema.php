<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Identidad visual del evento.
 *
 * Traduce la fila de evento_tema a variables CSS que se imprimen en el <head>.
 * Ese es todo el mecanismo de personalización: las hojas de estilo no conocen
 * ningún color, solo nombres de variable, así que cambiar la paleta de un
 * evento no toca ni una línea de CSS.
 *
 * Los presets están duplicados en assets/js/tema.js, que los usa para la
 * previsualización en vivo del panel de identidad. El lado que manda es este:
 * lo que se guarda y lo que se pinta sale de aquí.
 */
final class Tema
{
    public const PRESETS = [
        'tic-nocturno' => [
            'nombre' => 'TIC Nocturno',
            'descripcion' => 'Fondo profundo y cian de alta visibilidad.',
            'colores' => [
                'brand' => '#0C2E3C', 'accent' => '#35E0F5', 'bg' => '#050D15',
                'surface' => '#08151F', 'sunken' => '#05101A', 'line' => '#1E4557',
                'title' => '#E4F7FD', 'text' => '#89AEC0', 'muted' => '#4E7285',
                'onBrand' => '#E4F7FD',
            ],
        ],
        'narino-verde' => [
            'nombre' => 'Nariño Verde',
            'descripcion' => 'Verde territorial, para eventos de conectividad rural.',
            'colores' => [
                'brand' => '#123A31', 'accent' => '#7CF7C8', 'bg' => '#07120F',
                'surface' => '#0A1B17', 'sunken' => '#061310', 'line' => '#22463A',
                'title' => '#E6FBF3', 'text' => '#93BCAB', 'muted' => '#4E7A69',
                'onBrand' => '#E6FBF3',
            ],
        ],
        'institucional-azul' => [
            'nombre' => 'Institucional Azul',
            'descripcion' => 'Azul de gobierno, sobrio, alineado a la imagen departamental.',
            'colores' => [
                'brand' => '#12324F', 'accent' => '#4EA8FF', 'bg' => '#0A0F1A',
                'surface' => '#101827', 'sunken' => '#0B111C', 'line' => '#243449',
                'title' => '#EAF2FF', 'text' => '#8FA3B8', 'muted' => '#566B84',
                'onBrand' => '#EAF2FF',
            ],
        ],
        'creativa-magenta' => [
            'nombre' => 'Creativa Magenta',
            'descripcion' => 'Para economía creativa y contenidos digitales.',
            'colores' => [
                'brand' => '#2B1F3D', 'accent' => '#FF5FD1', 'bg' => '#100D1A',
                'surface' => '#191330', 'sunken' => '#120E22', 'line' => '#3A2E4A',
                'title' => '#F6ECFF', 'text' => '#AFA3C4', 'muted' => '#6E6187',
                'onBrand' => '#F6ECFF',
            ],
        ],
        'claro-institucional' => [
            'nombre' => 'Claro Institucional',
            'descripcion' => 'Fondo blanco, pensado para proyección y material impreso.',
            'colores' => [
                'brand' => '#0C4A6E', 'accent' => '#0284C7', 'bg' => '#F4F8FB',
                'surface' => '#FFFFFF', 'sunken' => '#F0F5F9', 'line' => '#C9DCE8',
                'title' => '#0B2534', 'text' => '#44647A', 'muted' => '#6E8A9C',
                'onBrand' => '#FFFFFF',
            ],
        ],
    ];

    public const TIPOGRAFIAS = [
        'tecnologica'   => ['nombre' => 'Tecnológica',   'muestra' => 'Chakra Petch · IBM Plex'],
        'institucional' => ['nombre' => 'Institucional', 'muestra' => 'Barlow Condensed · Source Sans'],
        'neutra'        => ['nombre' => 'Neutra',        'muestra' => 'Inter · JetBrains Mono'],
        'editorial'     => ['nombre' => 'Editorial',     'muestra' => 'Space Grotesk · IBM Plex'],
    ];

    /** Variable CSS por cada color, y cuáles necesitan además su terna RGB. */
    private const VARIABLES = [
        'brand' => '--c-brand', 'accent' => '--c-accent', 'bg' => '--c-bg',
        'surface' => '--c-surface', 'sunken' => '--c-sunken', 'line' => '--c-line',
        'title' => '--c-title', 'text' => '--c-text', 'muted' => '--c-muted',
        'onBrand' => '--c-on-brand',
    ];
    private const CON_RGB = [
        'brand' => '--c-brand-rgb', 'accent' => '--c-accent-rgb', 'bg' => '--c-bg-rgb',
        'surface' => '--c-surface-rgb', 'line' => '--c-line-rgb',
    ];

    private static array $cache = [];

    public static function del(int $eventoId): array
    {
        if (isset(self::$cache[$eventoId])) {
            return self::$cache[$eventoId];
        }

        $fila = null;
        if ($eventoId > 0) {
            try {
                $fila = Bd::fila('SELECT * FROM {evento_tema} WHERE evento_id = ?', [$eventoId]);
            } catch (\Throwable) {
                $fila = null;   // durante la instalación todavía no hay tabla
            }
        }

        $preset = $fila['preset'] ?? 'tic-nocturno';
        if (!isset(self::PRESETS[$preset])) {
            $preset = 'tic-nocturno';
        }
        $tipografia = $fila['tipografia'] ?? 'tecnologica';
        if (!isset(self::TIPOGRAFIAS[$tipografia])) {
            $tipografia = 'tecnologica';
        }

        $propios = [];
        if (!empty($fila['colores_json'])) {
            $decodificado = json_decode((string) $fila['colores_json'], true);
            if (is_array($decodificado)) {
                foreach ($decodificado as $clave => $valor) {
                    if (isset(self::VARIABLES[$clave]) && self::hexValido((string) $valor)) {
                        $propios[$clave] = strtoupper((string) $valor);
                    }
                }
            }
        }

        return self::$cache[$eventoId] = [
            'evento_id'   => $eventoId,
            'preset'      => $preset,
            'tipografia'  => $tipografia,
            'colores'     => $propios + self::PRESETS[$preset]['colores'],
            'propios'     => $propios,
            'logo'        => (string) ($fila['logo_archivo'] ?? ''),
            'logo_tipo'   => (string) ($fila['logo_tipo'] ?? ''),
        ];
    }

    public static function olvidar(int $eventoId): void
    {
        unset(self::$cache[$eventoId]);
    }

    public static function hexValido(string $hex): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex);
    }

    private static function aRgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /* =====================================================================
       Colores para el papel
       ===================================================================== */

    /**
     * El color de cada perfil: los mismos de assets/css/tokens.css. Hacen falta
     * aquí para el carnet impreso. El participante y los perfiles que agregue
     * cada evento llevan el acento del evento.
     */
    public const COLORES_DE_ROL = [
        'visitante'         => '#9BE7FF',
        'expositor'         => '#FFB84D',
        'organizador'       => '#7CF7C8',
        'prensa'            => '#FF5FD1',
        'staff'             => '#C8B5FF',
        'rueda_de_negocios' => '#FFE066',
        'comunicaciones'    => '#FF8A80',
    ];

    /**
     * Un color oscurecido lo justo para leerse sobre papel blanco.
     *
     * La paleta se pensó para la pantalla, casi siempre sobre fondo oscuro: el
     * cian del acento o el amarillo de un perfil, impresos en blanco, no se
     * leen. Se mezcla con negro hasta alcanzar el contraste pedido; un color que
     * ya lo tiene queda igual.
     */
    public static function paraPapel(string $hex, float $minimo = 4.5): string
    {
        [$r, $g, $b] = self::aRgb(self::hexValido($hex) ? $hex : '#0284C7');
        for ($paso = 0; $paso < 20; $paso++) {
            $factor = 1 - $paso * 0.05;
            $color = sprintf('#%02X%02X%02X', (int) round($r * $factor), (int) round($g * $factor), (int) round($b * $factor));
            if (self::contraste($color, '#FFFFFF') >= $minimo) {
                return $color;
            }
        }
        return '#000000';
    }

    /** Mezcla con blanco: 0 deja el color, 1 lo vuelve blanco. Para fondos y filetes suaves en papel. */
    public static function aclarar(string $hex, float $cuanto): string
    {
        [$r, $g, $b] = self::aRgb(self::hexValido($hex) ? $hex : '#000000');
        $mezcla = static fn(int $c): int => (int) round($c + (255 - $c) * max(0.0, min(1.0, $cuanto)));
        return sprintf('#%02X%02X%02X', $mezcla($r), $mezcla($g), $mezcla($b));
    }

    /**
     * Los tres colores del carnet impreso: el del evento y el del perfil, ya
     * aptos para papel, y uno suave para fondos y filetes.
     *
     * @return array{tinta: string, rol: string, suave: string}
     */
    public static function delCarnetImpreso(array $tema, string $rol): array
    {
        $tinta = self::paraPapel((string) ($tema['colores']['accent'] ?? '#0284C7'));
        return [
            'tinta' => $tinta,
            'rol'   => isset(self::COLORES_DE_ROL[$rol]) ? self::paraPapel(self::COLORES_DE_ROL[$rol]) : $tinta,
            'suave' => self::aclarar($tinta, 0.78),
        ];
    }

    /**
     * Bloque <style> con las variables del evento.
     *
     * Los valores se validan como hexadecimal antes de llegar aquí y se vuelven
     * a comprobar: es contenido que termina dentro de una etiqueta <style>, y
     * un valor arbitrario ahí es una vía de inyección aunque no ejecute
     * JavaScript.
     */
    public static function estilo(array $tema): string
    {
        $lineas = [];
        foreach ($tema['colores'] as $clave => $hex) {
            if (!isset(self::VARIABLES[$clave]) || !self::hexValido((string) $hex)) {
                continue;
            }
            $lineas[] = self::VARIABLES[$clave] . ':' . $hex . ';';
            if (isset(self::CON_RGB[$clave])) {
                $lineas[] = self::CON_RGB[$clave] . ':' . implode(', ', self::aRgb((string) $hex)) . ';';
            }
        }
        return ':root{' . implode('', $lineas) . '}';
    }

    /** Guarda la identidad de un evento. */
    public static function guardar(int $eventoId, array $datos): void
    {
        $preset = isset(self::PRESETS[$datos['preset'] ?? '']) ? $datos['preset'] : 'tic-nocturno';
        $tipografia = isset(self::TIPOGRAFIAS[$datos['tipografia'] ?? '']) ? $datos['tipografia'] : 'tecnologica';

        $colores = [];
        foreach (($datos['colores'] ?? []) as $clave => $valor) {
            if (isset(self::VARIABLES[$clave]) && self::hexValido((string) $valor)) {
                $colores[$clave] = strtoupper((string) $valor);
            }
        }
        // Solo se guardan los que se apartan del preset: así, al cambiar de
        // paleta base, no arrastra ajustes viejos que nadie recuerda.
        $base = self::PRESETS[$preset]['colores'];
        $colores = array_filter(
            $colores,
            static fn($v, $k) => strcasecmp($v, $base[$k] ?? '') !== 0,
            ARRAY_FILTER_USE_BOTH
        );

        $existe = Bd::valor('SELECT COUNT(*) FROM {evento_tema} WHERE evento_id = ?', [$eventoId]);
        $campos = [
            'preset'       => $preset,
            'tipografia'   => $tipografia,
            'colores_json' => $colores ? json_encode($colores) : null,
        ];
        if (array_key_exists('logo_archivo', $datos)) {
            $campos['logo_archivo'] = (string) $datos['logo_archivo'];
            $campos['logo_tipo'] = (string) ($datos['logo_tipo'] ?? '');
        }

        if ((int) $existe > 0) {
            Bd::actualizar('evento_tema', $campos, 'evento_id = :ev', ['ev' => $eventoId]);
        } else {
            Bd::insertar('evento_tema', $campos + ['evento_id' => $eventoId]);
        }
        self::olvidar($eventoId);
    }

    /* =====================================================================
       Contraste (WCAG 2.1)
       ===================================================================== */

    private static function luminancia(string $hex): float
    {
        $canales = array_map(static function (int $v): float {
            $x = $v / 255;
            return $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;
        }, self::aRgb($hex));
        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Combinaciones que se comprueban en el panel de identidad. */
    public static function revisarContraste(array $colores): array
    {
        $pares = [
            ['Títulos sobre el fondo', 'title', 'bg', 4.5],
            ['Texto sobre las tarjetas', 'text', 'surface', 4.5],
            ['Etiquetas sobre las tarjetas', 'muted', 'surface', 3.0],
            ['Énfasis sobre las tarjetas', 'accent', 'surface', 3.0],
            ['Texto sobre el color principal', 'onBrand', 'brand', 4.5],
        ];

        $resultado = [];
        foreach ($pares as [$etiqueta, $a, $b, $minimo]) {
            $razon = self::contraste($colores[$a], $colores[$b]);
            $resultado[] = [
                'etiqueta' => $etiqueta,
                'razon'    => round($razon, 2),
                'minimo'   => $minimo,
                'cumple'   => $razon >= $minimo,
            ];
        }
        return $resultado;
    }
}
