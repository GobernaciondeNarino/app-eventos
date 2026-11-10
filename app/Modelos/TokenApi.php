<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;
use App\Nucleo\Registro;

/**
 * Los tokens de la API de consulta.
 *
 * Uno por cada sistema que consulta —el sitio web, un tablero, otra
 * dependencia—, con su nombre: así se sabe quién usa la API y se le corta a
 * uno sin dejar sin servicio a los demás.
 *
 * El token lleva 256 bits al azar y se busca por su SHA-256. Desde la 1.15.0
 * se guarda además cifrado con la llave de la instalación, para que un
 * administrador lo vuelva a ver y copiar (paraCopiar()). De los creados antes
 * solo quedó el hash: si se pierde uno de esos, se crea otro y se elimina el
 * viejo.
 */
final class TokenApi
{
    public const ALCANCES = [
        'basico'   => 'Sin datos de contacto',
        'contacto' => 'Con correo y teléfono',
    ];

    /** Consultas permitidas por token en cada ventana de diez minutos. */
    public const TOPE = 600;
    public const VENTANA_MINUTOS = 10;

    /** @var array<string, bool> Las columnas de la 1.15.0 que ya tiene la base. */
    private static array $columnas = [];

    /**
     * Crea uno y devuelve el token en claro.
     * $conFoto: si la API le da además el enlace de la fotografía de cada persona.
     * $conCaracterizacion: si le da la caracterización de cada persona.
     */
    public static function crear(
        string $nombre,
        string $alcance,
        bool $conFoto,
        ?int $creadoPor,
        bool $conCaracterizacion = false,
        ?int $eventoId = null
    ): string {
        $alcance = isset(self::ALCANCES[$alcance]) ? $alcance : 'basico';
        $nombre = mb_substr(trim($nombre), 0, 120);
        $token = Cripto::token(32);

        $datos = [
            'nombre'     => $nombre,
            'token_hash' => hash('sha256', $token),
            'pista'      => substr($token, 0, 8),
            'alcance'    => $alcance,
            'creado_por' => $creadoPor,
        ];
        // Los permisos, solo si se piden: así un token sin ellos se crea
        // aunque la base todavía no tenga sus columnas (esquemas 1.13.0 y
        // 1.15.0). Quien los pide sin la columna ya fue al panel.
        if ($conFoto) {
            $datos['foto_desde'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        }
        if ($conCaracterizacion) {
            $datos['caracterizacion_desde'] = date('Y-m-d H:i:s');
        }
        $cifrado = self::cifrar($token);
        if ($cifrado !== null) {
            $datos['token_cifrado'] = $cifrado;
        }
        // De qué evento son los datos que entrega (1.17.0).
        if ($eventoId !== null && self::tieneColumna('evento_id')) {
            $datos['evento_id'] = $eventoId;
        }
        $id = Bd::insertar('api_token', $datos);
        Bitacora::registrar('api_token_creado', 'api_token', $id, ['nombre' => $nombre, 'alcance' => $alcance,
            'foto' => $conFoto] + ($conCaracterizacion ? ['caracterizacion' => true] : []));

        return $token;
    }

    /**
     * El token cifrado para guardarlo, o null si la base todavía no tiene
     * dónde. Si cifrar falla —una llave dañada—, el token se crea igual y sin
     * copia, que es como eran todos hasta la 1.15.0: quedarse sin poder crear
     * tokens sería peor. Se anota sin la traza, que llevaría el token.
     */
    private static function cifrar(string $token): ?string
    {
        if (!self::tieneColumna('token_cifrado')) {
            return null;
        }
        try {
            return Cripto::cifrar($token);
        } catch (\Throwable $e) {
            Registro::error('No se pudo cifrar un token de la API para volver a copiarlo: ' . $e->getMessage());
            return null;
        }
    }

    /** ¿La base ya tiene esa columna de la 1.15.0? Se pregunta una vez por petición. */
    public static function tieneColumna(string $columna): bool
    {
        return self::$columnas[$columna] ??= Bd::existeColumna('api_token', $columna);
    }

    /**
     * Todos, los vigentes primero.
     *
     * Sin el token cifrado: a la pantalla le basta saber si se puede volver a
     * ver («copiable»), y así no viaja a la vista en ninguna forma.
     */
    public static function todos(?int $eventoId = null): array
    {
        // Los de un evento (1.17.0); sin la columna todavía, todos, que eran
        // del único evento que había.
        $deEvento = $eventoId !== null && self::tieneColumna('evento_id');
        return array_map(static function (array $t): array {
            $t['copiable'] = ($t['token_cifrado'] ?? null) !== null && $t['token_cifrado'] !== '';
            unset($t['token_cifrado']);
            return $t;
        }, Bd::filas(
            'SELECT t.*, u.nombre AS creado_por_nombre
               FROM {api_token} t
          LEFT JOIN {usuario} u ON u.id = t.creado_por
              ' . ($deEvento ? 'WHERE t.evento_id = ?' : '') . '
           ORDER BY (t.revocado_en IS NULL) DESC, t.creado_en DESC, t.id DESC',
            $deEvento ? [$eventoId] : []
        ));
    }

    /**
     * ¿Ese token es de ese evento? Cada acción sobre un token —verlo,
     * revocarlo, darle la foto— lo pregunta antes: el número es correlativo,
     * y el equipo de un evento no tiene nada que hacer con los de otro.
     */
    public static function esDelEvento(int $id, int $eventoId): bool
    {
        if (!self::tieneColumna('evento_id')) {
            return true;
        }
        return (int) Bd::valor('SELECT COUNT(*) FROM {api_token} WHERE id = ? AND evento_id = ?', [$id, $eventoId]) === 1;
    }

    /**
     * Un token vigente, en claro, para volver a copiarlo, y queda en la
     * bitácora que alguien lo vio.
     *
     * 'estado' dice qué pasó: 'ok'; 'no_existe' si no está o ya se revocó —un
     * token revocado no le sirve a nadie—; 'sin_copia' si se creó antes de la
     * 1.15.0 y solo quedó su hash; 'ilegible' si la llave de la instalación ya
     * no lo abre o lo abierto no es el token de esa fila.
     *
     * @return array{estado: string, token: string, fila: ?array}
     */
    public static function paraCopiar(int $id): array
    {
        $fila = Bd::fila('SELECT * FROM {api_token} WHERE id = ? AND revocado_en IS NULL', [$id]);
        if ($fila === null) {
            return ['estado' => 'no_existe', 'token' => '', 'fila' => null];
        }
        $cifrado = (string) ($fila['token_cifrado'] ?? '');
        unset($fila['token_cifrado']);
        if ($cifrado === '') {
            return ['estado' => 'sin_copia', 'token' => '', 'fila' => $fila];
        }

        try {
            $token = Cripto::descifrar($cifrado);
        } catch (\Throwable $e) {
            Registro::error('No se pudo descifrar el token de la API ' . $id . ': ' . $e->getMessage());
            $token = '';
        }
        // Lo descifrado tiene que ser el token de esta fila: si alguien
        // cambiara el valor cifrado en la base, no se muestra otra cosa.
        if ($token === '' || !hash_equals((string) $fila['token_hash'], hash('sha256', $token))) {
            return ['estado' => 'ilegible', 'token' => '', 'fila' => $fila];
        }

        Bitacora::registrar('api_token_mostrado', 'api_token', $id, ['nombre' => (string) $fila['nombre']]);
        return ['estado' => 'ok', 'token' => $token, 'fila' => $fila];
    }

    /**
     * Lo quita de la lista. Si estaba vigente, la API lo rechaza desde la
     * consulta siguiente, igual que al revocarlo, y sus enlaces de fotografía
     * dejan de servir. En la bitácora quedan su nombre y si estaba vigente: el
     * historial de quién lo creó y lo usó no se pierde con la fila.
     *
     * Devuelve lo que se eliminó —nombre y si estaba vigente—, o null si ya
     * no estaba.
     *
     * @return array{nombre: string, vigente: bool}|null
     */
    public static function eliminar(int $id): ?array
    {
        $fila = Bd::fila('SELECT nombre, revocado_en FROM {api_token} WHERE id = ?', [$id]);
        if ($fila === null || Bd::ejecutar('DELETE FROM {api_token} WHERE id = ?', [$id])->rowCount() !== 1) {
            return null;
        }
        $eliminado = ['nombre' => (string) $fila['nombre'], 'vigente' => $fila['revocado_en'] === null];
        Bitacora::registrar('api_token_eliminado', 'api_token', $id, $eliminado);
        return $eliminado;
    }

    /** La huella con que se busca un token, o null si no tiene forma de token. */
    public static function huella(string $token): ?string
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : null;
    }

