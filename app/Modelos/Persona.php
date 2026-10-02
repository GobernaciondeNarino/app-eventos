<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;
use App\Nucleo\Imagen;

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
    /** Todos los perfiles de asistencia que existen. */
    public const ROLES = ['participante', 'visitante', 'expositor', 'organizador', 'prensa', 'staff'];

    /**
     * Los que puede elegir quien llena el formulario.
     *
     * «staff» queda fuera, y no es un detalle de presentación: ese perfil da
     * acceso a la plataforma —ver los carnets de todo el mundo, con su cédula, y
     * sellar ingresos—, así que ofrecerlo en un formulario abierto al público
     * sería dejar que cualquiera se lo asignara. Esconder la opción no basta:
     * un envío hecho a mano no pasa por la pantalla. Por eso la lista está aquí
     * y es contra esta contra la que valida el registro público.
     *
     * Lo asigna un administrador desde la ficha de la persona.
     */
    public const ROLES_PUBLICOS = ['participante', 'visitante', 'expositor', 'prensa'];

    /** ¿Este perfil da acceso a acreditar y a ver los carnets? */
    public static function esStaff(?array $persona): bool
    {
        return $persona !== null && (string) ($persona['rol'] ?? '') === 'staff';
    }

    /**
     * El perfil que de verdad se guarda desde el formulario público.
     *
     * Dos reglas, y la segunda es la que no se ve venir:
     *
     * 1. No se puede subir. El enviado vale solo si está en ROLES_PUBLICOS.
     * 2. Tampoco se puede perder. Un perfil que solo pone un administrador
     *    —«staff», «organizador»— se conserva pase lo que pase en el envío. Si
     *    no, a alguien del staff le bastaba con abrir «mis datos» y guardar para
     *    quedarse sin su perfil: el selector del formulario no tiene su opción,
     *    así que el navegador manda la primera de la lista.
     */
    public static function rolAdmitido(string $enviado, string $actual): string
    {
        $esDeAdmin = in_array($actual, self::ROLES, true)
            && !in_array($actual, self::ROLES_PUBLICOS, true);
        if ($esDeAdmin) {
            return $actual;
        }
        if (in_array($enviado, self::ROLES_PUBLICOS, true)) {
            return $enviado;
        }
        return in_array($actual, self::ROLES, true) ? $actual : 'participante';
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
    public static function registroCompleto(array $persona): bool
    {
        return trim((string) ($persona['nombre'] ?? '')) !== ''
            && ($persona['documento_huella'] ?? null) !== null;
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
     */
    public static function registrar(int $eventoId, array $datos): array
    {
        $correo = mb_strtolower(trim((string) $datos['correo']));
        $documento = self::normalizarDocumento((string) ($datos['documento'] ?? ''));
        $huella = $documento === '' ? null : Cripto::huella($documento);

        return Bd::transaccion(static function () use ($eventoId, $datos, $correo, $documento, $huella): array {
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
                'tipo_documento'    => in_array($datos['tipo_documento'] ?? 'CC', ['CC', 'CE', 'TI', 'PP'], true)
                                        ? $datos['tipo_documento'] : 'CC',
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
                    $existente ? (string) $existente['rol'] : 'participante'
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
            $credencial = self::registroCompleto($campos) ? Credencial::asegurar($id) : null;

            Bitacora::registrar($nueva ? 'registro' : 'registro_actualizado', 'persona', $id, [
                'rol' => $campos['rol'],
                'municipio' => $campos['municipio'],
            ]);

            return ['id' => $id, 'nueva' => $nueva, 'credencial' => $credencial];
        });
    }

    private static function guardarCaracterizacion(int $personaId, array $datos): void
    {
        $campos = [
            'genero'       => mb_substr((string) ($datos['genero'] ?? ''), 0, 20),
            'rango_edad'   => mb_substr((string) ($datos['rango_edad'] ?? ''), 0, 12),
            'etnia'        => mb_substr((string) ($datos['etnia'] ?? ''), 0, 40),
            'discapacidad' => mb_substr((string) ($datos['discapacidad'] ?? ''), 0, 40),
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
     * Listado del backoffice, con filtros.
     *
     * Los filtros se arman con parámetros; lo único que se interpola es el
     * nombre de la columna de orden, y sale de una lista blanca.
     */
    public static function buscar(int $eventoId, array $filtros = []): array
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
        if (!empty($filtros['rol']) && in_array($filtros['rol'], self::ROLES, true)) {
            $donde[] = 'p.rol = :rol';
            $parametros['rol'] = $filtros['rol'];
        }
        if (!empty($filtros['dia'])) {
            $donde[] = 'EXISTS (SELECT 1 FROM {asistencia} a
                                  JOIN {evento_dia} d ON d.id = a.evento_dia_id
                                 WHERE a.persona_id = p.id AND d.numero = :dia)';
            $parametros['dia'] = (int) $filtros['dia'];
        }

        $limite = max(1, min(500, (int) ($filtros['limite'] ?? 200)));

        return Bd::filas(
            'SELECT p.*,
                    (SELECT GROUP_CONCAT(d.numero ORDER BY d.numero)
                       FROM {asistencia} a
                       JOIN {evento_dia} d ON d.id = a.evento_dia_id
                      WHERE a.persona_id = p.id) AS dias
               FROM {persona} p
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY p.nombre
              LIMIT ' . $limite,
            $parametros
        );
    }

    /**
     * Las personas que pueden tener carnet, para listarlo e imprimirlo.
     *
     * Deja fuera los registros a medias —quien creó su acceso con correo y
     * contraseña y no llenó el formulario—: un carnet sin nombre ni documento
     * es una cartulina en blanco, y en una tanda de doscientas se cuela sin que
     * nadie la vea hasta que la reparte.
     */
    public static function conCredencial(int $eventoId, array $filtros = []): array
    {
        $personas = self::buscar($eventoId, $filtros);
        return array_values(array_filter($personas, static fn(array $p): bool => self::registroCompleto($p)));
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
