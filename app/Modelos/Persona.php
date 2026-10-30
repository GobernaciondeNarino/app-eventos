<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;
use App\Nucleo\Imagen;
use App\Nucleo\Orden;
use App\Nucleo\Peticion;

/**
 * El asistente al evento.
 *
 * El número de documento se guarda cifrado y además como huella HMAC. La huella
 * permite detectar que alguien ya está registrado sin descifrar toda la tabla;
 * el valor cifrado solo se abre cuando de verdad hay que mostrarlo, que es en
 * el carnet de esa misma persona y en la pantalla del operador que la está
 * acreditando.
 */
final class Persona
{
    /**
     * Los perfiles de asistencia que trae la plataforma, con su nombre.
     *
     * Desde la 3.8 cada evento los cambia en Configuración → Registro: agrega
     * los suyos, les cambia el nombre, los apaga o los elimina
     * (App\Modelos\Formulario). Lo que se guarda en la persona es la clave de
     * la izquierda, que no cambia aunque cambie el nombre: así renombrar un
     * perfil no le cambia nada a quien ya lo tiene.
     */
    public const PERFILES_DE_FABRICA = [
        'participante'      => 'Participante',
        'visitante'         => 'Visitante',
        'expositor'         => 'Expositor',
        'prensa'            => 'Prensa',
        'rueda_de_negocios' => 'Rueda de Negocios',
        'comunicaciones'    => 'Comunicaciones',
    ];

    /**
     * Los que la plataforma usa por su clave: se renombran, pero no se eliminan.
     * «Participante» es el de quien no elige ninguno; «expositor» va con las
     * propuestas de exposición.
     */
    public const PERFILES_DEL_SISTEMA = ['participante', 'expositor'];

    /**
     * Los que solo pone un administrador, desde la ficha de la persona.
     *
     * No entran en la lista configurable ni se ofrecen nunca en el formulario,
     * y no es un detalle de presentación: «staff» da acceso a la plataforma
     * —ver los carnets de todo el mundo, con su cédula, y sellar ingresos—, así
     * que ofrecerlo en un formulario abierto al público sería dejar que
     * cualquiera se lo asignara. Esconder la opción no basta: un envío hecho a
     * mano no pasa por la pantalla. Por eso el registro público valida contra
     * la lista del evento, que nunca los contiene.
     */
    public const PERFILES_DE_ADMIN = ['staff' => 'Staff', 'organizador' => 'Organizador'];

    /**
     * Los valores que admitía la columna antes de la 1.9.0, cuando era un ENUM.
     * Una base que todavía no se puso al día solo acepta estos.
     */
    public const PERFILES_DE_ANTES = ['participante', 'visitante', 'expositor', 'organizador', 'prensa'];

    /** ¿Este perfil da acceso a acreditar y a ver los carnets? */
    public static function esStaff(?array $persona): bool
    {
        return $persona !== null && (string) ($persona['rol'] ?? '') === 'staff';
    }

    /**
     * ¿Expone? Por su perfil, o porque ya mandó una propuesta aunque después
     * le cambiaran el perfil. Para la navegación y el carnet.
     */
    public static function esExpositor(?array $persona): bool
    {
        if ($persona === null) {
            return false;
        }
        if ((string) ($persona['rol'] ?? '') === 'expositor') {
            return true;
        }
        return (int) Bd::valor('SELECT COUNT(*) FROM {propuesta} WHERE persona_id = ?', [(int) $persona['id']]) > 0;
    }

    /**
     * La propuesta de una persona —la última, si mandó más de una—, con el
     * día, la hora y el salón que le asignó el comité si ya está aprobada.
     */
    public static function suPropuesta(int $personaId): ?array
    {
        return Bd::fila(
            'SELECT pr.*, c.hora_inicio, c.salon, d.numero AS dia_asignado, d.fecha AS fecha_asignada
               FROM {propuesta} pr
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id
          LEFT JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE pr.persona_id = ?
           ORDER BY pr.id DESC
              LIMIT 1',
            [$personaId]
        );
    }

