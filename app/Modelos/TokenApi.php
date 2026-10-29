<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;

/**
 * Los tokens de la API de consulta.
 *
 * Uno por cada sistema que consulta —el sitio web, un tablero, otra
 * dependencia—, con su nombre: así se sabe quién usa la API y se le corta a
 * uno sin dejar sin servicio a los demás.
 *
 * El token lleva 256 bits al azar y en la base queda solo su SHA-256. Se ve
 * una sola vez, al crearlo: si se pierde, se crea otro y se revoca el viejo.
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

    /**
     * Crea uno y devuelve el token en claro, que no se vuelve a poder leer.
     * $conFoto: si la API le da además el enlace de la fotografía de cada persona.
     */
    public static function crear(string $nombre, string $alcance, bool $conFoto, ?int $creadoPor): string
    {
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
        // Solo si se pide: así un token sin la foto se crea aunque la base
        // todavía no tenga la columna (esquema 1.13.0).
        if ($conFoto) {
            $datos['foto_desde'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        }
        $id = Bd::insertar('api_token', $datos);
        Bitacora::registrar('api_token_creado', 'api_token', $id,
            ['nombre' => $nombre, 'alcance' => $alcance, 'foto' => $conFoto]);

        return $token;
    }

    /** Todos, los vigentes primero. */
    public static function todos(): array
    {
        return Bd::filas(
            'SELECT t.*, u.nombre AS creado_por_nombre
               FROM {api_token} t
          LEFT JOIN {usuario} u ON u.id = t.creado_por
           ORDER BY (t.revocado_en IS NULL) DESC, t.creado_en DESC, t.id DESC'
        );
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
