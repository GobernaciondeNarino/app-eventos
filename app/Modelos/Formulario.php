<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Nucleo\Bd;

/**
 * Cómo es el formulario de registro de cada evento.
 *
 * Qué campos se piden, cuáles son obligatorios, qué opciones trae cada lista
 * desplegable y si arriba va un banner. Lo decide un administrador desde
 * Configuración → Registro, y vale para un evento: la cumbre puede pedir la
 * entidad y un taller abierto no pedir la cédula.
 *
 * Mientras nadie toque nada, el formulario es exactamente el de siempre: los
 * valores por omisión salen de App\Datos y de lo que la plataforma hacía hasta
 * la 3.6. Así una instalación que se actualiza no cambia ni una coma.
 *
 * Tres reglas que no se configuran, a propósito:
 *
 *   · El correo, el nombre y la autorización de tratamiento de datos se piden
 *     siempre. Sin correo no hay forma de entrar; sin nombre, carnet; y sin
 *     autorización, la Ley 1581 no deja guardar nada.
 *
 *   · Los datos sensibles —género, pertenencia étnica, discapacidad— se pueden
 *     pedir o no, pero nunca exigir. El artículo 6 de la Ley 1581 deja al
 *     titular la libertad de no responderlos.
 *
 *   · Cambiar las opciones de una lista no toca lo que ya está guardado: se
 *     guarda el valor, no la posición, y una opción quitada sigue
 *     apareciendo en los datos de quien la eligió.
 */
final class Formulario
{
    public const OCULTO = 'oculto';
    public const OPCIONAL = 'opcional';
    public const OBLIGATORIO = 'obligatorio';

    private const TRES = [self::OBLIGATORIO, self::OPCIONAL, self::OCULTO];
    private const DOS = [self::OPCIONAL, self::OCULTO];