    /**
     * El perfil que de verdad se guarda desde el formulario público.
     *
     * Dos reglas, y la segunda es la que no se ve venir:
     *
     * 1. No se puede subir. El enviado vale solo si está entre los que ofrece
     *    el formulario del evento ($ofrecidos), que nunca tiene los de admin.
     * 2. Tampoco se puede perder. Un perfil que solo pone un administrador
     *    —«staff», «organizador»— se conserva pase lo que pase en el envío. Si
     *    no, a alguien del staff le bastaba con abrir «mis datos» y guardar para
     *    quedarse sin su perfil: el selector del formulario no tiene su opción,
     *    así que el navegador manda la primera de la lista.
     *
     * @param array<int, string> $ofrecidos las claves que ofrece el formulario
     */
    public static function rolAdmitido(string $enviado, string $actual, array $ofrecidos): string
    {
        if (isset(self::PERFILES_DE_ADMIN[$actual])) {
            return $actual;
        }
        if (in_array($enviado, $ofrecidos, true) && !isset(self::PERFILES_DE_ADMIN[$enviado])) {
            return $enviado;
        }
        return $actual !== '' ? $actual : 'participante';
    }

    public static function porId(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {persona} WHERE id = ?', [$id]);
    }

    public static function porCorreo(int $eventoId, string $correo): ?array
    {
        return Bd::fila(
            'SELECT * FROM {persona} WHERE evento_id = ? AND correo = ?',
            [$eventoId, mb_strtolower(trim($correo))]
        );
    }

    public static function porDocumento(int $eventoId, string $documento): ?array
    {
        return Bd::fila(
            'SELECT * FROM {persona} WHERE evento_id = ? AND documento_huella = ?',
            [$eventoId, Cripto::huella(self::normalizarDocumento($documento))]
        );
    }

