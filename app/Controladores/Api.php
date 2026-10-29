<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Evento;
use App\Modelos\Formulario;
use App\Modelos\Persona;
use App\Modelos\TokenApi;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Limite;
use App\Nucleo\Orden;
use App\Nucleo\Paginacion;
use App\Nucleo\Peticion;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Url;

/**
 * La API de consulta: quiénes se registraron al evento, en JSON.
 *
 * Solo lee. Se identifica con un token que se crea en Configuración → API,
 * uno por cada sistema que consulta, y que va en la dirección (?token=…) o en
 * la cabecera «Authorization: Bearer …». La cabecera es mejor cuando se puede:
 * la dirección queda escrita en los registros del servidor web.
 *
 * Lo que nunca sale por aquí: el número de identificación, la caracterización
 * —datos sensibles, Ley 1581 art. 5—, las observaciones del comité ni ningún
 * token de acceso. El correo y el teléfono, solo con un token creado con
 * alcance «contacto»; el enlace de la fotografía, solo con un token que tenga
 * ese permiso (ver foto()).
 *
 * Los filtros van en la dirección y son todos opcionales. Uno que no se
 * entiende —un nombre mal escrito, un valor que no existe— responde 400
 * diciendo qué se admite, en vez de ignorarse y devolver algo distinto de lo
 * que se pidió.
 */
final class Api
{
    private const POR_PAGINA = 50;
    private const TOPE_POR_PAGINA = 200;
    private const ESTADOS = ['pendiente', 'observada', 'aprobada', 'rechazada', 'sin-propuesta'];

    /**
     * Lo que se admite en la dirección de cualquier consulta, además de sus
     * filtros: el token, y el «_» que agregan algunas librerías para que el
     * navegador no use una copia guardada.
     */
    private const SIEMPRE = ['token', '_'];

    /** @var array<int, array> Los días de cada evento, que se piden más de una vez. */
    private array $jornadas = [];

    /* =====================================================================
       Consultas
       ===================================================================== */

