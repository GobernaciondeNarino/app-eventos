<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\CertificadoPdf;
use App\Nucleo\Url;

/**
 * Los certificados del evento (3.21).
 *
 * Tienen derecho a uno:
 *
 *   · quien registró su ingreso en al menos una jornada: certificado de
 *     asistencia. Si estuvo en todas —y el evento tiene más de una—, el suyo es
 *     de asistencia completa: lleva un sello dorado, dice que asistió a todas
 *     las jornadas y suma las horas de todas. Ese es el valor agregado de
 *     quedarse los dos días;
 *   · quien expuso: un certificado de participación como conferencista por
 *     cada propuesta aprobada, con el título de su exposición.
 *
 * Lo que dice un certificado se calcula al descargarlo, con los datos de ese
 * momento: si el equipo le agrega a alguien un ingreso que no quedó registrado,
 * el certificado ya sale con él. Lo único que se guarda es la emisión —quién,
 * de qué, cuándo— y el código impreso, con el que cualquiera comprueba en
 * /v/<código> que el certificado es auténtico y sigue vigente.
 *
 * Nada se entrega mientras el administrador no los abra en Configuración →
 * Certificados.
 */
final class Certificado
{
    public const ESTADOS = [
        'cerrado' => 'Cerrados',
        'abierto' => 'Disponibles',
    ];

    public const TIPOS = [
        'asistencia' => 'Certificado de asistencia',
        'expositor'  => 'Certificado de participación como conferencista',
    ];

    /** Quién certifica, mientras el administrador no escriba otra cosa. */
    public const ENTIDAD_DE_FABRICA = 'La Gobernación de Nariño, a través de la Secretaría TIC, Innovación y Gobierno Abierto,';

    public const COLOR_DE_FABRICA = '#102951';

