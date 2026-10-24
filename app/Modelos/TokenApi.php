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

    /** Crea uno y devuelve el token en claro, que no se vuelve a poder leer. */
    public static function crear(string $nombre, string $alcance, ?int $creadoPor): string
    {
        $alcance = isset(self::ALCANCES[$alcance]) ? $alcance : 'basico';
        $nombre = mb_substr(trim($nombre), 0, 120);
        $token = Cripto::token(32);

        $id = Bd::insertar('api_token', [
            'nombre'     => $nombre,
            'token_hash' => hash('sha256', $token),
            'pista'      => substr($token, 0, 8),
            'alcance'    => $alcance,
            'creado_por' => $creadoPor,
        ]);
        Bitacora::registrar('api_token_creado', 'api_token', $id, ['nombre' => $nombre, 'alcance' => $alcance]);

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