    /**
     * El token vigente con esa huella, o null.
     *
     * Recibe la huella y no el token: si la consulta falla, la traza del error
     * lleva los argumentos de cada llamada, y el token quedaría escrito en el
     * registro de errores.
     */
    public static function vigente(string $huella): ?array
    {
        return Bd::fila('SELECT * FROM {api_token} WHERE token_hash = ? AND revocado_en IS NULL', [$huella]);
    }

    public static function revocar(int $id): bool
    {
        $hecho = Bd::ejecutar(
            'UPDATE {api_token} SET revocado_en = NOW() WHERE id = ? AND revocado_en IS NULL',
            [$id]
        )->rowCount() === 1;
        if ($hecho) {
            $nombre = (string) Bd::valor('SELECT nombre FROM {api_token} WHERE id = ?', [$id]);
            Bitacora::registrar('api_token_revocado', 'api_token', $id, ['nombre' => $nombre]);
        }
        return $hecho;
    }

    /**
     * Le da o le quita a un token vigente el enlace de la fotografía. El
     * sistema que lo usa no tiene que cambiar de token: desde la consulta
     * siguiente, los enlaces aparecen o desaparecen. Al quitarlo, los que ya
     * entregó dejan de servir para siempre: si se le vuelve a dar, recibe
     * enlaces nuevos, porque la firma lleva la fecha del permiso.
     */
    public static function permitirFoto(int $id, bool $permitir): bool
    {
        $hecho = Bd::ejecutar($permitir
            ? 'UPDATE {api_token} SET foto_desde = NOW(6) WHERE id = ? AND revocado_en IS NULL AND foto_desde IS NULL'
            : 'UPDATE {api_token} SET foto_desde = NULL WHERE id = ? AND revocado_en IS NULL AND foto_desde IS NOT NULL',
            [$id]
        )->rowCount() === 1;
        if ($hecho) {
            $nombre = (string) Bd::valor('SELECT nombre FROM {api_token} WHERE id = ?', [$id]);
            Bitacora::registrar('api_token_foto', 'api_token', $id, ['nombre' => $nombre, 'permitida' => $permitir]);
        }
        return $hecho;
    }