    /**
     * Los campos que se pueden configurar, en el orden del formulario.
     *
     * 'estados' son los que admite cada uno. Los de dos estados se muestran
     * como «visible» u «oculto»: un perfil o una casilla no tienen sentido como
     * «obligatorios».
     */
    public const CAMPOS = [
        'documento' => [
            'etiqueta' => 'Tipo y número de identificación', 'seccion' => 'Datos principales',
            'estados'  => self::TRES, 'defecto' => self::OBLIGATORIO,
            'nota'     => 'Sin él no se puede acreditar a nadie por su número ni detectar a quien se registra dos veces.',
        ],
        'telefono' => [
            'etiqueta' => 'Teléfono de contacto', 'seccion' => 'Datos principales',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
        'rol' => [
            'etiqueta' => 'Perfil de asistencia', 'seccion' => 'Datos principales',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL,
            'nota'     => 'Oculto, todo el mundo queda como participante; el perfil lo cambia un administrador desde la ficha.',
        ],
        'foto' => [
            'etiqueta' => 'Fotografía del carnet', 'seccion' => 'Fotografía',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
        'entidad' => [
            'etiqueta' => 'Entidad u organización', 'seccion' => 'Caracterización',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
        'rango_edad' => [
            'etiqueta' => 'Rango de edad', 'seccion' => 'Caracterización',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
        'ubicacion' => [
            'etiqueta' => 'Departamento y municipio', 'seccion' => 'Caracterización',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
        'genero' => [
            'etiqueta' => 'Género', 'seccion' => 'Caracterización',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL, 'sensible' => true,
        ],
        'etnia' => [
            'etiqueta' => 'Grupo étnico', 'seccion' => 'Caracterización',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL, 'sensible' => true,
        ],
        'discapacidad' => [
            'etiqueta' => 'Discapacidad', 'seccion' => 'Caracterización',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL, 'sensible' => true,
        ],
        'expositor' => [
            'etiqueta' => 'Propuestas de exposición', 'seccion' => 'Perfil expositor',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL,
            'nota'     => 'Oculto, nadie puede mandar una propuesta desde el formulario; las que ya llegaron no se tocan.',
        ],
        'dia_preferido' => [
            'etiqueta' => 'Día preferido', 'seccion' => 'Perfil expositor',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL,
        ],
        'duracion' => [
            'etiqueta' => 'Duración', 'seccion' => 'Perfil expositor',
            'estados'  => self::DOS, 'defecto' => self::OPCIONAL,
        ],
        'requerimientos' => [
            'etiqueta' => 'Requerimientos técnicos', 'seccion' => 'Perfil expositor',
            'estados'  => self::TRES, 'defecto' => self::OPCIONAL,
        ],
    ];

    /** Los que se piden siempre. Se muestran en la configuración, pero no se cambian. */
    public const FIJOS = ['Correo electrónico', 'Nombre completo', 'Autorización de tratamiento de datos'];

    /**
     * Las listas desplegables.
     *
     *   codigos     cada opción tiene un valor que se guarda y una etiqueta que
     *               se ve; las de fábrica conservan su valor para siempre.
     *   texto       lo que se ve es lo que se guarda; una por línea.
     *   perfiles    los perfiles públicos que se ofrecen; no se inventan otros,
     *               porque cada uno hace algo distinto en la plataforma.
     *   territorio  departamentos con sus municipios.
     *   minutos     duraciones de las exposiciones.
     *
     * 'largo' es el de la columna donde se guarda el valor.
     */
    public const LISTAS = [
        'tipo_documento' => ['etiqueta' => 'Tipos de documento', 'clase' => 'codigos', 'largo' => 12, 'campo' => 'documento'],
        'perfil'         => ['etiqueta' => 'Perfiles de asistencia', 'clase' => 'perfiles', 'campo' => 'rol'],
        'genero'         => ['etiqueta' => 'Género', 'clase' => 'codigos', 'largo' => 60, 'campo' => 'genero'],
        'etnia'          => ['etiqueta' => 'Grupo étnico', 'clase' => 'codigos', 'largo' => 60, 'campo' => 'etnia'],
        'discapacidad'   => ['etiqueta' => 'Discapacidad', 'clase' => 'codigos', 'largo' => 60, 'campo' => 'discapacidad'],
        'rango_edad'     => ['etiqueta' => 'Rangos de edad', 'clase' => 'texto', 'largo' => 40, 'campo' => 'rango_edad'],
        'ubicacion'      => ['etiqueta' => 'Departamentos y municipios', 'clase' => 'territorio', 'largo' => 80, 'campo' => 'ubicacion'],
        'categoria'      => ['etiqueta' => 'Categorías de las propuestas', 'clase' => 'texto', 'largo' => 80, 'campo' => 'expositor'],
        'duracion'       => ['etiqueta' => 'Duraciones de las exposiciones', 'clase' => 'minutos', 'campo' => 'duracion'],
    ];

    /** @var array<int, self> */
    private static array $cache = [];

    /**
     * @param array<string, string> $campos estado de cada campo
     * @param array<string, mixed>  $listas opciones de cada lista, en su forma guardada
     * @param array<string, mixed>  $banner
     */
    private function __construct(
        private int $eventoId,
        private array $campos,
        private array $listas,
        private array $banner,
        private bool $personalizado
    ) {
    }

    /* =====================================================================
       Lectura
       ===================================================================== */

    public static function delEvento(int $eventoId): self
    {
        if (isset(self::$cache[$eventoId])) {
            return self::$cache[$eventoId];
        }

        $fila = null;
        if ($eventoId > 0) {
            try {
                $fila = Bd::fila('SELECT * FROM {evento_formulario} WHERE evento_id = ?', [$eventoId]);
            } catch (\Throwable) {
                // Una base que todavía no tiene la tabla —la actualización
                // automática corre en la primera visita, pero puede estar
                // apagada—: el formulario de siempre, que es lo que había.
                $fila = null;
            }
        }

        $campos = [];
        $guardados = json_decode((string) ($fila['campos'] ?? ''), true);
        foreach (self::CAMPOS as $clave => $definicion) {
            $estado = is_array($guardados) ? (string) ($guardados[$clave] ?? '') : '';
            $campos[$clave] = in_array($estado, $definicion['estados'], true) ? $estado : $definicion['defecto'];
        }

        $listas = [];
        $guardadas = json_decode((string) ($fila['listas'] ?? ''), true);
        foreach (array_keys(self::LISTAS) as $clave) {
            $valor = is_array($guardadas) ? ($guardadas[$clave] ?? null) : null;
            $listas[$clave] = self::listaValida($clave, $valor) ?? self::listaPorDefecto($clave);
        }

        $banner = [
            'activo' => (bool) ($fila['banner_activo'] ?? false),
            'imagen' => (string) ($fila['banner_imagen'] ?? ''),
            'tipo'   => (string) ($fila['banner_tipo'] ?? ''),
            'titulo' => (string) ($fila['banner_titulo'] ?? ''),
            'texto'  => (string) ($fila['banner_texto'] ?? ''),
            'alt'    => (string) ($fila['banner_alt'] ?? ''),
        ];

        // «Propio» es que algo difiera del de fábrica, no que exista la fila: la
        // fila queda también cuando solo hay banner, o después de restablecer.
        $personalizado = $campos !== self::camposPorDefecto();
        foreach ($listas as $clave => $lista) {
            $personalizado = $personalizado || $lista != self::listaPorDefecto($clave);
        }

        return self::$cache[$eventoId] = new self($eventoId, $campos, $listas, $banner, $personalizado);
    }

    /** Para las pruebas y para después de guardar: que la próxima lectura vaya a la base. */
    public static function olvidar(): void
    {
        self::$cache = [];
    }

    public function estado(string $campo): string
    {
        return $this->campos[$campo] ?? self::OPCIONAL;
    }

    public function visible(string $campo): bool
    {
        return $this->estado($campo) !== self::OCULTO;
    }

    public function obligatorio(string $campo): bool
    {
        return $this->estado($campo) === self::OBLIGATORIO;
    }

    /** ¿Alguien cambió algo, o es el formulario de fábrica? */
    public function personalizado(): bool
    {
        return $this->personalizado;
    }

    /**
     * Las opciones activas de una lista de códigos, como [valor => etiqueta].
     *
     * $actual es lo que ya tiene guardado quien edita sus datos. Si esa opción
     * se desactivó o se quitó después, se añade igual: si no, el navegador
     * mandaría la primera de la lista y al guardar su teléfono la persona
     * perdería, sin enterarse, lo que había respondido.
     *
     * @return array<string, string>
     */
    public function opciones(string $lista, string $actual = ''): array
    {
        $salida = [];
        foreach ((array) ($this->listas[$lista] ?? []) as $opcion) {
            if (!empty($opcion['activo'])) {
                $salida[(string) $opcion['valor']] = (string) $opcion['etiqueta'];
            }
        }
        if ($actual !== '' && !isset($salida[$actual])) {
            $salida[$actual] = $this->etiqueta($lista, $actual);
        }
        return $salida;
    }

    /** La etiqueta de un valor guardado, aunque ya no esté activo. Para las fichas. */
    public function etiqueta(string $lista, string $valor): string
    {
        foreach ((array) ($this->listas[$lista] ?? []) as $opcion) {
            if (is_array($opcion) && (string) ($opcion['valor'] ?? '') === $valor) {
                return (string) $opcion['etiqueta'];
            }
        }
        return $valor;
    }

    /** ¿Es una opción que se puede elegir? */
    public function opcionValida(string $lista, string $valor, string $actual = ''): bool
    {
        return array_key_exists($valor, $this->opciones($lista, $actual));
    }

    /** @return array<int, string> */
    public function texto(string $lista, string $actual = ''): array
    {
        $salida = array_values(array_map('strval', (array) ($this->listas[$lista] ?? [])));
        if ($actual !== '' && !in_array($actual, $salida, true)) {
            $salida[] = $actual;
        }
        return $salida;
    }

    /** @return array<int, string> los perfiles públicos que se ofrecen */
    public function perfiles(string $actual = ''): array
    {
        $salida = [];
        foreach (Persona::ROLES_PUBLICOS as $rol) {
            if (!empty($this->listas['perfil'][$rol])) {
                $salida[] = $rol;
            }
        }
        if ($actual !== '' && in_array($actual, Persona::ROLES_PUBLICOS, true) && !in_array($actual, $salida, true)) {
            $salida[] = $actual;
        }
        return $salida;
    }

    /** @return array<int, string> */
    public function departamentos(string $actual = ''): array
    {
        $salida = array_keys((array) $this->listas['ubicacion']);
        if ($actual !== '' && !in_array($actual, $salida, true)) {
            $salida[] = $actual;
        }
        return $salida;
    }

    /** @return array<int, string> */
    public function municipiosDe(string $departamento, string $actual = ''): array
    {
        $lista = array_values(array_unique((array) ($this->listas['ubicacion'][$departamento] ?? [])));
        sort($lista, SORT_LOCALE_STRING);
        if ($actual !== '' && !in_array($actual, $lista, true)) {
            $lista[] = $actual;
        }
        return $lista;
    }

    /** ¿Es un par departamento-municipio que se puede elegir? */
    public function territorioValido(string $departamento, string $municipio, array $actual = []): bool
    {
        if ($departamento === '' && $municipio === '') {
            return true;
        }
        // Lo que ya tenía guardado vale aunque la lista haya cambiado.
        if ($departamento === (string) ($actual['departamento'] ?? '')
            && $municipio === (string) ($actual['municipio'] ?? '')) {
            return true;
        }
        return in_array($municipio, (array) ($this->listas['ubicacion'][$departamento] ?? []), true);
    }

    /** @return array<int, int> */
    public function duraciones(int $actual = 0): array
    {
        $salida = array_map('intval', (array) $this->listas['duracion']);
        if ($actual > 0 && !in_array($actual, $salida, true)) {
            $salida[] = $actual;
            sort($salida);
        }
        return $salida;
    }

    /**
     * El banner, si está encendido y tiene algo que mostrar.
     *
     * 'version' cambia con cada imagen nueva: va en la dirección para que el
     * navegador no siga enseñando la anterior desde su caché.
     *
     * @return array{imagen: string, version: string, titulo: string, texto: string, alt: string}|null
     */
    public function banner(): ?array
    {
        $b = $this->banner;
        if (!$b['activo'] || ($b['imagen'] === '' && trim($b['titulo']) === '' && trim($b['texto']) === '')) {
            return null;
        }
        return [
            'imagen'  => $b['imagen'] !== '' ? '/medios/banner/' . $this->eventoId : '',
            'version' => substr(md5($b['imagen']), 0, 10),
            'titulo'  => $b['titulo'],
            'texto'   => $b['texto'],
            'alt'     => $b['alt'],
        ];
    }

    /** Todo, tal cual, para la pantalla de configuración. */
    public function paraEditar(): array
    {
        return ['campos' => $this->campos, 'listas' => $this->listas, 'banner' => $this->banner];
    }

    /**
     * Los campos obligatorios, en palabras, para el encabezado del formulario.
     *
     * @return array<int, string>
     */
    public function obligatoriosEnPalabras(): array
    {
        $lista = ['el correo', 'el nombre'];
        $nombres = [
            'documento' => 'la identificación', 'telefono' => 'el teléfono', 'foto' => 'la fotografía',
            'entidad' => 'la entidad', 'rango_edad' => 'el rango de edad', 'ubicacion' => 'el municipio',
        ];
        foreach ($nombres as $campo => $palabra) {
            if ($this->obligatorio($campo)) {
                $lista[] = $palabra;
            }
        }
        return $lista;
    }

    /* =====================================================================
       Escritura
       ===================================================================== */

    /**
     * Guarda los estados de los campos y las listas.
     *
     * Las listas llegan ya convertidas por leerListas(). Lo que no se reconoce
     * se descarta en silencio y vuelve a su valor por omisión: un formulario
     * manipulado no puede dejar el registro sin, por ejemplo, ningún tipo de
     * documento que elegir.
     *
     * @param array<string, string> $campos
     * @param array<string, mixed>  $listas
     */
    public static function guardar(int $eventoId, array $campos, array $listas, ?int $usuarioId): void
    {
        $limpios = [];
        foreach (self::CAMPOS as $clave => $definicion) {
            $estado = (string) ($campos[$clave] ?? $definicion['defecto']);
            $limpios[$clave] = in_array($estado, $definicion['estados'], true) ? $estado : $definicion['defecto'];
        }

        // Solo se guarda lo que difiere de fábrica. La pantalla manda todas las
        // listas en cada envío —también cuando solo se cambió el banner—, y
        // guardarlas enteras las dejaría congeladas: el municipio o la opción
        // que agregue una versión nueva no le llegaría nunca a este evento.
        $propias = [];
        foreach (array_keys(self::LISTAS) as $clave) {
            $lista = self::listaValida($clave, $listas[$clave] ?? null) ?? self::listaPorDefecto($clave);
            if ($lista != self::listaPorDefecto($clave)) {
                $propias[$clave] = $lista;
            }
        }

        self::escribir($eventoId, [
            'campos' => $limpios === self::camposPorDefecto() ? null : json_encode($limpios, JSON_UNESCAPED_UNICODE),
            'listas' => $propias === [] ? null : json_encode($propias, JSON_UNESCAPED_UNICODE),
        ], $usuarioId);
    }

    /** @param array{activo?: bool, imagen?: string, tipo?: string, titulo?: string, texto?: string, alt?: string} $banner */
    public static function guardarBanner(int $eventoId, array $banner, ?int $usuarioId): void
    {
        $columnas = [];
        if (array_key_exists('activo', $banner)) {
            $columnas['banner_activo'] = $banner['activo'] ? 1 : 0;
        }
        foreach (['imagen' => 120, 'tipo' => 40, 'titulo' => 160, 'texto' => 600, 'alt' => 200] as $clave => $largo) {
            if (array_key_exists($clave, $banner)) {
                $columnas['banner_' . $clave] = mb_substr(trim((string) $banner[$clave]), 0, $largo);
            }
        }
        if ($columnas !== []) {
            self::escribir($eventoId, $columnas, $usuarioId);
        }
    }

    /** Vuelve al formulario de fábrica. El banner no se toca. */
    public static function restablecer(int $eventoId, ?int $usuarioId): void
    {
        self::escribir($eventoId, ['campos' => null, 'listas' => null], $usuarioId);
    }

    private static function escribir(int $eventoId, array $columnas, ?int $usuarioId): void
    {
        $columnas += ['actualizado_en' => date('Y-m-d H:i:s'), 'actualizado_por' => $usuarioId];
        $nombres = array_keys($columnas);
        Bd::ejecutar(
            'INSERT INTO {evento_formulario} (evento_id, ' . implode(', ', $nombres) . ')
                  VALUES (?' . str_repeat(', ?', count($nombres)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                static fn(string $c): string => "$c = VALUES($c)", $nombres
            )),
            array_merge([$eventoId], array_values($columnas))
        );
        self::olvidar();
    }

    /* =====================================================================
       Lo que llega de la pantalla de configuración
       ===================================================================== */

    /**
     * Convierte lo que escribe el administrador en listas guardables.
     *
     * Devuelve [listas, errores]. Un error en una lista no impide guardar las
     * demás: esa se queda como estaba y se dice por qué.
     *
     * @param array<string, mixed> $entrada lo enviado, por lista
     * @param array<string, mixed> $antes   las listas como estaban
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function leerListas(array $entrada, array $antes): array
    {
        $listas = [];
        $errores = [];
        foreach (self::LISTAS as $clave => $definicion) {
            $dato = $entrada[$clave] ?? null;
            try {
                $listas[$clave] = match ($definicion['clase']) {
                    'codigos'    => self::leerCodigos($clave, is_array($dato) ? $dato : [], (array) ($antes[$clave] ?? [])),
                    'texto'      => self::leerTexto((string) (is_string($dato) ? $dato : ''), (int) $definicion['largo']),
                    'perfiles'   => self::leerPerfiles(is_array($dato) ? $dato : []),
                    'territorio' => self::leerTerritorio((string) (is_string($dato) ? $dato : '')),
                    'minutos'    => self::leerMinutos((string) (is_string($dato) ? $dato : '')),
                };
            } catch (\DomainException $e) {
                $errores[$clave] = $e->getMessage();
                $listas[$clave] = $antes[$clave] ?? self::listaPorDefecto($clave);
            }
        }
        return [$listas, $errores];
    }

    /**
     * Las opciones de una lista de códigos.
     *
     * Llegan como filas: las que ya existían con su valor fijo, su etiqueta y
     * si están activas; y las nuevas, con una etiqueta y, para los documentos,
     * su sigla. Un valor de fábrica no se puede quitar, solo desactivar: hay
     * registros que lo tienen guardado.
     */
    private static function leerCodigos(string $lista, array $filas, array $antes): array
    {
        $largo = (int) self::LISTAS[$lista]['largo'];
        $deFabrica = array_column(self::listaPorDefecto($lista), 'valor');
        $salida = [];
        $vistos = [];

        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $etiqueta = trim(preg_replace('/\s+/u', ' ', (string) ($fila['etiqueta'] ?? '')) ?? '');
            $nueva = !array_key_exists('valor', $fila);
            $valor = $nueva ? trim((string) ($fila['codigo'] ?? '')) : (string) $fila['valor'];

            if ($nueva) {
                if ($etiqueta === '') {
                    continue;   // la fila vacía de «agregar»
                }
                if ($lista === 'tipo_documento') {
                    $valor = mb_strtoupper($valor);
                    if (!preg_match('/^[A-Z0-9]{2,8}$/', $valor)) {
                        throw new \DomainException('La sigla de «' . $etiqueta . '» debe tener entre 2 y 8 letras o números, sin espacios: PPT, PEP, RC…');
                    }
                } else {
                    // Lo que se guarda es la etiqueta misma: así se lee igual en
                    // la ficha y en el archivo exportado.
                    $valor = mb_substr($etiqueta, 0, $largo);
                }
            } elseif (!in_array($valor, $deFabrica, true)
                && !in_array($valor, array_column($antes, 'valor'), true)) {
                continue;   // un valor «existente» que nunca existió: manipulado
            }

            if (isset($vistos[$valor])) {
                if ($nueva) {
                    throw new \DomainException('«' . $etiqueta . '» ya está en la lista.');
                }
                continue;
            }
            if ($etiqueta === '') {
                $etiqueta = $valor !== '' ? $valor : 'Prefiero no responder';
            }
            $vistos[$valor] = true;
            $salida[] = [
                'valor'    => $valor,
                'etiqueta' => mb_substr($etiqueta, 0, 120),
                // La opción vacía —«prefiero no responder»— no se apaga nunca:
                // es la que deja no contestar un dato sensible.
                'activo'   => $valor === '' || !empty($fila['activo']),
            ];
        }

        // Ninguna de las de fábrica puede desaparecer: las que no vinieron se
        // conservan apagadas.
        foreach (self::listaPorDefecto($lista) as $opcion) {
            if (!isset($vistos[$opcion['valor']])) {
                $salida[] = ['valor' => $opcion['valor'], 'etiqueta' => $opcion['etiqueta'], 'activo' => $opcion['valor'] === ''];
            }
        }

        $activas = array_filter($salida, static fn(array $o): bool => $o['activo'] && $o['valor'] !== '');
        if ($activas === []) {
            throw new \DomainException('Deja al menos una opción activa en «' . self::LISTAS[$lista]['etiqueta'] . '».');
        }
        return $salida;
    }

    /** Una opción por línea. */
    private static function leerTexto(string $texto, int $largo): array
    {
        $salida = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linea) {
            $linea = trim(preg_replace('/\s+/u', ' ', $linea) ?? '');
            if ($linea === '') {
                continue;
            }
            if (mb_strlen($linea) > $largo) {
                throw new \DomainException('«' . mb_substr($linea, 0, 40) . '…» es demasiado larga: el máximo es ' . $largo . ' caracteres.');
            }
            if (!in_array($linea, $salida, true)) {
                $salida[] = $linea;
            }
        }
        if ($salida === []) {
            throw new \DomainException('La lista no puede quedar vacía.');
        }
        return $salida;
    }

    /** @return array<string, bool> */
    private static function leerPerfiles(array $marcados): array
    {
        $salida = [];
        foreach (Persona::ROLES_PUBLICOS as $rol) {
            // «Participante» siempre: es el perfil de quien no elige ninguno.
            $salida[$rol] = $rol === 'participante' || in_array($rol, $marcados, true);
        }
        return $salida;
    }

    /**
     * Departamentos y municipios, en un solo texto:
     *
     *     Nariño
     *     - Pasto
     *     - Ipiales
     *     Putumayo
     *     - Mocoa
     *
     * Una línea sin guion es un departamento; con guion, un municipio del
     * departamento de arriba. Es fácil de pegar desde una hoja de cálculo.
     *
     * @return array<string, array<int, string>>
     */
    private static function leerTerritorio(string $texto): array
    {
        $salida = [];
        $actual = null;
        foreach (preg_split('/\R/u', $texto) ?: [] as $numero => $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            if (preg_match('/^[-–•*]\s*(.+)$/u', $linea, $m)) {
                $municipio = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
                if ($actual === null) {
                    throw new \DomainException('El municipio «' . $municipio . '» (línea ' . ($numero + 1) . ') no tiene un departamento arriba.');
                }
                if (mb_strlen($municipio) > 80) {
                    throw new \DomainException('«' . mb_substr($municipio, 0, 40) . '…» es demasiado largo.');
                }
                if (!in_array($municipio, $salida[$actual], true)) {
                    $salida[$actual][] = $municipio;
                }
                continue;
            }
            $actual = trim(preg_replace('/\s+/u', ' ', rtrim($linea, ':')) ?? '');
            if (mb_strlen($actual) > 80) {
                throw new \DomainException('«' . mb_substr($actual, 0, 40) . '…» es demasiado largo.');
            }
            $salida[$actual] ??= [];
        }

        $salida = array_filter($salida, static fn(array $m): bool => $m !== []);
        if ($salida === []) {
            throw new \DomainException('Escribe al menos un departamento con un municipio: «Nariño» en una línea y «- Pasto» en la siguiente.');
        }
        return $salida;
    }

    /** @return array<int, int> */
    private static function leerMinutos(string $texto): array
    {
        $salida = [];
        foreach (preg_split('/[\s,;]+/', $texto) ?: [] as $trozo) {
            if ($trozo === '') {
                continue;
            }
            if (!ctype_digit($trozo) || (int) $trozo < 5 || (int) $trozo > 480) {
                throw new \DomainException('Las duraciones son minutos entre 5 y 480, separados por comas: «20, 40, 60».');
            }
            $salida[] = (int) $trozo;
        }
        $salida = array_values(array_unique($salida));
        sort($salida);
        if ($salida === []) {
            throw new \DomainException('Deja al menos una duración.');
        }
        return $salida;
    }

    /* =====================================================================
       Valores de fábrica
       ===================================================================== */

    /** @return array<string, string> el estado de fábrica de cada campo */
    private static function camposPorDefecto(): array
    {
        return array_map(static fn(array $definicion): string => $definicion['defecto'], self::CAMPOS);
    }

    /** Lo que la plataforma ofrecía antes de que existiera esta configuración. */
    public static function listaPorDefecto(string $clave): array
    {
        $codigos = static function (array $mapa): array {
            $salida = [];
            foreach ($mapa as $valor => $etiqueta) {
                $salida[] = ['valor' => (string) $valor, 'etiqueta' => (string) $etiqueta, 'activo' => true];
            }
            return $salida;
        };

        return match ($clave) {
            'tipo_documento' => $codigos(Datos::TIPOS_DOCUMENTO),
            'genero'         => $codigos(Datos::GENEROS),
            'etnia'          => $codigos(Datos::ETNIAS),
            'discapacidad'   => $codigos(Datos::DISCAPACIDADES),
            'perfil'         => array_fill_keys(Persona::ROLES_PUBLICOS, true),
            'rango_edad'     => Datos::RANGOS_EDAD,
            'ubicacion'      => array_map(
                static fn(array $m): array => array_values(array_unique($m)),
                Datos::MUNICIPIOS
            ),
            'categoria'      => Datos::CATEGORIAS,
            'duracion'       => [20, 40, 60],
            default          => [],
        };
    }

    /**
     * ¿Tiene una lista guardada la forma que le corresponde?
     *
     * Lo guardado se revisa al leer, no solo al escribir: una fila editada a
     * mano en la base no puede tumbar el registro público.
     */
    private static function listaValida(string $clave, mixed $valor): ?array
    {
        if (!is_array($valor) || $valor === []) {
            return null;
        }
        switch (self::LISTAS[$clave]['clase'] ?? '') {
            case 'codigos':
                // Las mismas reglas que al guardar: sin una opción que elegir, el
                // campo quedaría inservible y, si es obligatorio, nadie podría
                // registrarse.
                $activas = 0;
                foreach ($valor as $o) {
                    if (!is_array($o) || !isset($o['valor'], $o['etiqueta'])
                        || !is_scalar($o['valor']) || !is_scalar($o['etiqueta'])) {
                        return null;
                    }
                    if (!empty($o['activo']) && (string) $o['valor'] !== '') {
                        $activas++;
                    }
                }
                return $activas > 0 ? array_values($valor) : null;
            case 'perfiles':
                $salida = [];
                foreach (Persona::ROLES_PUBLICOS as $rol) {
                    $salida[$rol] = $rol === 'participante' || !empty($valor[$rol]);
                }
                return $salida;
            case 'territorio':
                foreach ($valor as $departamento => $municipios) {
                    if (!is_string($departamento) || !is_array($municipios) || $municipios === []) {
                        return null;
                    }
                    foreach ($municipios as $municipio) {
                        if (!is_string($municipio) || $municipio === '') {
                            return null;
                        }
                    }
                }
                return $valor;
            case 'minutos':
                $salida = array_values(array_filter(array_map('intval', $valor), static fn(int $m): bool => $m >= 5 && $m <= 480));
                return $salida ?: null;
            default:
                foreach ($valor as $opcion) {
                    if (!is_scalar($opcion) || (string) $opcion === '') {
                        return null;
                    }
                }
                return array_values(array_map('strval', $valor));
        }
    }

    /** El territorio en el formato del editor, para precargarlo. */
    public static function territorioComoTexto(array $territorio): string
    {
        $lineas = [];
        foreach ($territorio as $departamento => $municipios) {
            $lineas[] = (string) $departamento;
            foreach ((array) $municipios as $m) {
                $lineas[] = '- ' . $m;
            }
        }
        return implode("\n", $lineas);
    }
}