    /** Sin 0/O ni 1/I/L: el código se teclea, y así no hay letras que se confundan. */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LARGO_CODIGO = 12;

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                           'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const CUANTAS = [2 => 'las dos', 3 => 'las tres', 4 => 'las cuatro', 5 => 'las cinco',
                             6 => 'las seis', 7 => 'las siete', 8 => 'las ocho', 9 => 'las nueve', 10 => 'las diez'];

    /** @var array<int, array<string, mixed>> la configuración de cada evento, por petición */
    private static array $configuraciones = [];
    private static ?bool $enBase = null;

    /* =====================================================================
       Configuración
       ===================================================================== */

    /** ¿Ya están las tablas? Antes de actualizar la base no hay certificados. */
    public static function hayTablas(): bool
    {
        return self::$enBase ??= Bd::existeTabla('certificado') && Bd::existeTabla('evento_certificado');
    }

    /** Para las pruebas y para después de actualizar la base en la misma petición. */
    public static function olvidar(): void
    {
        self::$enBase = null;
        self::$configuraciones = [];
    }

    /**
     * La configuración de los certificados de un evento, con lo de fábrica en
     * lo que no se haya guardado.
     *
     * @return array<string, mixed>
     */
    public static function configuracion(int $eventoId): array
    {
        if (isset(self::$configuraciones[$eventoId])) {
            return self::$configuraciones[$eventoId];
        }
        // Se pregunta directo, sin information_schema: esto lo lee el menú de
        // cada persona en cada página. Si la tabla todavía no existe —la base
        // sin actualizar—, es lo mismo que no haber guardado nada.
        $fila = null;
        if (self::$enBase !== false) {
            try {
                $fila = Bd::fila('SELECT * FROM {evento_certificado} WHERE evento_id = ?', [$eventoId]);
            } catch (\PDOException) {
                self::$enBase = false;
            }
        }
        $horas = [];
        foreach ((array) json_decode((string) ($fila['horas_json'] ?? ''), true) as $dia => $valor) {
            if (is_numeric($valor) && (float) $valor > 0) {
                $horas[(int) $dia] = (float) $valor;
            }
        }
        $color = (string) ($fila['color'] ?? '');
        $entidad = trim((string) ($fila['entidad'] ?? ''));
        return self::$configuraciones[$eventoId] = [
            'guardada'      => $fila !== null,
            'estado'        => isset(self::ESTADOS[(string) ($fila['estado'] ?? '')]) ? (string) $fila['estado'] : 'cerrado',
            'nombre_evento' => trim((string) ($fila['nombre_evento'] ?? '')),
            'entidad'       => $entidad !== '' ? $entidad : self::ENTIDAD_DE_FABRICA,
            'ciudad'        => trim((string) ($fila['ciudad'] ?? '')),
            'horas'         => $horas,
            'expositores'   => (bool) ($fila['expositores'] ?? true),
            'color'         => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : self::COLOR_DE_FABRICA,
            'firma_nombre'  => trim((string) ($fila['firma_nombre'] ?? '')),
            'firma_cargo'   => trim((string) ($fila['firma_cargo'] ?? '')),
            'firma_imagen'  => (string) ($fila['firma_imagen'] ?? ''),
            'fondo_imagen'  => (string) ($fila['fondo_imagen'] ?? ''),
            'texto_imagen'  => (string) ($fila['texto_imagen'] ?? ''),
            'actualizado_en' => $fila['actualizado_en'] ?? null,
        ];
    }

    /**
     * Guarda lo que llegue en $columnas —las de evento_certificado— y deja
     * lo demás como estaba. Crea la fila la primera vez.
     *
     * @param array<string, mixed> $columnas
     */
    public static function guardarConfiguracion(int $eventoId, array $columnas, ?int $usuarioId): void
    {
        $columnas += ['actualizado_en' => date('Y-m-d H:i:s'), 'actualizado_por' => $usuarioId];
        $nombres = array_keys($columnas);
        Bd::ejecutar(
            'INSERT INTO {evento_certificado} (evento_id, ' . implode(', ', $nombres) . ')
                  VALUES (?' . str_repeat(', ?', count($nombres)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => "$c = VALUES($c)", $nombres)),
            array_merge([$eventoId], array_values($columnas))
        );
        unset(self::$configuraciones[$eventoId]);
    }

    /** ¿Se pueden descargar los de este evento? Lo preguntan el menú y las pantallas. */
    public static function abiertos(?array $evento): bool
    {
        if ($evento === null) {
            return false;
        }
        try {
            return self::configuracion((int) $evento['id'])['estado'] === 'abierto';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Dónde guarda la plataforma las imágenes del certificado. */
    public static function rutaImagen(string $archivo): string
    {
        return RAIZ . '/almacen/logos/' . basename($archivo);
    }

    /* =====================================================================
       Quién tiene derecho
       ===================================================================== */

    /**
     * Lo único que hace falta para emitirle un certificado a alguien: su
     * nombre. La identificación sale si la dio; si el evento la dejó opcional
     * y no la dio, el certificado va sin esa línea.
     */
    public static function tieneNombre(array $persona): bool
    {
        return trim((string) ($persona['nombre'] ?? '')) !== '';
    }

    /**
     * La asistencia de una persona: cada jornada del evento, si estuvo y sus
     * horas, y el total.
     *
     * @return array{jornadas: array<int, array{id: int, numero: int, fecha: string, asistio: bool, horas: float}>,
     *               asistidas: int, total: int, completa: bool, horas: float}
     */
    public static function asistencia(int $personaId, int $eventoId, array $config): array
    {
        $jornadas = [];
        foreach (Bd::filas(
            'SELECT d.id, d.numero, d.fecha, a.id AS ingreso
               FROM {evento_dia} d
          LEFT JOIN {asistencia} a ON a.evento_dia_id = d.id AND a.persona_id = ?
              WHERE d.evento_id = ?
           ORDER BY d.fecha, d.numero',
            [$personaId, $eventoId]
        ) as $fila) {
            $jornadas[] = [
                'id'      => (int) $fila['id'],
                'numero'  => (int) $fila['numero'],
                'fecha'   => (string) $fila['fecha'],
                'asistio' => $fila['ingreso'] !== null,
                'horas'   => (float) ($config['horas'][(int) $fila['id']] ?? 0),
            ];
        }
        $asistidas = array_values(array_filter($jornadas, static fn(array $j): bool => $j['asistio']));
        return [
            'jornadas'  => $jornadas,
            'asistidas' => count($asistidas),
            'total'     => count($jornadas),
            'completa'  => count($jornadas) > 1 && count($asistidas) === count($jornadas),
            'horas'     => array_sum(array_column($asistidas, 'horas')),
        ];
    }

    /**
     * Las exposiciones aprobadas de una persona, con el día que les asignó el
     * comité.
     *
     * @return array<int, array{id: int, titulo: string, fecha: ?string}>
     */
    public static function exposiciones(int $personaId): array
    {
        return array_map(static fn(array $f): array => [
            'id'     => (int) $f['id'],
            'titulo' => (string) $f['titulo'],
            'fecha'  => $f['fecha'] !== null ? (string) $f['fecha'] : null,
        ], Bd::filas(
            "SELECT pr.id, pr.titulo, d.fecha
               FROM {propuesta} pr
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id
          LEFT JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE pr.persona_id = ? AND pr.estado = 'aprobada'
           ORDER BY pr.id",
            [$personaId]
        ));
    }

    /**
     * Los certificados a los que tiene derecho una persona, cada uno con su
     * emisión. Con $emitir, los que todavía no tienen código lo reciben ahora:
     * es lo que pasa la primera vez que la persona abre «Mi certificado».
     *
     * @return array<int, array<string, mixed>>
     */
    public static function dePersona(array $persona, bool $emitir = true, ?array $config = null): array
    {
        $id = (int) $persona['id'];
        $eventoId = (int) $persona['evento_id'];
        $config ??= self::configuracion($eventoId);

        $derechos = [];
        $asistencia = self::asistencia($id, $eventoId, $config);
        if ($asistencia['asistidas'] > 0) {
            $derechos[] = ['tipo' => 'asistencia', 'referencia' => 0, 'asistencia' => $asistencia];
        }
        if ($config['expositores']) {
            foreach (self::exposiciones($id) as $exposicion) {
                $derechos[] = ['tipo' => 'expositor', 'referencia' => $exposicion['id'],
                               'exposicion' => $exposicion, 'asistencia' => $asistencia];
            }
        }

        $emitidos = [];
        foreach (Bd::filas('SELECT * FROM {certificado} WHERE persona_id = ?', [$id]) as $fila) {
            $emitidos[$fila['tipo'] . '|' . $fila['referencia']] = $fila;
        }
        foreach ($derechos as $i => $derecho) {
            $fila = $emitidos[$derecho['tipo'] . '|' . $derecho['referencia']] ?? null;
            if ($fila === null && $emitir) {
                $fila = self::emitir($id, $derecho['tipo'], (int) $derecho['referencia']);
            }
            $derechos[$i]['emision'] = $fila;
        }
        return $derechos;
    }

    /** El certificado de una persona que tiene ese código, si todavía tiene derecho a él. */
    public static function derechoPorCodigo(array $persona, string $codigo): ?array
    {
        foreach (self::dePersona($persona, false) as $derecho) {
            if (($derecho['emision']['codigo'] ?? '') === $codigo) {
                return $derecho;
            }
        }
        return null;
    }

    /* =====================================================================
       Emisión, código y verificación
       ===================================================================== */

    /** «k7m2 9qxr-ht4p» → «K7M29QXRHT4P». Vacío si no puede ser un código. */
    public static function normalizarCodigo(string $texto): string
    {
        $limpio = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $texto));
        return strlen($limpio) === self::LARGO_CODIGO && strspn($limpio, self::ALFABETO) === self::LARGO_CODIGO ? $limpio : '';
    }

    /** «K7M29QXRHT4P» → «K7M2-9QXR-HT4P», que es como va impreso. */
    public static function codigoLegible(string $codigo): string
    {
        return implode('-', str_split($codigo, 4));
    }

    /**
     * La emisión con ese código, con la persona y el evento. Para la página de
     * verificación, que es pública: no se le pide a nadie que inicie sesión
     * para comprobar un certificado que le presentaron.
     */
    public static function porCodigo(string $codigo): ?array
    {
        if (!self::hayTablas() || $codigo === '') {
            return null;
        }
        return Bd::fila(
            'SELECT c.*, p.nombre, p.evento_id, p.tipo_documento, p.documento_cifrado, p.id AS persona_id
               FROM {certificado} c
               JOIN {persona} p ON p.id = c.persona_id
              WHERE c.codigo = ?',
            [$codigo]
        );
    }

    public static function anotarDescarga(int $id): void
    {
        Bd::ejecutar('UPDATE {certificado} SET descargas = descargas + 1, descargado_en = NOW() WHERE id = ?', [$id]);
    }

    /**
     * El código se guarda con su emisión. Una llave única por persona, tipo y
     * referencia hace que dos pestañas abiertas a la vez no emitan dos: la
     * segunda choca y se queda con el de la primera.
     */
    private static function emitir(int $personaId, string $tipo, int $referencia): array
    {
        for ($intento = 0; $intento < 6; $intento++) {
            $codigo = '';
            for ($i = 0; $i < self::LARGO_CODIGO; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
            try {
                Bd::insertar('certificado', [
                    'persona_id' => $personaId,
                    'tipo'       => $tipo,
                    'referencia' => $referencia,
                    'codigo'     => $codigo,
                    'emitido_en' => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Si chocó la emisión, ya la hizo otra petición; si chocó el
                // código —uno entre 10^17—, se prueba con otro.
            }
            $fila = Bd::fila('SELECT * FROM {certificado} WHERE persona_id = ? AND tipo = ? AND referencia = ?',
                [$personaId, $tipo, $referencia]);
            if ($fila !== null) {
                return $fila;
            }
        }
        throw new \RuntimeException('No se pudo emitir el certificado.');
    }

    /* =====================================================================
       Lo que dice
       ===================================================================== */

    /**
     * Los textos de un certificado, para el PDF y para la pantalla.
     *
     * @return array{titulo: string, entidad: string, nombre: string, documento: string,
     *               cuerpo: array<int, array{texto: string, negrita?: bool}>, expedicion: string,
     *               sello: ?array, resumen: string, completa: bool}
     */
    public static function redactar(array $derecho, array $persona, array $evento, array $config, string $documento = ''): array
    {
        [$articulo, $propio] = self::nombreDelEvento($config, $evento);
        $asistencia = $derecho['asistencia'];
        $fechasEvento = array_column($asistencia['jornadas'], 'fecha');
        $realizado = 'que se realizó' . ($config['ciudad'] !== '' ? ' en ' . $config['ciudad'] : '')
            . ($fechasEvento !== [] ? ' ' . self::dias($fechasEvento) : '');
        $delEvento = [['texto' => $articulo], ['texto' => $propio, 'negrita' => true]];

        if ($derecho['tipo'] === 'expositor') {
            $exposicion = $derecho['exposicion'];
            $cuerpo = array_merge([['texto' => 'participó como conferencista ']], self::contraer('en', $delEvento), [[
                'texto' => ', ' . $realizado . ', con la exposición «' . trim($exposicion['titulo']) . '»'
                    . ($exposicion['fecha'] !== null && count($fechasEvento) > 1
                        ? ', presentada el ' . self::fechaLarga($exposicion['fecha'], false) : '')
                    . '.',
            ]]);
            $titulo = 'Certificado de participación';
            $resumen = 'Conferencista con «' . trim($exposicion['titulo']) . '»';
            $completa = false;
        } else {
            $horas = $asistencia['horas'] > 0 ? ', con una intensidad de ' . self::horas($asistencia['horas']) : '';
            $asistidas = array_values(array_filter($asistencia['jornadas'], static fn(array $j): bool => $j['asistio']));
            $completa = $asistencia['completa'];
            if ($asistencia['total'] <= 1) {
                $cuerpo = array_merge([['texto' => 'asistió ']], self::contraer('a', $delEvento),
                    [['texto' => ', ' . $realizado . $horas . '.']]);
                $resumen = 'Asistencia';
            } elseif ($completa) {
                $cuantas = self::CUANTAS[$asistencia['total']] ?? 'las ' . $asistencia['total'];
                $cuerpo = array_merge([['texto' => 'asistió a ' . $cuantas . ' jornadas ']], self::contraer('de', $delEvento),
                    [['texto' => ', ' . $realizado . $horas . '.']]);
                $resumen = 'Asistencia completa: ' . $asistencia['total'] . ' de ' . $asistencia['total'] . ' jornadas';
            } else {
                $fechas = array_column($asistidas, 'fecha');
                $cuerpo = array_merge(
                    [['texto' => 'asistió a ' . (count($fechas) === 1 ? 'la jornada del ' : 'las jornadas del ')
                        . self::listaDeFechas($fechas) . ' ']],
                    self::contraer('de', $delEvento),
                    [['texto' => ', que se realizó' . ($config['ciudad'] !== '' ? ' en ' . $config['ciudad'] : '') . $horas . '.']]
                );
                $resumen = 'Asistencia: ' . count($asistidas) . ' de ' . $asistencia['total'] . ' jornadas';
            }
            $titulo = 'Certificado de asistencia';
        }

        $entidad = rtrim($config['entidad']);
        if (!preg_match('/certifica(\s+que)?\s*:?$/iu', $entidad)) {
            $entidad .= ' certifica que';
        }
        $emitido = (string) ($derecho['emision']['emitido_en'] ?? date('Y-m-d'));

        return [
            'titulo'     => mb_strtoupper($titulo),
            'entidad'    => $entidad,
            'nombre'     => mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $persona['nombre']))),
            'documento'  => trim($documento) !== '' ? identificacion((string) ($persona['tipo_documento'] ?? ''), $documento) : '',
            'cuerpo'     => $cuerpo,
            'expedicion' => 'Se expide' . ($config['ciudad'] !== '' ? ' en ' . $config['ciudad'] : '')
                . ' el ' . self::fechaLarga($emitido) . '.',
            'sello'      => $completa
                ? ['arriba' => 'Asistencia completa', 'centro' => $asistencia['total'] . '/' . $asistencia['total'], 'abajo' => 'Jornadas']
                : null,
            'resumen'    => $resumen,
            'completa'   => $completa,
        ];
    }

    /**
     * El PDF de un certificado.
     *
     * @param array<string, mixed> $derecho uno de dePersona(), con su emisión
     */
    public static function pdf(array $derecho, array $persona, array $evento, ?array $config = null, bool $muestra = false): string
    {
        $config ??= self::configuracion((int) $evento['id']);
        $texto = self::redactar($derecho, $persona, $evento, $config,
            $muestra ? (string) ($persona['documento'] ?? '') : Persona::documento($persona));
        $codigo = (string) ($derecho['emision']['codigo'] ?? '');
        $verificar = Url::absoluta('/v');
        $imagen = static fn(string $archivo): string => $archivo !== '' ? self::rutaImagen($archivo) : '';

        return CertificadoPdf::generar([
            'titulo'     => $texto['titulo'],
            'entidad'    => $texto['entidad'],
            'nombre'     => $texto['nombre'],
            'documento'  => $texto['documento'],
            'cuerpo'     => $texto['cuerpo'],
            'expedicion' => $texto['expedicion'],
            'sello'      => $texto['sello'],
            'encabezado' => (string) $evento['nombre'],
            'firma'      => $config['firma_nombre'] !== '' ? [
                'nombre' => $config['firma_nombre'],
                'cargo'  => $config['firma_cargo'],
                'imagen' => $imagen($config['firma_imagen']),
                'cache'  => $config['firma_imagen'] !== '' ? $imagen($config['firma_imagen']) . '.cache' : null,
            ] : null,
            'qr'         => $muestra ? $verificar : Url::absoluta('/v/' . $codigo),
            'codigo'     => self::codigoLegible($codigo),
            'verificar'  => preg_replace('#^https?://#', '', $verificar),
            'fondo'      => $imagen($config['fondo_imagen']),
            'fondoTexto' => $imagen($config['texto_imagen']),
            'cacheTexto' => $config['texto_imagen'] !== '' ? $imagen($config['texto_imagen']) . '.cache' : null,
            'color'      => $config['color'],
            'muestra'    => $muestra,
            'metadatos'  => [
                'Title'    => ($derecho['tipo'] === 'expositor' ? 'Certificado de participación' : 'Certificado de asistencia')
                    . ' — ' . trim((string) $persona['nombre']),
                'Author'   => trim((string) ($evento['dependencia'] ?? '')) !== '' ? (string) $evento['dependencia'] : 'Gobernación de Nariño',
                'Subject'  => (string) $evento['nombre'] . ($codigo !== '' ? ' · código ' . self::codigoLegible($codigo) : ''),
                'Keywords' => 'certificado, ' . (string) $evento['nombre'],
                'Creator'  => 'Plataforma de Eventos TIC',
            ],
        ]);
    }

    /**
     * Un certificado de ejemplo, con datos inventados y la marca de agua, para
     * que el administrador vea el diseño antes de abrirlos.
     */
    public static function ejemplo(array $evento, string $caso): string
    {
        $config = self::configuracion((int) $evento['id']);
        $jornadas = Bd::filas('SELECT id, numero, fecha FROM {evento_dia} WHERE evento_id = ? ORDER BY fecha, numero',
            [(int) $evento['id']]) ?: [['id' => 0, 'numero' => 1, 'fecha' => date('Y-m-d')]];
        $todas = $caso !== 'parcial';
        $lista = [];
        foreach ($jornadas as $i => $j) {
            $lista[] = ['id' => (int) $j['id'], 'numero' => (int) $j['numero'], 'fecha' => (string) $j['fecha'],
                        'asistio' => $todas || $i === 0, 'horas' => (float) ($config['horas'][(int) $j['id']] ?? 0)];
        }
        $asistidas = array_filter($lista, static fn(array $j): bool => $j['asistio']);
        $derecho = [
            'tipo'       => $caso === 'expositor' ? 'expositor' : 'asistencia',
            'referencia' => 0,
            'asistencia' => [
                'jornadas'  => $lista,
                'asistidas' => count($asistidas),
                'total'     => count($lista),
                'completa'  => count($lista) > 1 && count($asistidas) === count($lista),
                'horas'     => array_sum(array_column($asistidas, 'horas')),
            ],
            'exposicion' => ['id' => 0, 'titulo' => 'Inteligencia artificial al servicio de los municipios de Nariño',
                             'fecha' => (string) $jornadas[0]['fecha']],
            'emision'    => ['codigo' => 'EJEMPLOXXXXX', 'emitido_en' => date('Y-m-d H:i:s')],
        ];
        $persona = ['nombre' => 'María Fernanda Zambrano Ortiz', 'tipo_documento' => 'CC', 'documento' => '1085000000'];
        return self::pdf($derecho, $persona, $evento, $config, true);
    }

    /* =====================================================================
       Para el panel
       ===================================================================== */

    /**
     * Cuántos tienen derecho y cuántos ya lo descargaron.
     *
     * @return array{asistentes: int, completos: int, jornadas: int, expositores: int, emitidos: int, descargados: int}
     */
    public static function resumen(int $eventoId): array
    {
        $jornadas = (int) Bd::valor('SELECT COUNT(*) FROM {evento_dia} WHERE evento_id = ?', [$eventoId]);
        $porPersona = Bd::filas(
            'SELECT a.persona_id, COUNT(*) AS dias
               FROM {asistencia} a
               JOIN {evento_dia} d ON d.id = a.evento_dia_id
              WHERE d.evento_id = ?
           GROUP BY a.persona_id',
            [$eventoId]
        );
        $emitidos = self::hayTablas() ? Bd::fila(
            'SELECT COUNT(*) AS emitidos, SUM(c.descargas > 0) AS descargados
               FROM {certificado} c
               JOIN {persona} p ON p.id = c.persona_id
              WHERE p.evento_id = ?',
            [$eventoId]
        ) : null;
        return [
            'asistentes'  => count($porPersona),
            'completos'   => $jornadas > 1
                ? count(array_filter($porPersona, static fn(array $f): bool => (int) $f['dias'] >= $jornadas)) : 0,
            'jornadas'    => $jornadas,
            'expositores' => (int) Bd::valor(
                "SELECT COUNT(DISTINCT pr.persona_id)
                   FROM {propuesta} pr
                   JOIN {persona} p ON p.id = pr.persona_id
                  WHERE p.evento_id = ? AND pr.estado = 'aprobada'",
                [$eventoId]
            ),
            'emitidos'    => (int) ($emitidos['emitidos'] ?? 0),
            'descargados' => (int) ($emitidos['descargados'] ?? 0),
        ];
    }

    /* =====================================================================
       Palabras
       ===================================================================== */

    /** «2026-11-13» → «13 de noviembre de 2026» (o sin el año). */
    public static function fechaLarga(string $iso, bool $conAno = true): string
    {
        $marca = strtotime($iso);
        if ($marca === false) {
            return $iso;
        }
        return (int) date('j', $marca) . ' de ' . self::MESES[(int) date('n', $marca) - 1]
            . ($conAno ? ' de ' . date('Y', $marca) : '');
    }

    /**
     * Una lista de fechas en palabras, juntando lo que se repite:
     * «13 y 14 de noviembre de 2026», «30 de octubre y 2 de noviembre de 2026».
     *
     * @param array<int, string> $fechas
     */
    public static function listaDeFechas(array $fechas): string
    {
        $fechas = array_values(array_unique($fechas));
        sort($fechas);
        $porMes = [];
        foreach ($fechas as $fecha) {
            $marca = strtotime($fecha);
            if ($marca !== false) {
                $porMes[date('Y-m', $marca)][] = (int) date('j', $marca);
            }
        }
        $partes = [];
        $anos = array_unique(array_map(static fn(string $m): string => substr($m, 0, 4), array_keys($porMes)));
        foreach ($porMes as $mes => $dias) {
            $ultimoDelAno = $mes === max(array_filter(array_keys($porMes), static fn(string $m): bool => str_starts_with($m, substr($mes, 0, 4))));
            $partes[] = enumerar(array_map('strval', $dias)) . ' de ' . self::MESES[(int) substr($mes, 5, 2) - 1]
                . (count($anos) > 1 && $ultimoDelAno ? ' de ' . substr($mes, 0, 4) : '');
        }
        $texto = enumerar($partes);
        return count($anos) > 1 ? $texto : $texto . ' de ' . reset($anos);
    }

    /** «el 13 de noviembre de 2026» o «los días 13 y 14 de noviembre de 2026». */
    private static function dias(array $fechas): string
    {
        $fechas = array_values(array_unique($fechas));
        return count($fechas) === 1 ? 'el ' . self::fechaLarga($fechas[0]) : 'los días ' . self::listaDeFechas($fechas);
    }

    /** 16 → «16 horas»; 1 → «1 hora»; 7.5 → «7,5 horas». */
    public static function horas(float $horas): string
    {
        $texto = rtrim(rtrim(number_format($horas, 1, ',', '.'), '0'), ',');
        return $texto . ($horas == 1.0 ? ' hora' : ' horas');
    }

    /**
     * Cómo se nombra el evento en el texto: [artículo, nombre]. Lo que escribió
     * el administrador —«la Cumbre Tecnológica IA Nariño 2026»—, o, si no
     * escribió nada, «el evento Cumbre…», que vale para cualquier nombre.
     *
     * @return array{0: string, 1: string}
     */
    public static function nombreDelEvento(array $config, array $evento): array
    {
        $nombre = $config['nombre_evento'] !== '' ? $config['nombre_evento'] : 'el evento ' . trim((string) $evento['nombre']);
        if (preg_match('/^((?:el|la|los|las)(?:\s+evento)?\s+)(.+)$/iu', $nombre, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', $nombre];
    }

    /**
     * La preposición pegada al artículo: «de el evento» → «del evento», «a el
     * foro» → «al foro». Devuelve los tramos con la preposición delante.
     *
     * @param array<int, array{texto: string, negrita?: bool}> $tramos
     * @return array<int, array{texto: string, negrita?: bool}>
     */
    private static function contraer(string $preposicion, array $tramos): array
    {
        $primero = $preposicion . ' ' . $tramos[0]['texto'];
        $primero = (string) preg_replace(['/^de el\b/u', '/^a el\b/u'], ['del', 'al'], $primero);
        $tramos[0]['texto'] = $primero;
        return $tramos;
    }
}
