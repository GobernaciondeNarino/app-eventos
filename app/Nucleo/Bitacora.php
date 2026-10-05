<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Auditoría.
 *
 * Solo inserta. No hay método para actualizar ni para borrar una entrada, y el
 * usuario de base de datos de la aplicación tampoco debería tener permiso para
 * hacerlo por fuera. Una bitácora que se puede editar no sirve como bitácora.
 *
 * Lo que se registra: accesos y su resultado, cambios de configuración,
 * aprobaciones, exportaciones, rotaciones de token, acceso a datos sensibles y
 * los rechazos de seguridad. Lo que no: el contenido de los datos personales.
 * La bitácora dice que alguien exportó la caracterización, no qué decía.
 */
final class Bitacora
{
    private static bool $disponible = true;

    public static function registrar(
        string $accion,
        string $entidad = '',
        ?int $entidadId = null,
        array $detalle = []
    ): void {
        if (!self::$disponible || !Config::instalado()) {
            return;
        }

        try {
            $peticion = App::peticion();
            $sesionAdmin = Sesion::actual('admin');

            Bd::insertar('bitacora', [
                'usuario_id' => $sesionAdmin['sujeto_id'] ?? null,
                'accion'     => mb_substr($accion, 0, 80),
                'entidad'    => mb_substr($entidad, 0, 60),
                'entidad_id' => $entidadId,
                'detalle'    => $detalle ? json_encode(self::limpiar($detalle), JSON_UNESCAPED_UNICODE) : null,
                'ip'         => @inet_pton($peticion->ip()) ?: null,
                'creado_en'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Que falle la auditoría no puede tumbar la operación en curso —en
            // plena puerta del evento eso sería peor—, pero sí queda en el
            // registro de errores para que alguien lo mire.
            self::$disponible = false;
            Registro::error('No se pudo escribir en la bitácora: ' . $e->getMessage());
        }
    }

    /**
     * Quita del detalle lo que no debe quedar registrado.
     *
     * Es fácil que alguien pase el arreglo del formulario completo a un
     * registro por comodidad; esto evita que una contraseña o un documento
     * termine guardado para siempre en texto plano.
     */
    private static function limpiar(array $detalle): array
    {
        $prohibidas = ['clave', 'password', 'contrasena', 'documento', 'doc', 'token',
                       'codigo', 'totp', 'secreto', 'llave'];
        $salida = [];
        foreach ($detalle as $clave => $valor) {
            $normal = mb_strtolower((string) $clave);
            $sensible = false;
            foreach ($prohibidas as $p) {
                if (str_contains($normal, $p)) {
                    $sensible = true;
                    break;
                }
            }
            if ($sensible) {
                $salida[$clave] = '[oculto]';
            } elseif (is_scalar($valor) || $valor === null) {
                $salida[$clave] = is_string($valor) ? mb_substr($valor, 0, 200) : $valor;
            } elseif (is_array($valor)) {
                $salida[$clave] = self::limpiar($valor);
            }
        }
        return $salida;
    }

    /** Últimas entradas, para el panel. */
    public static function recientes(int $limite = 20): array
    {
        $limite = max(1, min(200, $limite));
        return Bd::filas(
            "SELECT b.*, u.nombre AS usuario_nombre
               FROM {bitacora} b
          LEFT JOIN {usuario} u ON u.id = b.usuario_id
           ORDER BY b.id DESC
              LIMIT $limite"
        );
    }

    /** Texto legible de cada acción, para no mostrar claves internas en pantalla. */
    public static function describir(array $entrada): string
    {
        $detalle = json_decode((string) ($entrada['detalle'] ?? ''), true) ?: [];
        $quien = $entrada['usuario_nombre'] ?? 'Sistema';

        return match ($entrada['accion']) {
            'acceso_correcto'    => "$quien inició sesión" . (($detalle['via'] ?? '') === 'correo' ? ' con un código del correo' : ''),
            'acceso_fallido'     => 'Intento de acceso fallido',
            'acceso_cerrado'     => "$quien cerró sesión",
            'asistencia_sellada' => "$quien registró un ingreso" . (isset($detalle['dia']) ? ' del día ' . $detalle['dia'] : ''),
            'checkin_propio'     => 'Un asistente registró su ingreso' . (isset($detalle['dia']) ? ' del día ' . $detalle['dia'] : ''),
            'registro'           => 'Nuevo registro',
            'registro_actualizado' => 'Registro actualizado',
            'acceso_creado'      => 'Acceso creado con correo y contraseña',
            // Acciones de antes de la 3.2, para que la bitácora vieja se siga
            // leyendo con palabras y no con identificadores.
            'preregistro'        => 'Nuevo registro',
            'propuesta_decidida' => "$quien resolvió una propuesta de exposición",
            'token_dia_rotado'   => "$quien regeneró el código de un día",
            'identidad_guardada' => "$quien cambió la identidad del evento",
            'exportacion'        => "$quien exportó registros" . (($detalle['sensible'] ?? false) ? ' con caracterización' : ''),
            'contacto_creado'    => 'Intercambio de contacto entre asistentes',
            'usuario_creado'     => "$quien agregó a alguien al equipo",
            'usuario_restablecido' => 'Se restableció el acceso de una cuenta administradora',
            'segundo_factor_retirado' => 'Se retiró el segundo factor de una cuenta',
            'segundo_factor_activado' => "$quien activó la verificación en dos pasos",
            'segundo_factor_restablecido' => "$quien configuró de nuevo su verificación en dos pasos",
            'segundo_factor_correo'   => 'Se envió un código de respaldo al correo de una cuenta',
            'segundo_factor_ilegible' => 'El segundo factor de una cuenta no se pudo leer con la llave actual',
            'segundo_factor_resincronizado' => 'Se compensó la diferencia de hora entre el servidor y un teléfono',
            'esquema_actualizado' => match ($detalle['origen'] ?? '') {
                'automatica' => 'La base de datos se puso al día sola tras la actualización',
                'consola'    => 'Se actualizó la base de datos desde la consola',
                default      => "$quien actualizó la base de datos",
            } . (isset($detalle['a']) ? ' (versión ' . $detalle['a'] . ')' : ''),
            'instalacion_reparacion'  => 'Se reabrió el asistente para reparar la instalación',
            'evento_creado'      => "$quien creó un evento",
            'evento_activado'    => "$quien cambió el evento activo",
            'instalacion'        => 'Se completó la instalación',
            'limite_alcanzado'   => 'Se bloqueó una acción por exceso de intentos',
            'csrf_rechazado'     => 'Se descartó un envío sin testigo válido',
            default              => (string) $entrada['accion'],
        };
    }
}