    /**
     * El documento tal como se compara y se guarda.
     *
     * Se conservan las letras. Quitándolas, dos pasaportes distintos —AB123456
     * y CD123456— quedaban en el mismo «123456» y la plataforma rechazaba al
     * segundo diciéndole que su documento ya estaba registrado con otro correo.
     * Lo mismo con las cédulas de extranjería.
     *
     * Se quitan puntos, espacios y guiones, que es lo que la gente escribe de
     * más, y se pasa a mayúsculas para que la comparación no dependa de cómo
     * lo teclee cada quien.
     */
    public static function normalizarDocumento(string $documento): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/u', '', $documento) ?? '');
    }

    public static function documento(array $persona): string
    {
        // Nulo: la persona creó su acceso con correo y contraseña y todavía no
        // ha llenado el formulario. No es un error, es un registro a medias.
        if (($persona['documento_cifrado'] ?? null) === null) {
            return '';
        }
        try {
            return Cripto::descifrar((string) $persona['documento_cifrado']);
        } catch (\Throwable) {
            // Si la llave cambió, el dato es ilegible. Mejor decirlo que
            // mostrar basura en un carnet.
            return '';
        }
    }

    /**
     * ¿Terminó de registrarse, o solo creó su acceso?
     *
     * Desde la 3.2 se puede entrar con correo y contraseña sin llenar nada
     * más, y completar el formulario después. Mientras falten el nombre o la
     * identificación no se emite carnet: un carnet sin nombre no sirve en la
     * puerta, y la ficha de acreditación existe para comparar contra el
     * documento físico.
     */
    public static function registroCompleto(array $persona, ?bool $exigeDocumento = null): bool
    {
        if (trim((string) ($persona['nombre'] ?? '')) === '') {
            return false;
        }
        // La identificación cuenta solo si el evento la exige: desde la 3.7 se
        // puede dejar opcional o quitar, y entonces el carnet sale con el
        // nombre. Con dos formularios (3.9), si alguno la deja opcional.
        $exigeDocumento ??= isset($persona['evento_id'])
            ? Formulario::exigeDocumento((int) $persona['evento_id'])
            : true;
        return !$exigeDocumento || ($persona['documento_huella'] ?? null) !== null;
    }

    /**
     * Lo que le falta a su perfil, en palabras: «tu foto», «tu entidad»…
     *
     * Para invitarla a completarlo y que el carnet con el que va a entrar al
     * evento salga entero (3.17). Cuenta solo lo que el formulario pide y que
     * sale en el carnet o sirve para ubicarla: el nombre, la identificación,
     * la foto, la entidad, el teléfono y el municipio. La caracterización no:
     * es opcional por ley, y no se insiste en ella.
     *
     * @return array<int, string>
     */
    public static function faltantes(array $persona, Formulario $f): array
    {
        $vacio = static fn(string $columna): bool => trim((string) ($persona[$columna] ?? '')) === '';
        $faltan = [];
        if ($vacio('nombre')) {
            $faltan[] = 'tu nombre';
        }
        if ($f->visible('documento') && ($persona['documento_huella'] ?? null) === null) {
            $faltan[] = 'tu identificación';
        }
        if ($f->visible('foto') && !self::tieneFoto($persona)) {
            $faltan[] = 'tu foto';
        }
        if ($f->visible('entidad') && $vacio('entidad')) {
            $faltan[] = 'tu entidad';
        }
        if ($f->visible('telefono') && $vacio('telefono')) {
            $faltan[] = 'tu teléfono';
        }
        if ($f->visible('ubicacion') && $vacio('municipio')) {
            $faltan[] = 'tu municipio';
        }
        return $faltan;
    }

    /**
     * Crea el acceso de alguien que todavía no se ha registrado.
     *
     * Correo y contraseña, y nada más. Es la puerta que faltaba: quien llega a
     * la pantalla de ingreso sin estar inscrito se encontraba con que la única
     * salida era un formulario de tres pantallas, y ahí se pierde la mitad de
     * la gente. Con esto entra en quince segundos y termina sus datos dentro.
     *
     * La autorización de tratamiento de datos SÍ se pide aquí: es el momento
     * en que se crea el registro, y la Ley 1581 no admite diferirla.
     */
    public static function crearAcceso(int $eventoId, string $correo, string $clave): array
    {
        $correo = mb_strtolower(trim($correo));

        return Bd::transaccion(static function () use ($eventoId, $correo, $clave): array {
            if (self::porCorreo($eventoId, $correo) !== null) {
                throw new \DomainException(
                    'Ese correo ya tiene acceso en este evento. Entra con él en vez de crearlo otra vez.'
                );
            }

            $id = Bd::insertar('persona', [
                'evento_id'         => $eventoId,
                'nombre'            => '',
                'correo'            => $correo,
                'clave_hash'        => Cripto::hashClave($clave),
                'autorizo_datos_en' => date('Y-m-d H:i:s'),
            ]);

            Bitacora::registrar('acceso_creado', 'persona', $id);

            return ['id' => $id, 'nueva' => true, 'credencial' => null];
        });
    }

    /**
     * Alta o actualización del preregistro.
     *
     * Se admite que una persona vuelva a diligenciar el formulario con el mismo
     * correo: pasa todo el tiempo, porque el enlace se comparte y la gente lo
     * llena dos veces. En ese caso se actualizan sus datos en vez de fallar.
     *
     * @param string $formulario por cuál llegó: Formulario::PUBLICO o ::EXPOSITORES
     */
    public static function registrar(int $eventoId, array $datos, string $formulario = Formulario::PUBLICO): array
    {
        $correo = mb_strtolower(trim((string) $datos['correo']));
        $documento = self::normalizarDocumento((string) ($datos['documento'] ?? ''));
        $huella = $documento === '' ? null : Cripto::huella($documento);

        return Bd::transaccion(static function () use ($eventoId, $datos, $correo, $documento, $huella, $formulario): array {
            $existente = self::porCorreo($eventoId, $correo);

            // El documento pertenece a otro correo del mismo evento: son dos
            // personas distintas diciendo tener la misma cédula.
            $porDocumento = $huella === null ? null : Bd::fila(
                'SELECT id, correo FROM {persona} WHERE evento_id = ? AND documento_huella = ?',
                [$eventoId, $huella]
            );
            if ($porDocumento && (!$existente || (int) $porDocumento['id'] !== (int) $existente['id'])) {
                throw new \DomainException(
                    'Ese número de identificación ya está registrado con otro correo. '
                    . 'Si es tuyo, entra con el correo que usaste la primera vez.'
                );
            }

            $campos = [
                'nombre'            => mb_substr(trim((string) $datos['nombre']), 0, 160),
                // La lista de tipos la configura cada evento y la valida el
                // formulario; aquí solo se cuida la forma, por si algo llega
                // sin pasar por él.
                'tipo_documento'    => preg_match('/^[A-Z0-9]{1,12}$/', (string) ($datos['tipo_documento'] ?? ''))
                                        ? (string) $datos['tipo_documento'] : 'CC',
                'documento_cifrado' => $documento === '' ? null : Cripto::cifrar($documento),
                'documento_huella'  => $huella,
                'telefono'          => mb_substr(trim((string) ($datos['telefono'] ?? '')), 0, 32),
                'entidad'           => mb_substr(trim((string) ($datos['entidad'] ?? '')), 0, 160),
                'departamento'      => mb_substr(trim((string) ($datos['departamento'] ?? '')), 0, 80),
                'municipio'         => mb_substr(trim((string) ($datos['municipio'] ?? '')), 0, 80),
                // El perfil se admite solo si es de los que puede elegir quien
                // llena el formulario. Si llega otro —«staff» en un envío hecho
                // a mano— se conserva el que ya tenía, no se baja a
                // participante: si no, un miembro del staff que entrara a
                // corregir su teléfono se quedaría sin su perfil.
                'rol'               => self::rolAdmitido(
                    (string) ($datos['rol'] ?? ''),
                    $existente ? (string) $existente['rol'] : 'participante',
                    Formulario::delEvento($eventoId, $formulario)->perfiles($existente ? (string) $existente['rol'] : '')
                ),
            ];

            if ($existente) {
                Bd::actualizar('persona', $campos, 'id = :id', ['id' => $existente['id']]);
                $id = (int) $existente['id'];
                $nueva = false;
            } else {
                $id = Bd::insertar('persona', $campos + [
                    'evento_id'         => $eventoId,
                    'correo'            => $correo,
                    'autorizo_datos_en' => date('Y-m-d H:i:s'),
                ]);
                $nueva = true;
            }

            self::guardarCaracterizacion($id, $datos);

            // El carnet solo se emite cuando el registro está completo. Uno sin
            // nombre ni identificación no sirve de nada en la puerta, y
            // emitirlo antes deja credenciales huérfanas de quien creó su
            // acceso y no volvió.
            $credencial = self::registroCompleto($campos, Formulario::exigeDocumento($eventoId))
                ? Credencial::asegurar($id) : null;

            Bitacora::registrar($nueva ? 'registro' : 'registro_actualizado', 'persona', $id, [
                'rol' => $campos['rol'],
                'municipio' => $campos['municipio'],
            ] + ($formulario === Formulario::PUBLICO ? [] : ['formulario' => $formulario]));

            return ['id' => $id, 'nueva' => $nueva, 'credencial' => $credencial];
        });
    }

    private static function guardarCaracterizacion(int $personaId, array $datos): void
    {
        $campos = [
            'genero'       => mb_substr((string) ($datos['genero'] ?? ''), 0, 60),
            'rango_edad'   => mb_substr((string) ($datos['rango_edad'] ?? ''), 0, 40),
            'etnia'        => mb_substr((string) ($datos['etnia'] ?? ''), 0, 60),
            'discapacidad' => mb_substr((string) ($datos['discapacidad'] ?? ''), 0, 60),
        ];

        // Si no diligenció nada, no se crea la fila: una tabla de datos
        // sensibles llena de registros vacíos solo agranda el riesgo.
        if (implode('', $campos) === '') {
            return;
        }

        $existe = (int) Bd::valor('SELECT COUNT(*) FROM {persona_caracterizacion} WHERE persona_id = ?', [$personaId]);
        if ($existe > 0) {
            Bd::actualizar('persona_caracterizacion', $campos, 'persona_id = :p', ['p' => $personaId]);
        } else {
            Bd::insertar('persona_caracterizacion', $campos + ['persona_id' => $personaId]);
        }
    }

    public static function caracterizacion(int $personaId): array
    {
        return Bd::fila('SELECT * FROM {persona_caracterizacion} WHERE persona_id = ?', [$personaId]) ?? [];
    }

    /**
     * Las condiciones del listado del backoffice.
     *
     * Las comparten la página, la cuenta, las cifras de arriba y la
     * exportación. Si cada una armara las suyas, tarde o temprano dirían cosas
     * distintas sobre el mismo filtro.
     *
     * Los filtros van en parámetros. 'completos' deja fuera los registros a
     * medias, con la misma regla que registroCompleto().
     *
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    private static function condiciones(int $eventoId, array $filtros): array
    {
        $donde = ['p.evento_id = :evento'];
        $parametros = ['evento' => $eventoId];

        if (!empty($filtros['texto'])) {
            // Un marcador distinto por columna. Con las sentencias preparadas
            // de verdad —sin emulación, que es como está configurado PDO—
            // MySQL no admite repetir el mismo nombre, y la consulta entera
            // fallaba: toda búsqueda por texto del panel respondía 500,
            // incluida la del escáner, que es la que se usa en la puerta
            // cuando a alguien no le funciona el código.
            $campos = '(p.nombre LIKE :texto1 OR p.correo LIKE :texto2'
                . ' OR p.entidad LIKE :texto3 OR p.municipio LIKE :texto4';
            $patron = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filtros['texto']) . '%';
            $parametros += ['texto1' => $patron, 'texto2' => $patron,
                            'texto3' => $patron, 'texto4' => $patron];

            // Y por número de identificación, que es lo que trae quien llega a
            // la puerta sin carnet y sin teléfono: la cédula en la mano.
            //
            // El número está cifrado, así que un LIKE sobre él no encuentra
            // nada —la pantalla lo ofrecía desde el principio y nunca funcionó—.
            // Lo que sí se puede es comparar la huella HMAC, que es exacta: o
            // se escribe el documento completo, o no aparece. Buscar por los
            // últimos cuatro dígitos exigiría descifrar la tabla entera en cada
            // búsqueda, y eso es justamente lo que el cifrado evita.
            $documento = self::normalizarDocumento((string) $filtros['texto']);
            if ($documento !== '') {
                $campos .= ' OR p.documento_huella = :huella';
                $parametros['huella'] = Cripto::huella($documento);
            }

            $donde[] = $campos . ')';
        }
        // Los perfiles los configura cada evento: basta con que tenga forma de
        // clave. Va en un parámetro, así que no hay nada que inyectar.
        if (!empty($filtros['rol']) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $filtros['rol'])) {
            $donde[] = 'p.rol = :rol';
            $parametros['rol'] = $filtros['rol'];
        }
        if (!empty($filtros['dia'])) {
            $donde[] = 'EXISTS (SELECT 1 FROM {asistencia} a
                                  JOIN {evento_dia} d ON d.id = a.evento_dia_id
                                 WHERE a.persona_id = p.id AND d.numero = :dia)';
            $parametros['dia'] = (int) $filtros['dia'];
        }
        if (!empty($filtros['completos'])) {
            $donde[] = 'NOT (' . self::sqlIncompleto($eventoId) . ')';
        }

        return [$donde, $parametros];
    }

    /**
     * registroCompleto() en SQL, al revés: la condición de un registro a
     * medias, sobre la tabla con el alias «p». La usa también la API.
     */
    public static function sqlIncompleto(int $eventoId): string
    {
        return "TRIM(p.nombre) = ''"
            . (Formulario::exigeDocumento($eventoId) ? ' OR p.documento_huella IS NULL' : '');
    }

    /**
     * Listado del backoffice, con filtros.
     *
     * Lo único que se interpola es el orden, y sale de las columnas que
     * declara cada pantalla (App\Nucleo\Orden).
     *
     * 'limite' y 'desde' parten el listado en páginas. Sin 'limite' trae todo
     * lo que deje el filtro, que es lo que necesita la exportación: hasta la
     * 3.12 se cortaba en silencio en quinientas filas, y el listado en
     * doscientas.
     */
    public static function buscar(int $eventoId, array $filtros = [], ?Orden $orden = null): array
    {
        [$donde, $parametros] = self::condiciones($eventoId, $filtros);

        $limite = '';
        if (isset($filtros['limite'])) {
            $limite = ' LIMIT ' . max(1, (int) $filtros['limite'])
                . ' OFFSET ' . max(0, (int) ($filtros['desde'] ?? 0));
        }

        return Bd::filas(
            'SELECT p.*,
                    (SELECT GROUP_CONCAT(d.numero ORDER BY d.numero)
                       FROM {asistencia} a
                       JOIN {evento_dia} d ON d.id = a.evento_dia_id
                      WHERE a.persona_id = p.id) AS dias
               FROM {persona} p
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY ' . ($orden?->sql() ?? 'p.nombre, p.id') . $limite,
            $parametros
        );
    }

    /** Cuántas personas deja el filtro, sin traerlas. */
    public static function contar(int $eventoId, array $filtros = []): int
    {
        [$donde, $parametros] = self::condiciones($eventoId, $filtros);
        return (int) Bd::valor(
            'SELECT COUNT(*) FROM {persona} p WHERE ' . implode(' AND ', $donde),
            $parametros
        );
    }

    /**
     * Las cifras de arriba del listado, sobre todo el filtro.
     *
     * Antes se sacaban de las filas que había en pantalla. Con el listado
     * cortado en doscientas, «Con al menos un ingreso» contaba solo a esas
     * doscientas; con páginas, contaría solo a las cincuenta de la página.
     *
     * @return array{en_filtro: int, con_ingreso: int, completos: int, a_medias: int, entidades: int}
     */
    public static function resumen(int $eventoId, array $filtros, int $jornadas): array
    {
        [$donde, $parametros] = self::condiciones($eventoId, $filtros);
        $dias = '(SELECT COUNT(*) FROM {asistencia} a
                    JOIN {evento_dia} d ON d.id = a.evento_dia_id
                   WHERE a.persona_id = p.id)';

        $fila = Bd::fila(
            'SELECT COUNT(*) AS en_filtro,
                    COALESCE(SUM(' . $dias . ' > 0), 0) AS con_ingreso,
                    COALESCE(SUM(' . ($jornadas > 0 ? $dias . ' >= ' . $jornadas : '0') . '), 0) AS completos,
                    COALESCE(SUM(' . self::sqlIncompleto($eventoId) . '), 0) AS a_medias,
                    COUNT(DISTINCT NULLIF(p.entidad, \'\')) AS entidades
               FROM {persona} p
              WHERE ' . implode(' AND ', $donde),
            $parametros
        ) ?? [];

        return array_map('intval', [
            'en_filtro'   => $fila['en_filtro'] ?? 0,
            'con_ingreso' => $fila['con_ingreso'] ?? 0,
            'completos'   => $fila['completos'] ?? 0,
            'a_medias'    => $fila['a_medias'] ?? 0,
            'entidades'   => $fila['entidades'] ?? 0,
        ]);
    }

    /**
     * Por qué columnas se puede ordenar el listado de personas.
     *
     * El documento no está: va cifrado, y ordenar por él exigiría descifrar a
     * todo el evento en cada página, que es justo lo que el cifrado evita. El
     * perfil se ordena por su nombre, no por su clave interna, que es lo que
     * se ve en la tabla.
     *
     * @return array<string, array{0: string|array<int, string>, 1?: string}>
     */
    public static function columnasDeOrden(int $eventoId): array
    {
        $perfiles = perfilesDelEvento($eventoId);
        $sinTildes = static fn(string $t): string => strtr(mb_strtolower($t),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n~']);
        uasort($perfiles, static fn(string $a, string $b): int => strcmp($sinTildes($a), $sinTildes($b)));
        $claves = array_filter(array_keys($perfiles),
            static fn($c): bool => (bool) preg_match('/^[a-z0-9_]{1,40}$/', (string) $c));

        return [
            'nombre'    => ['p.nombre', "TRIM(p.nombre) = ''"],
            'municipio' => ['p.municipio', "p.municipio = ''"],
            'entidad'   => ['p.entidad', "p.entidad = ''"],
            // Las claves se comprobaron arriba: solo letras, números y guiones bajos.
            'perfil'    => [$claves ? "FIELD(p.rol, '" . implode("', '", $claves) . "')" : 'p.rol'],
            'ingresos'  => ['(SELECT COUNT(*) FROM {asistencia} a
                                JOIN {evento_dia} d ON d.id = a.evento_dia_id
                               WHERE a.persona_id = p.id)'],
        ];
    }

    /**
     * El orden elegido en la tabla, o por nombre si no se eligió ninguno.
     *
     * En los empates —la misma entidad, el mismo municipio— van por nombre, y
     * los registros a medias, que todavía no tienen nombre, al final.
     */
    public static function ordenElegido(Peticion $peticion, int $eventoId): Orden
    {
        return Orden::desde($peticion, self::columnasDeOrden($eventoId), 'nombre', 'asc',
            "(TRIM(p.nombre) = '') ASC, p.nombre ASC, p.id ASC");
    }

    /**
     * Las personas que pueden tener carnet, para listarlo e imprimirlo.
     *
     * Deja fuera los registros a medias —quien creó su acceso con correo y
     * contraseña y no llenó el formulario—: un carnet sin nombre ni documento
     * es una cartulina en blanco, y en una tanda de doscientas se cuela sin que
     * nadie la vea hasta que la reparte. Se filtra en la consulta y no
     * después, para que las páginas y la cuenta salgan bien.
     */
    public static function conCredencial(int $eventoId, array $filtros = [], ?Orden $orden = null): array
    {
        return self::buscar($eventoId, ['completos' => true] + $filtros, $orden);
    }

    /**
     * Quienes se registraron como expositores y todavía no han enviado su
     * propuesta: los de perfil Expositor y los que llegaron por el enlace
     * privado, sin ninguna propuesta.
     *
     * Pasa todo el tiempo: con «Voy a exponer» marcado, el formulario pide la
     * hoja de vida y la presentación, y quien no las tiene a mano se registra
     * sin la casilla para volver después. La lista de propuestas no los
     * muestra —no tienen ninguna—, y son justo a quienes hay que escribirles.
     *
     * @param array<int, true> $porEnlace los de Formulario::llegadosPorEnlace()
     */
    public static function expositoresSinPropuesta(
        int $eventoId,
        array $porEnlace = [],
        ?Orden $orden = null,
        ?int $limite = null,
        int $desde = 0
    ): array {
        return Bd::filas(
            'SELECT p.id, p.nombre, p.correo, p.telefono, p.entidad, p.municipio, p.rol, p.creado_en
               FROM {persona} p
              WHERE ' . self::sqlSinPropuesta($porEnlace) . '
           ORDER BY ' . ($orden?->sql() ?? 'p.creado_en DESC, p.id DESC')
            . ($limite !== null ? ' LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $desde) : ''),
            [$eventoId]
        );
    }

    /** Cuántos son, sin traerlos: para los contadores del panel y de Expositores. */
    public static function contarExpositoresSinPropuesta(int $eventoId, array $porEnlace = []): int
    {
        return (int) Bd::valor(
            'SELECT COUNT(*) FROM {persona} p WHERE ' . self::sqlSinPropuesta($porEnlace),
            [$eventoId]
        );
    }

    /** Los ids del enlace son enteros: se pueden escribir en la consulta. */
    private static function sqlSinPropuesta(array $porEnlace): string
    {
        $ids = array_map('intval', array_keys($porEnlace));
        return "p.evento_id = ?
                AND NOT EXISTS (SELECT 1 FROM {propuesta} pr WHERE pr.persona_id = p.id)
                AND (p.rol = 'expositor'" . ($ids ? ' OR p.id IN (' . implode(',', $ids) . ')' : '') . ')';
    }

    /** Días en que ingresó, como arreglo de enteros. */
    public static function diasDe(?string $concatenado): array
    {
        if (!$concatenado) {
            return [];
        }
        return array_map('intval', explode(',', $concatenado));
    }

    /* =====================================================================
       Fotografía del carnet
       ===================================================================== */

    /**
     * Guarda la foto ya procesada y borra la anterior.
     *
     * El procesado —tipo real, reescritura, recorte— está en App\Nucleo\Imagen.
     * Aquí solo se toca la base y el archivo viejo, para que no queden huérfanos
     * ocupando disco en el servidor.
     */
    public static function ponerFoto(int $id, string $archivo, string $tipo): void
    {
        $anterior = (string) (Bd::valor('SELECT foto FROM {persona} WHERE id = ?', [$id]) ?? '');

        Bd::ejecutar('UPDATE {persona} SET foto = ?, foto_tipo = ? WHERE id = ?', [
            $archivo,
            $tipo,
            $id,
        ]);

        if ($anterior !== '' && $anterior !== $archivo) {
            Imagen::borrarFoto($anterior);
        }
    }

    public static function quitarFoto(int $id): void
    {
        $anterior = (string) (Bd::valor('SELECT foto FROM {persona} WHERE id = ?', [$id]) ?? '');
        Bd::ejecutar("UPDATE {persona} SET foto = '', foto_tipo = '' WHERE id = ?", [$id]);
        Imagen::borrarFoto($anterior);
    }

    public static function tieneFoto(array $persona): bool
    {
        return (string) ($persona['foto'] ?? '') !== '';
    }

    /* =====================================================================
       Métodos de acceso distintos del código por correo
       -------------------------------------------------------------------------
       Existen porque atar la entrada al correo dejó a todo el mundo fuera
       cuando el correo falló. Ver App\Nucleo\Autenticacion.
       ===================================================================== */

    /** Guarda la contraseña simple de una persona. Se guarda el hash, claro. */
    public static function ponerClave(int $id, string $clave): void
    {
        Bd::ejecutar('UPDATE {persona} SET clave_hash = ? WHERE id = ?', [
            Cripto::hashClave($clave),
            $id,
        ]);
    }

    public static function tieneClave(array $persona): bool
    {
        return (string) ($persona['clave_hash'] ?? '') !== '';
    }

    /**
     * ¿Es esta la contraseña de esta persona?
     *
     * Con hash_equals implícito dentro de password_verify, y sin atajos: si la
     * persona no tiene contraseña puesta, se compara igual contra un hash
     * inventado para que responder tarde lo mismo y no se pueda averiguar quién
     * la tiene y quién no midiendo el tiempo.
     */
    public static function claveValida(array $persona, string $clave): bool
    {
        $hash = (string) ($persona['clave_hash'] ?? '');
        if ($hash === '') {
            Cripto::verificarClave($clave, '$2y$12$' . str_repeat('a', 53));
            return false;
        }
        return Cripto::verificarClave($clave, $hash);
    }

    /**
     * El token del QR de acceso, creándolo si no lo tenía.
     *
     * Son 128 bits en hexadecimal, como los tokens de credencial: quien vea un
     * QR ajeno no puede deducir otro, y adivinarlo no es viable.
     *
     * Es distinto del token de la credencial a propósito. El de la credencial
     * está impreso en la escarapela y se enseña a cualquiera para intercambiar
     * contactos; este identifica a su dueño y abre su sesión. Que fueran el
     * mismo convertiría cada foto de una escarapela en una llave.
     */
    public static function tokenDeAcceso(int $id): string
    {
        $fila = Bd::fila('SELECT acceso_token FROM {persona} WHERE id = ?', [$id]);
        $actual = (string) ($fila['acceso_token'] ?? '');
        if ($actual !== '') {
            return $actual;
        }

        $nuevo = Cripto::token(16);
        Bd::ejecutar('UPDATE {persona} SET acceso_token = ?, acceso_token_en = NOW() WHERE id = ?', [
            $nuevo,
            $id,
        ]);
        return $nuevo;
    }

    /** Cambia el token: invalida el QR anterior. Para cuando se pierde una escarapela. */
    public static function regenerarTokenDeAcceso(int $id): string
    {
        $nuevo = Cripto::token(16);
        Bd::ejecutar('UPDATE {persona} SET acceso_token = ?, acceso_token_en = NOW() WHERE id = ?', [
            $nuevo,
            $id,
        ]);
        return $nuevo;
    }

    public static function porTokenDeAcceso(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Bd::fila('SELECT * FROM {persona} WHERE acceso_token = ?', [$token]);
    }
}
