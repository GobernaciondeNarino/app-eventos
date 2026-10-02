<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;

/**
 * El registro de ingreso.
 *
 * Una persona, una jornada, un ingreso. Esa regla la impone la llave única de
 * la tabla y no el código: en la puerta de un evento hay reintentos, dobles
 * toques y dos operadores escaneando a la vez, y una comprobación previa en PHP
 * se pierde esas carreras. Aquí se intenta insertar y se interpreta el choque.
 */
final class Asistencia
{
    /**
     * Sella el ingreso.
     *
     * @return array{sellada: bool, repetida: bool, cuando: string}
     */
    /**
     * $operadorTipo dice en qué tabla vive $operadorId: «equipo» es un usuario
     * del backoffice, «staff» es una persona con ese perfil. Sin eso, el
     * usuario 7 y el staff 7 serían el mismo número en la misma columna.
     */
    public static function sellar(
        int $personaId,
        array $jornada,
        string $via,
        ?int $operadorId = null,
        string $operadorTipo = 'equipo'
    ): array {
        $ip = @inet_pton(App::peticion()->ip()) ?: null;

        try {
            Bd::insertar('asistencia', [
                'persona_id'    => $personaId,
                'evento_dia_id' => (int) $jornada['id'],
                'via'           => in_array($via, ['qr_dia', 'carnet_operador', 'manual'], true) ? $via : 'qr_dia',
                'operador_id'   => $operadorId,
                'operador_tipo' => $operadorTipo === 'staff' ? 'staff' : 'equipo',
                'ip'            => $ip,
            ]);

            Bitacora::registrar(
                $via === 'qr_dia' ? 'checkin_propio' : 'asistencia_sellada',
                'persona',
                $personaId,
                ['dia' => $jornada['numero'], 'via' => $via, 'selló' => $operadorTipo]
            );

            return ['sellada' => true, 'repetida' => false, 'cuando' => date('Y-m-d H:i:s')];
        } catch (\PDOException $e) {
            // 23000 con llave duplicada: ya tenía ingreso de esa jornada.
            if ($e->getCode() === '23000') {
                $previa = self::de($personaId, (int) $jornada['id']);
                return [
                    'sellada'  => false,
                    'repetida' => true,
                    'cuando'   => (string) ($previa['registrado_en'] ?? ''),
                ];
            }
            throw $e;
        }
    }

    public static function de(int $personaId, int $jornadaId): ?array
    {
        return Bd::fila(
            'SELECT * FROM {asistencia} WHERE persona_id = ? AND evento_dia_id = ?',
            [$personaId, $jornadaId]
        );
    }

    /** Historial de una persona: una fila por jornada del evento. */
    public static function historial(int $personaId, int $eventoId): array
    {
        return Bd::filas(
            'SELECT d.numero, d.fecha, a.registrado_en, a.via
               FROM {evento_dia} d
          LEFT JOIN {asistencia} a ON a.evento_dia_id = d.id AND a.persona_id = ?
              WHERE d.evento_id = ?
           ORDER BY d.numero',
            [$personaId, $eventoId]
        );
    }

    public static function totalPorJornada(int $eventoId): array
    {
        return Bd::filas(
            'SELECT d.numero, d.fecha, COUNT(a.id) AS ingresos
               FROM {evento_dia} d
          LEFT JOIN {asistencia} a ON a.evento_dia_id = d.id
              WHERE d.evento_id = ?
           GROUP BY d.id, d.numero, d.fecha
           ORDER BY d.numero',
            [$eventoId]
        );
    }

    /** Escaneos que lleva hoy un operador, para su pantalla. */
    public static function escaneosDeHoy(int $operadorId, string $operadorTipo = 'equipo'): int
    {
        return (int) Bd::valor(
            'SELECT COUNT(*) FROM {asistencia}
              WHERE operador_id = ? AND operador_tipo = ? AND DATE(registrado_en) = CURDATE()',
            [$operadorId, $operadorTipo === 'staff' ? 'staff' : 'equipo']
        );
    }
}