    /** GET /api/v1 — qué se puede consultar y con qué filtros. */
    public function indice(Peticion $peticion): void
    {
        $token = $this->autenticar($peticion);
        $conContacto = $token['alcance'] === 'contacto';
        $this->admitir($peticion, $this->filtros('indice', $conContacto));
        $evento = $this->evento($peticion);

        $this->responder([
            'api'      => 'Plataforma de Eventos TIC · API de consulta',
            'version'  => 1,
            'alcance'  => $conContacto ? 'contacto' : 'basico',
            // Si cada persona trae el enlace de su fotografía.
            'fotografia' => TokenApi::daFoto($token),
            'evento'   => $this->datosEvento($evento),
            'eventos'  => array_map(static fn(array $e): array => [
                'id' => (int) $e['id'], 'nombre' => (string) $e['nombre'], 'fecha_inicio' => (string) $e['fecha_inicio'],
                'estado' => (string) $e['estado'], 'activo' => (int) $e['activo'] === 1,
            ], Bd::filas('SELECT id, nombre, fecha_inicio, estado, activo FROM {evento}
                           ORDER BY activo DESC, fecha_inicio DESC, id DESC')),
            'consultas' => [
                [
                    'url' => Url::absoluta('/api/v1/perfiles'),
                    'descripcion' => 'Los perfiles de asistencia del evento —participante, expositor, prensa…— y cuántas personas tiene cada uno.',
                    'filtros' => $this->filtros('perfiles', $conContacto),
                ],
                [
                    'url' => Url::absoluta('/api/v1/personas'),
                    'descripcion' => 'Quienes se registraron al evento, de todos los perfiles, con su propuesta si la enviaron.',
                    'filtros' => $this->filtros('personas', $conContacto),
                ],
                [
                    'url' => Url::absoluta('/api/v1/expositores'),
                    'descripcion' => 'Quienes exponen o se registraron para exponer, con su propuesta y, si ya está en la agenda, su día, hora y salón.',
                    'filtros' => $this->filtros('expositores', $conContacto),
                ],
            ],
        ]);
    }

    /** GET /api/v1/perfiles */
    public function perfiles(Peticion $peticion): void
    {
        $conContacto = $this->autenticar($peticion)['alcance'] === 'contacto';
        $this->admitir($peticion, $this->filtros('perfiles', $conContacto));
        $evento = $this->evento($peticion);

        $perfiles = $this->perfilesConTotal((int) $evento['id']);
        $datos = [];
        foreach ($perfiles as $clave => $perfil) {
            $datos[] = ['clave' => $clave, 'nombre' => $perfil['nombre'], 'total' => $perfil['total']];
        }

        $this->responder([
            'evento' => $this->datosEvento($evento),
            'total'  => array_sum(array_column($perfiles, 'total')),
            'datos'  => $datos,
        ]);
    }

    /** GET /api/v1/personas */
    public function personas(Peticion $peticion): void
    {
        $token = $this->autenticar($peticion);
        $conContacto = $token['alcance'] === 'contacto';
        $this->admitir($peticion, $this->filtros('personas', $conContacto));
        $evento = $this->evento($peticion);
        $eventoId = (int) $evento['id'];

        [$donde, $parametros, $aplicados] = $this->filtrosComunes($peticion, $eventoId, $conContacto);

        $perfiles = $this->lista($peticion->query('perfil'));
        if ($perfiles !== []) {
            $validos = array_map('strval', array_keys($this->perfilesConTotal($eventoId)));
            $desconocidos = array_diff($perfiles, $validos);
            if ($desconocidos !== []) {
                $this->error(400, 'Perfil desconocido', 'No existe el perfil «' . implode('», «', $desconocidos)
                    . '». Los de este evento son: ' . implode(', ', $validos) . '.');
            }
            $marcas = [];
            foreach (array_values($perfiles) as $i => $clave) {
                $marcas[] = ':perfil' . $i;
                $parametros['perfil' . $i] = $clave;
            }
            $donde[] = 'p.rol IN (' . implode(', ', $marcas) . ')';
            $aplicados['perfil'] = array_values($perfiles);
        }

        $dia = $this->dia($peticion, $evento);
        if ($dia !== null) {
            $donde[] = 'EXISTS (SELECT 1 FROM {asistencia} a
                                  JOIN {evento_dia} d ON d.id = a.evento_dia_id
                                 WHERE a.persona_id = p.id AND d.numero = :dia)';
            $parametros['dia'] = $dia;
            $aplicados['dia'] = $dia;
        }

        $completo = $peticion->query('completo');
        if ($completo !== '') {
            if (!in_array($completo, ['0', '1'], true)) {
                $this->error(400, 'Filtro no válido', 'completo admite 1 (registros completos) o 0 (a medias).');
            }
            $donde[] = ($completo === '1' ? 'NOT ' : '') . '(' . Persona::sqlIncompleto($eventoId) . ')';
            $aplicados['completo'] = $completo === '1';
        }

        $departamento = $peticion->query('departamento');
        if ($departamento !== '') {
            $donde[] = 'p.departamento LIKE :departamento';
            $parametros['departamento'] = $this->patron($departamento);
            $aplicados['departamento'] = $departamento;
        }

        foreach (['registrado_desde' => '>=', 'registrado_hasta' => '<'] as $clave => $operador) {
            $fecha = $peticion->query($clave);
            if ($fecha === '') {
                continue;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $this->error(400, 'Fecha no válida', $clave . ' va como AAAA-MM-DD, por ejemplo 2026-10-15.');
            }
            // «Hasta» incluye el día entero: se compara con el comienzo del siguiente.
            $parametros[$clave] = $clave === 'registrado_hasta'
                ? date('Y-m-d', strtotime($fecha . ' +1 day')) . ' 00:00:00'
                : $fecha . ' 00:00:00';
            $donde[] = 'p.creado_en ' . $operador . ' :' . $clave;
            $aplicados[$clave] = $fecha;
        }

        $orden = $this->orden($peticion, Persona::columnasDeOrden($eventoId) + ['registro' => ['p.creado_en']]);

        $this->listar($peticion, $evento, $donde, $parametros, $aplicados, $orden, '', $token);
    }

    /** GET /api/v1/expositores */
    public function expositores(Peticion $peticion): void
    {
        $token = $this->autenticar($peticion);
        $conContacto = $token['alcance'] === 'contacto';
        $this->admitir($peticion, $this->filtros('expositores', $conContacto));
        $evento = $this->evento($peticion);
        $eventoId = (int) $evento['id'];

        [$donde, $parametros, $aplicados] = $this->filtrosComunes($peticion, $eventoId, $conContacto, true);

        // Los mismos que muestra Expositores en el panel: el perfil Expositor,
        // quien envió una propuesta y quien llegó por el enlace privado.
        $porEnlace = array_map('intval', array_keys(Formulario::llegadosPorEnlace($eventoId)));
        $donde[] = "(p.rol = 'expositor' OR pr.id IS NOT NULL"
            . ($porEnlace ? ' OR p.id IN (' . implode(',', $porEnlace) . ')' : '') . ')';

        $estado = $peticion->query('estado');
        if ($estado !== '') {
            if (!in_array($estado, self::ESTADOS, true)) {
                $this->error(400, 'Estado desconocido', 'estado admite: ' . implode(', ', self::ESTADOS) . '.');
            }
            $donde[] = $estado === 'sin-propuesta' ? 'pr.id IS NULL' : 'pr.estado = :estado';
            if ($estado !== 'sin-propuesta') {
                $parametros['estado'] = $estado;
            }
            $aplicados['estado'] = $estado;
        }

        $categoria = $peticion->query('categoria');
        if ($categoria !== '') {
            $donde[] = 'pr.categoria = :categoria';
            $parametros['categoria'] = $categoria;
            $aplicados['categoria'] = $categoria;
        }

        $dia = $this->dia($peticion, $evento);
        if ($dia !== null) {
            $donde[] = 'dc.numero = :dia';
            $parametros['dia'] = $dia;
            $aplicados['dia'] = $dia;
        }

        $orden = $this->orden($peticion, [
            'nombre'   => ['p.nombre', "TRIM(p.nombre) = ''"],
            'entidad'  => ['p.entidad', "p.entidad = ''"],
            'registro' => ['p.creado_en'],
            'estado'   => ["FIELD(pr.estado, 'pendiente', 'observada', 'aprobada', 'rechazada')", 'pr.id IS NULL'],
            'jornada'  => [['dc.numero', 'c.hora_inicio'], 'dc.numero IS NULL'],
        ]);

        $this->listar($peticion, $evento, $donde, $parametros, $aplicados, $orden, self::UNION_PROPUESTA, $token);
    }

    /**
     * GET /api/v1/fotos/{persona}/{clave}/{firma} — la fotografía de una persona.
     *
     * Es el enlace que entrega la API en «foto». No lleva el token: la firma es
     * la llave. Así se puede poner tal cual en una página —un <img> del sitio
     * web— sin que el token quede a la vista de quien la visita.
     *
     * La firma sale del token —su número y la fecha de su permiso— y del
     * archivo de la foto (TokenApi::firmaFoto): el enlace deja de servir al
     * revocar el token, al quitarle el permiso de la fotografía —y no revive si
     * se le vuelve a dar— o cuando la persona cambia de foto.
     * Uno que no cuadra responde 404, igual que una persona sin foto: no se dice
     * por qué.
     *
     * No se corta por IP a quien pide enlaces que no sirven, como con los
     * tokens: una firma de 128 bits no se adivina, y quien pide enlaces viejos
     * suele ser el visitante de una página que todavía no se ha puesto al día.
     * Con tope, toda una oficina detrás de la misma IP se quedaría sin las
     * fotos que sí sirven.
     *
     * Ver una foto no cuenta como consulta del token: una página con cincuenta
     * expositores pediría cincuenta imágenes cada vez que alguien la abre.
     */
    public function foto(Peticion $peticion, array $parametros): void
    {
        $personaId = (int) $parametros['persona'];
        $tokenId = (int) $parametros['clave'];
        try {
            $token = TokenApi::vigenteConFoto($tokenId);
            $persona = $token !== null
                ? Bd::fila('SELECT id, foto, foto_tipo FROM {persona} WHERE id = ?', [$personaId])
                : null;
        } catch (\Throwable $e) {
            Registro::excepcion($e);
            $this->error(503, 'API no disponible', 'La base de datos de la plataforma todavía no se ha actualizado '
                . 'para la API. Quien la administra lo resuelve desde el panel.');
        }

        $archivo = (string) ($persona['foto'] ?? '');
        if ($token === null || $archivo === ''
            || !hash_equals(TokenApi::firmaFoto($token, $personaId, $archivo), (string) $parametros['firma'])) {
            $this->error(404, 'Sin fotografía', 'El enlace no existe o ya no sirve. Pide los datos otra vez a la API.');
        }

        // Se puede mostrar en otro sitio —para eso es—, pero los buscadores no
        // la guardan: la cara de quien asistió no tiene por qué salir en una
        // búsqueda de imágenes.
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('X-Robots-Tag: noindex, nofollow, noimageindex');

        // basename() aunque el nombre lo ponga el servidor, como en Medios::foto().
        // Quien guarde la imagen la recibe como foto-14.jpg, no con el nombre
        // interno del archivo.
        Respuesta::archivo(RAIZ . '/almacen/fotos/' . basename($archivo),
            (string) ($persona['foto_tipo'] ?? '') ?: 'image/jpeg', true,
            'foto-' . $personaId . '.' . (pathinfo($archivo, PATHINFO_EXTENSION) ?: 'jpg'));
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    /**
     * La propuesta más reciente de cada persona, con su lugar en la agenda.
     * Casi siempre es una sola; si alguien mandó dos, vale la última. De la
     * agenda, lo mismo que muestra la agenda pública: lo publicado.
     */
    private const UNION_PROPUESTA = '
          LEFT JOIN {propuesta} pr ON pr.id = (SELECT MAX(x.id) FROM {propuesta} x WHERE x.persona_id = p.id)
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id AND c.publicada = 1
          LEFT JOIN {evento_dia} dc ON dc.id = c.evento_dia_id';

    /**
     * Los filtros de cada consulta, con lo que hace cada uno.
     *
     * Es la lista que muestra /api/v1 y también la que decide qué se acepta:
     * así la ayuda y lo que de verdad se admite no pueden separarse.
     *
     * @return array<string, string>
     */
    private function filtros(string $consulta, bool $conContacto): array
    {
        $evento = ['evento' => 'Número del evento. Sin él, el evento activo.'];
        if (!in_array($consulta, ['personas', 'expositores'], true)) {
            return $evento;
        }

        $textos = [
            'q'         => 'Texto en el nombre, la entidad o el municipio'
                . ($consulta === 'expositores' ? ', o en el título de la propuesta' : '')
                . ($conContacto ? ', o en el correo' : '') . '.',
            'entidad'   => 'La entidad contiene este texto.',
            'municipio' => 'El municipio contiene este texto.',
        ];
        $paginas = [
            'pagina' => 'Número de página, desde 1. Pasada la última, llega vacía.',
            'por'    => 'Cuántos por página, de 1 a ' . self::TOPE_POR_PAGINA . '. Por omisión, ' . self::POR_PAGINA . '.',
            'dir'    => 'asc o desc. Por omisión, asc.',
        ];

        if ($consulta === 'personas') {
            return [
                'perfil'           => 'Clave del perfil, o varias separadas por comas: expositor,prensa. Ver /api/v1/perfiles.',
                'dia'              => 'Solo quienes registraron su ingreso ese día del evento: 1, 2, 3…',
                'completo'         => '1: solo los registros completos. 0: solo los que están a medias, que crearon su acceso y no terminaron el formulario.',
                'departamento'     => 'El departamento contiene este texto.',
                'registrado_desde' => 'Fecha AAAA-MM-DD: registrados ese día o después.',
                'registrado_hasta' => 'Fecha AAAA-MM-DD: registrados ese día o antes.',
            ] + $textos + [
                'orden' => 'nombre, entidad, municipio, perfil, ingresos o registro. Por omisión, nombre.',
            ] + $paginas + $evento;
        }

        return [
            'estado'    => 'De la propuesta: ' . implode(', ', self::ESTADOS) . '.',
            'categoria' => 'La categoría de la propuesta, escrita igual que en ella.',
            'dia'       => 'Solo las exposiciones agendadas ese día del evento: 1, 2, 3…',
        ] + $textos + [
            'orden' => 'nombre, entidad, registro, estado o jornada —por día y hora de la agenda—. Por omisión, nombre.',
        ] + $paginas + $evento;
    }

    /**
     * Corta la petición si trae algo que esta consulta no conoce: un filtro
     * mal escrito daría, sin aviso, la lista entera.
     *
     * @param array<string, string> $filtros
     */
    private function admitir(Peticion $peticion, array $filtros): void
    {
        foreach ($peticion->parametrosDeConsulta() as $clave => $esTexto) {
            if (!isset($filtros[$clave]) && !in_array($clave, self::SIEMPRE, true)) {
                $this->error(400, 'Filtro desconocido', '«' . mb_substr($clave, 0, 40) . '» no es un filtro de esta consulta. '
                    . 'Admite: ' . implode(', ', array_keys($filtros)) . '.');
            }
            if (!$esTexto) {
                $this->error(400, 'Filtro no válido', $clave . ' va una sola vez y sin corchetes'
                    . ($clave === 'perfil' ? '; para varios perfiles, sepáralos con comas' : '') . '.');
            }
        }

        $this->numero($peticion, 'pagina');
        $this->numero($peticion, 'por', self::TOPE_POR_PAGINA);
    }

    /**
     * Los filtros que comparten las dos listas.
     *
     * Los textos van con LIKE en parámetros. El correo solo entra en la
     * búsqueda con alcance «contacto»: si no, la búsqueda serviría para
     * averiguar si un correo está registrado, que es justo lo que ese alcance
     * no deja ver.
     *
     * @return array{0: array<int, string>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function filtrosComunes(Peticion $peticion, int $eventoId, bool $conContacto, bool $conTitulo = false): array
    {
        $donde = ['p.evento_id = :evento'];
        $parametros = ['evento' => $eventoId];
        $aplicados = [];

        $q = $peticion->query('q');
        if ($q !== '') {
            $campos = ['p.nombre', 'p.entidad', 'p.municipio'];
            if ($conContacto) {
                $campos[] = 'p.correo';
            }
            if ($conTitulo) {
                $campos[] = 'pr.titulo';
            }
            $partes = [];
            foreach ($campos as $i => $campo) {
                $partes[] = $campo . ' LIKE :q' . $i;
                $parametros['q' . $i] = $this->patron($q);
            }
            $donde[] = '(' . implode(' OR ', $partes) . ')';
            $aplicados['q'] = $q;
        }

        foreach (['entidad', 'municipio'] as $campo) {
            $valor = $peticion->query($campo);
            if ($valor !== '') {
                $donde[] = 'p.' . $campo . ' LIKE :' . $campo;
                $parametros[$campo] = $this->patron($valor);
                $aplicados[$campo] = $valor;
            }
        }

        return [$donde, $parametros, $aplicados];
    }

    /** La página pedida, con su paginación y su propuesta. */
    private function listar(
        Peticion $peticion,
        array $evento,
        array $donde,
        array $parametros,
        array $aplicados,
        Orden $orden,
        string $uniones,
        array $token
    ): never {
        $eventoId = (int) $evento['id'];
        // La propuesta entra en la consulta solo donde se filtra u ordena por
        // ella —los expositores—. Para mostrarla se trae aparte, de una vez
        // para toda la página.
        $where = implode(' AND ', $donde);

        $total = (int) Bd::valor('SELECT COUNT(*) FROM {persona} p' . $uniones . ' WHERE ' . $where, $parametros);
        $paginacion = Paginacion::conTope($peticion, $total, self::POR_PAGINA, self::TOPE_POR_PAGINA);
        $paginas = $paginacion->paginas();

        $filas = Bd::filas(
            'SELECT p.id, p.evento_id, p.nombre, p.correo, p.telefono, p.comparte_telefono, p.entidad,
                    p.municipio, p.departamento, p.rol, p.documento_huella, p.en_directorio, p.creado_en, p.foto,
                    (SELECT cr.codigo FROM {credencial} cr
                      WHERE cr.persona_id = p.id AND cr.revocada_en IS NULL ORDER BY cr.id DESC LIMIT 1) AS carnet,
                    (SELECT GROUP_CONCAT(d.numero ORDER BY d.numero) FROM {asistencia} a
                       JOIN {evento_dia} d ON d.id = a.evento_dia_id WHERE a.persona_id = p.id) AS dias
               FROM {persona} p' . $uniones . '
              WHERE ' . $where . '
           ORDER BY ' . $orden->sql() . '
              LIMIT ' . $paginacion->porPagina . ' OFFSET ' . $paginacion->desplazamiento(),
            $parametros
        );

        $propuestas = $this->propuestasDe(array_map(static fn(array $f): int => (int) $f['id'], $filas));
        $exigeDocumento = Formulario::exigeDocumento($eventoId);

        $this->responder([
            'evento'     => $this->datosEvento($evento),
            'filtros'    => (object) $aplicados,
            'orden'      => ['campo' => $orden->clave, 'dir' => $orden->direccion],
            'paginacion' => [
                'pagina'     => $paginacion->pagina,
                'por_pagina' => $paginacion->porPagina,
                'total'      => $paginacion->total,
                'paginas'    => $paginas,
                'siguiente'  => $paginacion->pagina < $paginas ? $paginacion->pagina + 1 : null,
                // Desde una página pasada la última, la anterior que sirve es la última.
                'anterior'   => $paginacion->pagina > 1 ? min($paginacion->pagina - 1, $paginas) : null,
            ],
            'datos'      => array_map(
                fn(array $f): array => $this->persona($f, $propuestas[(int) $f['id']] ?? null, $token, $exigeDocumento),
                $filas
            ),
        ]);
    }

    /**
     * La propuesta más reciente de cada una de esas personas, en una consulta.
     *
     * @param array<int, int> $ids
     * @return array<int, array>
     */
    private function propuestasDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $porPersona = [];
        foreach (Bd::filas(
            'SELECT pr.id, pr.persona_id, pr.titulo, pr.categoria, pr.detalle, pr.duracion_min, pr.estado,
                    pr.dia_preferido, c.hora_inicio, c.salon, dc.numero AS dia, dc.fecha
               FROM {propuesta} pr
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id AND c.publicada = 1
          LEFT JOIN {evento_dia} dc ON dc.id = c.evento_dia_id
              WHERE pr.persona_id IN (' . implode(',', array_map('intval', $ids)) . ')
           ORDER BY pr.id',
            []
        ) as $fila) {
            $porPersona[(int) $fila['persona_id']] = $fila;
        }
        return $porPersona;
    }

    /** Una persona como sale por la API. Lo que no está aquí, no sale. */
    private function persona(array $p, ?array $propuesta, array $token, bool $exigeDocumento): array
    {
        $conContacto = $token['alcance'] === 'contacto';
        $item = [
            'id'                => (int) $p['id'],
            'nombre'            => (string) $p['nombre'],
            'perfil'            => ['clave' => (string) $p['rol'], 'nombre' => etiquetaRol((string) $p['rol'], (int) $p['evento_id'])],
            'entidad'           => (string) $p['entidad'],
            'municipio'         => (string) $p['municipio'],
            'departamento'      => (string) $p['departamento'],
            'registro_completo' => Persona::registroCompleto($p, $exigeDocumento),
            'carnet'            => $p['carnet'] !== null && $p['carnet'] !== '' ? (string) $p['carnet'] : null,
            'ingresos'          => Persona::diasDe($p['dias'] ?? null),
            // Si aceptó aparecer en el directorio del evento. Quien publique
            // nombres en otro sitio debería respetarlo.
            'en_directorio'     => (int) $p['en_directorio'] === 1,
            'registrado_en'     => $this->fechaIso((string) $p['creado_en']),
        ];

        // El enlace de la fotografía, firmado para este token: sirve sin el
        // token y se puede poner tal cual en una página. null si no tiene foto.
        if (TokenApi::daFoto($token)) {
            $archivo = (string) ($p['foto'] ?? '');
            $item['foto'] = $archivo === '' ? null : Url::absoluta('/api/v1/fotos/' . (int) $p['id'] . '/'
                . (int) $token['id'] . '/' . TokenApi::firmaFoto($token, (int) $p['id'], $archivo));
        }

        if ($conContacto) {
            $item['correo'] = (string) $p['correo'];
            $item['telefono'] = (string) $p['telefono'] !== '' ? (string) $p['telefono'] : null;
            // Si autorizó compartir el teléfono con otros asistentes: el panel
            // lo ve igual, pero quien lo muestre a terceros debería mirarlo.
            $item['comparte_telefono'] = (int) $p['comparte_telefono'] === 1;
        }

        $item['propuesta'] = $propuesta === null ? null : [
            'id'            => (int) $propuesta['id'],
            'titulo'        => (string) $propuesta['titulo'],
            'categoria'     => (string) $propuesta['categoria'],
            'resumen'       => (string) $propuesta['detalle'],
            'duracion_min'  => (int) $propuesta['duracion_min'],
            'estado'        => (string) $propuesta['estado'],
            'dia_preferido' => (int) $propuesta['dia_preferido'] > 0 ? (int) $propuesta['dia_preferido'] : null,
            'agenda'        => $propuesta['dia'] === null ? null : [
                'dia'   => (int) $propuesta['dia'],
                'fecha' => (string) $propuesta['fecha'],
                'hora'  => substr((string) $propuesta['hora_inicio'], 0, 5),
                'salon' => (string) $propuesta['salon'],
            ],
        ];

        return $item;
    }

    /**
     * Los perfiles del evento y cuántas personas tiene cada uno.
     *
     * Con los que tenga alguien aunque ya no estén en la lista del formulario:
     * si no, la suma no daría el total, y a esas personas no se las podría
     * filtrar por su perfil.
     *
     * @return array<string, array{nombre: string, total: int}>
     */
    private function perfilesConTotal(int $eventoId): array
    {
        $totales = [];
        foreach (Bd::filas('SELECT rol, COUNT(*) AS n FROM {persona} WHERE evento_id = ? GROUP BY rol', [$eventoId]) as $fila) {
            $totales[(string) $fila['rol']] = (int) $fila['n'];
        }

        $perfiles = [];
        foreach (perfilesDelEvento($eventoId) as $clave => $nombre) {
            $perfiles[(string) $clave] = ['nombre' => (string) $nombre, 'total' => $totales[(string) $clave] ?? 0];
        }
        foreach ($totales as $clave => $n) {
            $perfiles[$clave] ??= ['nombre' => etiquetaRol($clave, $eventoId), 'total' => $n];
        }
        return $perfiles;
    }

    /** El evento pedido con ?evento=, o el activo. */
    private function evento(Peticion $peticion): array
    {
        $id = $this->numero($peticion, 'evento');
        if ($id !== null) {
            $evento = Bd::fila('SELECT * FROM {evento} WHERE id = ?', [$id]);
            if ($evento === null) {
                $this->error(404, 'Evento no encontrado', 'No hay ningún evento con el número ' . $id . '. Ver /api/v1.');
            }
            return $evento;
        }
        $evento = App::eventoActivo();
        if ($evento === null) {
            $this->error(404, 'Sin evento activo', 'No hay ningún evento activo: indica uno con ?evento=… (ver /api/v1).');
        }
        return $evento;
    }

    private function datosEvento(array $evento): array
    {
        return [
            'id'     => (int) $evento['id'],
            'nombre' => (string) $evento['nombre'],
            'dias'   => array_map(static fn(array $d): array => [
                'numero' => (int) $d['numero'], 'fecha' => (string) $d['fecha'],
            ], $this->jornadas((int) $evento['id'])),
        ];
    }

    private function jornadas(int $eventoId): array
    {
        return $this->jornadas[$eventoId] ??= Evento::jornadas($eventoId);
    }

    /** El día pedido con ?dia=, que tiene que ser uno del evento. */
    private function dia(Peticion $peticion, array $evento): ?int
    {
        $dia = $this->numero($peticion, 'dia');
        if ($dia === null) {
            return null;
        }
        $dias = array_map(static fn(array $d): int => (int) $d['numero'], $this->jornadas((int) $evento['id']));
        if (!in_array($dia, $dias, true)) {
            $this->error(400, 'Día no válido', $dias === []
                ? 'El evento todavía no tiene días.'
                : 'El evento tiene ' . (count($dias) === 1 ? 'solo el día ' : 'los días ') . implode(', ', $dias) . '.');
        }
        return $dia;
    }

    /**
     * El orden pedido. Uno que no existe responde 400: en el panel se cae al
     * de siempre, pero un programa tiene que enterarse de que pidió mal.
     */
    private function orden(Peticion $peticion, array $columnas): Orden
    {
        $clave = $peticion->query('orden');
        if ($clave !== '' && !isset($columnas[$clave])) {
            $this->error(400, 'Orden desconocido', 'orden admite: ' . implode(', ', array_keys($columnas)) . '.');
        }
        $direccion = $peticion->query('dir');
        if ($direccion !== '' && !in_array($direccion, ['asc', 'desc'], true)) {
            $this->error(400, 'Dirección desconocida', 'dir admite asc o desc.');
        }
        return Orden::desde($peticion, $columnas, 'nombre', 'asc', "(TRIM(p.nombre) = '') ASC, p.nombre ASC, p.id ASC");
    }

    /**
     * Un número entero de la dirección, o null si no vino. Uno que no lo es
     * responde 400: «dia=uno» no puede volverse «sin filtro».
     */
    private function numero(Peticion $peticion, string $clave, int $maximo = 1000000): ?int
    {
        $valor = $peticion->query($clave);
        if ($valor === '') {
            return null;
        }
        if (!ctype_digit($valor) || (int) $valor < 1 || (int) $valor > $maximo) {
            $this->error(400, 'Número no válido', $clave . ' va como un número entero'
                . ($maximo < 1000000 ? ' de 1 a ' . $maximo : ', desde 1') . '.');
        }
        return (int) $valor;
    }

    /**
     * El token, de la dirección o de una cabecera. Corta la petición si falta,
     * si no sirve o si su dueño ya hizo demasiadas consultas.
     */
    private function autenticar(Peticion $peticion): array
    {
        $token = $peticion->query('token');
        if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', $peticion->cabecera('Authorization'), $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            $token = $peticion->cabecera('X-Api-Token');
        }
        if ($token === '') {
            $this->error(401, 'Falta el token', 'Agrega el token a la dirección (?token=…) o mándalo en la cabecera '
                . '«Authorization: Bearer …». Se crea en Configuración → API.');
        }

        $huella = TokenApi::huella(strtolower($token));
        try {
            $fila = $huella === null ? null : TokenApi::vigente($huella);
        } catch (\Throwable $e) {
            // Sin la tabla, la base no se ha puesto al día con esta versión.
            Registro::excepcion($e);
            $this->error(503, 'API no disponible', 'La base de datos de la plataforma todavía no se ha actualizado '
                . 'para la API. Quien la administra lo resuelve desde el panel.');
        }

        if ($fila === null) {
            // Se corta por IP a quien insiste con tokens que no sirven. Adivinar
            // uno de 256 bits no es viable, pero insistir es una señal que no
            // hace falta seguir atendiendo. Solo a esas peticiones: un sistema
            // con un token vigente no se queda sin servicio porque otro, detrás
            // de la misma dirección, siga usando uno revocado.
            $ip = $peticion->ip();
            $espera = Limite::bloqueado('api_token', $ip);
            if ($espera > 0) {
                $minutos = (int) ceil($espera / 60);
                $this->error(429, 'Demasiados intentos', 'Demasiados intentos con un token que no sirve desde esta '
                    . 'dirección. Vuelve a intentarlo en ' . $minutos . ' ' . ($minutos === 1 ? 'minuto' : 'minutos') . '.',
                    ['Retry-After' => (string) $espera]);
            }
            Limite::registrarFallo('api_token', $ip);
            $this->error(401, 'Token no válido', 'El token no existe o fue revocado. Pide uno nuevo a quien administra la plataforma.');
        }

        if (TokenApi::anotarUso((int) $fila['id'], $peticion->ip()) > TokenApi::TOPE) {
            $this->error(429, 'Demasiadas consultas', 'Este token pasó de ' . TokenApi::TOPE . ' consultas en '
                . TokenApi::VENTANA_MINUTOS . ' minutos. Espera un momento y vuelve a intentarlo.', ['Retry-After' => '60']);
        }

        return $fila;
    }

    /** «expositor, prensa» → ['expositor', 'prensa'], sin vacíos ni repetidos. */
    private function lista(string $valor): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $v): string => mb_strtolower(trim($v)),
            explode(',', $valor)
        ), static fn(string $v): bool => $v !== '')));
    }

    /** Un texto para LIKE, con sus comodines escapados. */
    private function patron(string $texto): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr($texto, 0, 120)) . '%';
    }

    private function fechaIso(string $fecha): ?string
    {
        $marca = $fecha !== '' ? strtotime($fecha) : false;
        return $marca === false ? null : date(DATE_ATOM, $marca);
    }

    private function error(int $codigo, string $titulo, string $mensaje, array $cabeceras = []): never
    {
        foreach ($cabeceras as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        $this->responder(['error' => ['codigo' => $codigo, 'titulo' => $titulo, 'mensaje' => $mensaje]], $codigo);
    }

    /**
     * La respuesta, legible a simple vista —quien prueba la API la abre en el
     * navegador— y sin quedar en ninguna caché: son datos personales.
     */
    private function responder(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