    /** Si la API le da a ese token el enlace de la fotografía. */
    public static function daFoto(array $token): bool
    {
        return ($token['foto_desde'] ?? null) !== null;
    }

    /**
     * Le da o le quita a un token vigente la caracterización de cada persona,
     * sin cambiar el token: desde la consulta siguiente aparece o desaparece.
     */
    public static function permitirCaracterizacion(int $id, bool $permitir): bool
    {
        $hecho = Bd::ejecutar($permitir
            ? 'UPDATE {api_token} SET caracterizacion_desde = NOW() WHERE id = ? AND revocado_en IS NULL AND caracterizacion_desde IS NULL'
            : 'UPDATE {api_token} SET caracterizacion_desde = NULL WHERE id = ? AND revocado_en IS NULL AND caracterizacion_desde IS NOT NULL',
            [$id]
        )->rowCount() === 1;
        if ($hecho) {
            $nombre = (string) Bd::valor('SELECT nombre FROM {api_token} WHERE id = ?', [$id]);
            Bitacora::registrar('api_token_caracterizacion', 'api_token', $id, ['nombre' => $nombre, 'permitida' => $permitir]);
        }
        return $hecho;
    }

    /** Si la API le da a ese token la caracterización. */
    public static function daCaracterizacion(array $token): bool
    {
        return ($token['caracterizacion_desde'] ?? null) !== null;
    }

    /**
     * Deja en la bitácora que la API entregó la caracterización con ese token.
     *
     * Una vez al día por token, no en cada consulta: un tablero que pregunta
     * cada minuto llenaría la bitácora —que es de solo inserción— y el resumen
     * del panel con la misma línea. Así queda qué días la recibió cada sistema;
     * cuántas consultas y la última, ya están en la fila del token.
     */
    public static function anotarCaracterizacion(array $token): void
    {
        $ya = Bd::valor(
            "SELECT 1 FROM {bitacora}
              WHERE accion = 'api_caracterizacion' AND entidad = 'api_token' AND entidad_id = ? AND creado_en >= ?
              LIMIT 1",
            [(int) $token['id'], date('Y-m-d') . ' 00:00:00']
        );
        if ($ya === null) {
            Bitacora::registrar('api_caracterizacion', 'api_token', (int) $token['id'], ['nombre' => (string) $token['nombre']]);
        }
    }

    /** El token por su número, si está vigente y tiene el permiso de la fotografía. */
    public static function vigenteConFoto(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {api_token} WHERE id = ? AND revocado_en IS NULL AND foto_desde IS NOT NULL', [$id]);
    }

    /**
     * La firma del enlace a la fotografía de una persona, para un token.
     *
     * Un HMAC con la llave de la instalación —no se adivina—, con su propio
     * prefijo para que no sirva para nada más. Lleva el número del token, la
     * fecha desde la que tiene el permiso y el archivo de la foto: el enlace
     * deja de servir si se revoca el token, si se le quita el permiso o si la
     * persona cambia de foto.
     */
    public static function firmaFoto(array $token, int $personaId, string $archivo): string
    {
        return substr(Cripto::huella('api_foto|' . (int) $token['id'] . '|' . (string) $token['foto_desde']
            . '|' . $personaId . '|' . $archivo), 0, 32);
    }

    /**
     * Anota una consulta y dice cuántas lleva en la ventana actual.
     *
     * Es una sola sentencia: la ventana se reinicia si ya pasaron los diez
     * minutos, y si no, se suma una. Dos consultas a la vez no se pisan la
     * cuenta.
     */
    public static function anotarUso(int $id, string $ip): int
    {
        $minutos = self::VENTANA_MINUTOS;
        Bd::ejecutar(
            "UPDATE {api_token}
                SET usos = usos + 1,
                    ultimo_uso_en = NOW(),
                    ultima_ip = ?,
                    ventana_usos = IF(ventana_inicio > DATE_SUB(NOW(), INTERVAL $minutos MINUTE), ventana_usos + 1, 1),
                    ventana_inicio = IF(ventana_inicio > DATE_SUB(NOW(), INTERVAL $minutos MINUTE), ventana_inicio, NOW())
              WHERE id = ?",
            [@inet_pton($ip) ?: null, $id]
        );
        return (int) Bd::valor('SELECT ventana_usos FROM {api_token} WHERE id = ?', [$id]);
    }
}
