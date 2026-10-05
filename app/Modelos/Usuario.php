<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;

/**
 * El equipo organizador: quien entra al backoffice.
 *
 * Distinto de Persona a propósito. Un asistente y un operador tienen ciclos de
 * vida, riesgos y formas de identificarse muy distintos; mezclarlos en una sola
 * tabla obliga a poner banderas por todas partes y termina en que alguien se
 * autentica por el camino equivocado.
 */
final class Usuario
{
    public const ROLES = ['administrador', 'operador', 'consulta'];

    /**
     * Hash de una contraseña que no es de nadie.
     *
     * Sirve para gastar el mismo tiempo cuando el correo no existe, y que la
     * duración de la respuesta no delate qué cuentas están registradas.
     * Generado con bcrypt de coste 12; ninguna contraseña real coincide.
     */
    public const HASH_DESCARTE = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7Nt1zMcbGtVDXTfLXAmBGCnfoDMS6Hy';

    public static function porId(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {usuario} WHERE id = ?', [$id]);
    }

    public static function porCorreo(string $correo): ?array
    {
        return Bd::fila('SELECT * FROM {usuario} WHERE correo = ?', [mb_strtolower(trim($correo))]);
    }

    public static function todos(): array
    {
        return Bd::filas(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM {asistencia} a
                      WHERE a.operador_id = u.id AND DATE(a.registrado_en) = CURDATE()) AS escaneos_hoy
               FROM {usuario} u
           ORDER BY FIELD(u.rol, "administrador", "operador", "consulta"), u.nombre'
        );
    }

    public static function crear(array $datos): int
    {
        $correo = mb_strtolower(trim((string) $datos['correo']));
        if (self::porCorreo($correo)) {
            throw new \DomainException('Ya existe una cuenta con ese correo.');
        }

        $rol = in_array($datos['rol'] ?? '', self::ROLES, true) ? $datos['rol'] : 'operador';

        $id = Bd::insertar('usuario', [
            'nombre'       => mb_substr(trim((string) $datos['nombre']), 0, 160),
            'correo'       => $correo,
            'clave_hash'   => Cripto::hashClave((string) $datos['clave']),
            'rol'          => $rol,
            'puesto'       => mb_substr(trim((string) ($datos['puesto'] ?? '')), 0, 80),
            'estado'       => 'activo',
            // Quien recibe una clave puesta por otra persona debe cambiarla.
            'debe_cambiar' => !empty($datos['debe_cambiar']) ? 1 : 0,
        ]);

        Bitacora::registrar('usuario_creado', 'usuario', $id, ['rol' => $rol]);
        return $id;
    }

    /**
     * Deja lista una cuenta administradora con ese correo, exista o no.
     *
     * La usa el instalador. Tiene que ser repetible: si el paso final falla por
     * cualquier motivo —permisos del archivo de configuración, por ejemplo— hay
     * que poder volver a pulsar «Terminar» sin toparse con «ya existe una cuenta
     * con ese correo» y sin quedar a medias.
     *
     * Recibe el hash y no la contraseña: quien llama ya la convirtió, para no
     * arrastrarla en claro entre pasos.
     */
    public static function asegurarAdministrador(string $correo, string $nombre, string $claveHash): int
    {
        $correo = mb_strtolower(trim($correo));
        $existente = self::porCorreo($correo);

        if ($existente === null) {
            $id = Bd::insertar('usuario', [
                'nombre'       => mb_substr(trim($nombre), 0, 160),
                'correo'       => $correo,
                'clave_hash'   => $claveHash,
                'rol'          => 'administrador',
                'puesto'       => 'Administración del evento',
                'estado'       => 'activo',
                'debe_cambiar' => 0,
            ]);
            Bitacora::registrar('usuario_creado', 'usuario', $id, ['rol' => 'administrador']);
            return $id;
        }

        // Ya existía: se le devuelve el acceso. Quien llega hasta aquí tuvo que
        // dar las credenciales de la base de datos, así que ya podía hacer esto
        // mismo por fuera.
        $id = (int) $existente['id'];
        Bd::ejecutar(
            "UPDATE {usuario}
                SET nombre = ?, clave_hash = ?, rol = 'administrador', estado = 'activo', debe_cambiar = 0
              WHERE id = ?",
            [mb_substr(trim($nombre), 0, 160), $claveHash, $id]
        );
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
        Bitacora::registrar('usuario_restablecido', 'usuario', $id, ['rol' => 'administrador']);
        return $id;
    }

    public static function verificarClave(array $usuario, string $clave): bool
    {
        if (!Cripto::verificarClave($clave, (string) $usuario['clave_hash'])) {
            return false;
        }
        // Si el algoritmo cambió de parámetros, se rehashea al vuelo.
        //
        // La decisión vive en Cripto y no aquí: tener los parámetros de Argon2
        // escritos en dos sitios es cómo se acaba con un hash que se genera con
        // unos valores y se comprueba contra otros, reescribiendo la contraseña
        // en cada acceso sin ninguna necesidad.
        if (Cripto::claveNecesitaRehash((string) $usuario['clave_hash'])) {
            Bd::ejecutar('UPDATE {usuario} SET clave_hash = ? WHERE id = ?', [
                Cripto::hashClave($clave),
                $usuario['id'],
            ]);
        }
        return true;
    }

    public static function cambiarClave(int $id, string $clave): void
    {
        Bd::ejecutar('UPDATE {usuario} SET clave_hash = ?, debe_cambiar = 0 WHERE id = ?', [
            Cripto::hashClave($clave),
            $id,
        ]);
        // Cambiar la contraseña cierra las demás sesiones: es lo que se espera
        // cuando alguien la cambia justamente porque cree que se la robaron.
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
    }

    public static function registrarAcceso(int $id): void
    {
        Bd::ejecutar('UPDATE {usuario} SET ultimo_acceso = NOW() WHERE id = ?', [$id]);
    }

    public static function cambiarEstado(int $id, string $estado): void
    {
        $estado = $estado === 'suspendido' ? 'suspendido' : 'activo';
        Bd::ejecutar('UPDATE {usuario} SET estado = ? WHERE id = ?', [$estado, $id]);
        if ($estado === 'suspendido') {
            \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
        }
        Bitacora::registrar('usuario_estado', 'usuario', $id, ['estado' => $estado]);
    }

    /**
     * Cambia el rol de una cuenta del equipo.
     *
     * Se cierran sus sesiones abiertas a propósito. El rol se comprueba en cada
     * petición contra la fila de la base, así que no haría falta por seguridad;
     * pero quien acaba de perder el rol de administrador se quedaba con el menú
     * completo en pantalla y recibía un 403 en cada clic, sin entender por qué.
     * Volver a entrar es más claro que eso.
     */
    public static function cambiarRol(int $id, string $rol): void
    {
        if (!in_array($rol, self::ROLES, true)) {
            throw new \DomainException('Ese rol no existe.');
        }

        $anterior = (string) (Bd::valor('SELECT rol FROM {usuario} WHERE id = ?', [$id]) ?? '');
        if ($anterior === $rol) {
            return;
        }

        Bd::ejecutar('UPDATE {usuario} SET rol = ? WHERE id = ?', [$rol, $id]);
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);

        Bitacora::registrar('usuario_rol', 'usuario', $id, [
            'antes'   => $anterior,
            'despues' => $rol,
        ]);
    }

    /** Cuántos administradores hay activos. Sin ninguno, nadie puede configurar nada. */
    public static function administradoresActivos(): int
    {
        return (int) Bd::valor(
            "SELECT COUNT(*) FROM {usuario} WHERE rol = 'administrador' AND estado = 'activo'"
        );
    }

    /* =====================================================================
       Segundo factor
       ===================================================================== */

    /**
     * Guarda un secreto nuevo, todavía sin confirmar.
     *
     * Empieza de cero el último código aceptado: era del secreto anterior. El
     * desfase aprendido se conserva, porque es del reloj del servidor y no del
     * secreto.
     */
    public static function guardarSecretoTotp(int $id, string $secreto): void
    {
        $columnas = ['totp_secreto = ?', 'totp_confirmado = 0'];
        $valores = [Cripto::cifrar($secreto)];
        if (self::tieneColumna('totp_ultimo')) {
            $columnas[] = 'totp_ultimo = 0';
        }
        $valores[] = $id;
        Bd::ejecutar('UPDATE {usuario} SET ' . implode(', ', $columnas) . ' WHERE id = ?', $valores);
    }

    /**
     * Deja activo un secreto que se acaba de confirmar con un código.
     *
     * Ese código queda consumido —no sirve para entrar después— y el desfase
     * con que cuadró queda aprendido.
     */
    public static function activarSecretoTotp(int $id, string $secreto, int $intervalo, int $deriva): void
    {
        $columnas = ['totp_secreto = ?', 'totp_confirmado = 1'];
        $valores = [Cripto::cifrar($secreto)];
        if (self::tieneColumna('totp_ultimo')) {
            $columnas[] = 'totp_ultimo = ?';
            $valores[] = $intervalo;
        }
        if (self::tieneColumna('totp_deriva')) {
            $columnas[] = 'totp_deriva = ?';
            $valores[] = $deriva;
        }
        $valores[] = $id;
        Bd::ejecutar('UPDATE {usuario} SET ' . implode(', ', $columnas) . ' WHERE id = ?', $valores);
    }

    /** Quita el segundo factor. La cuenta tendrá que volver a configurarlo. */
    public static function quitarSegundoFactor(int $id): void
    {
        $columnas = ['totp_secreto = NULL', 'totp_confirmado = 0'];
        if (self::tieneColumna('totp_ultimo')) {
            $columnas[] = 'totp_ultimo = 0';
        }
        Bd::ejecutar('UPDATE {usuario} SET ' . implode(', ', $columnas) . ' WHERE id = ?', [$id]);
    }

    public static function secretoTotp(array $usuario): string
    {
        if (empty($usuario['totp_secreto'])) {
            return '';
        }
        try {
            return Cripto::descifrar((string) $usuario['totp_secreto']);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function confirmarTotp(int $id): void
    {
        Bd::ejecutar('UPDATE {usuario} SET totp_confirmado = 1 WHERE id = ?', [$id]);
    }

    /**
     * En qué está el segundo factor de la cuenta.
     *
     *   'ninguno'    no lo ha configurado
     *   'pendiente'  empezó el alta y no la confirmó
     *   'activo'     configurado y legible
     *   'ilegible'   configurado, pero el secreto no se puede descifrar
     *
     * El último caso es el que había detrás del «error de reloj» después de
     * algunas actualizaciones. Si config/config.php se pierde y se vuelve a
     * pasar por el asistente, la llave de cifrado es otra, el secreto guardado
     * ya no se puede leer, y todos los códigos se rechazaban con un mensaje que
     * mandaba a revisar la hora del teléfono. Ahora se reconoce y se dice.
     */
    public static function estadoSegundoFactor(array $usuario): string
    {
        if (empty($usuario['totp_secreto'])) {
            return 'ninguno';
        }
        if ((int) $usuario['totp_confirmado'] !== 1) {
            return 'pendiente';
        }
        return self::secretoTotp($usuario) === '' ? 'ilegible' : 'activo';
    }

    /**
     * Evalúa el código del segundo factor y, si sirve, lo consume.
     *
     * Un código vale hasta noventa segundos con la tolerancia de reloj. Sin
     * consumirlo, quien lo vea por encima del hombro puede usarlo otra vez
     * dentro de esa ventana. Se guarda el intervalo aceptado y no se admite
     * ninguno anterior ni el mismo (RFC 6238 §5.2). La escritura es la que
     * decide: si llegan dos envíos del mismo código a la vez, solo uno la
     * consigue.
     *
     * Funciona también con una base que todavía no tiene las columnas del
     * último código o del desfase: entra sin guardarlos. Antes, con la base de
     * una versión anterior a la 1.1.0, el código correcto respondía un error
     * 500, y el administrador se quedaba fuera del panel desde el que se
     * actualiza la base.
     *
     * @param array{intervalo: int, deriva: int, hasta: int}|null $pendiente
     * @return array{estado: string, intervalo?: int, deriva?: int}
     *         'ok', 'repetido', 'confirmar', 'no' o 'ilegible'
     */
    public static function evaluarTotp(array $usuario, string $codigo, ?array $pendiente = null): array
    {
        $secreto = self::secretoTotp($usuario);
        if ($secreto === '') {
            return ['estado' => 'ilegible'];
        }

        $conUltimo = array_key_exists('totp_ultimo', $usuario);
        $conDeriva = array_key_exists('totp_deriva', $usuario);

        $resultado = \App\Nucleo\Totp::evaluar(
            $secreto,
            $codigo,
            $conDeriva ? (int) $usuario['totp_deriva'] : 0,
            $conUltimo ? (int) $usuario['totp_ultimo'] : 0,
            $pendiente
        );
        if ($resultado['estado'] !== 'ok' || !$conUltimo) {
            return $resultado;
        }

        $columnas = ['totp_ultimo = ?'];
        $valores = [$resultado['intervalo']];
        if ($conDeriva) {
            $columnas[] = 'totp_deriva = ?';
            $valores[] = $resultado['deriva'];
        }
        if (!empty($resultado['retroceso'])) {
            // El teléfono se puso en hora y el intervalo queda por debajo del
            // último: se guarda igual, pero solo si nadie lo cambió desde que
            // se leyó la cuenta.
            array_push($valores, $usuario['id'], (int) $usuario['totp_ultimo']);
            $donde = 'id = ? AND totp_ultimo = ?';
        } else {
            // El último guardado se respeta salvo que sea imposible —más allá
            // de lo que la búsqueda alcanza—, el mismo criterio de Totp::evaluar().
            $imposible = intdiv(time(), 30) + \App\Nucleo\Totp::BUSQUEDA;
            array_push($valores, $usuario['id'], $resultado['intervalo'], $imposible);
            $donde = 'id = ? AND (totp_ultimo < ? OR totp_ultimo > ?)';
        }

        $cambiadas = Bd::ejecutar(
            'UPDATE {usuario} SET ' . implode(', ', $columnas) . ' WHERE ' . $donde,
            $valores
        )->rowCount();

        return $cambiadas === 1 ? $resultado : ['estado' => 'repetido'];
    }

    /** Compatibilidad: true si el código sirve y quedó consumido. */
    public static function consumirTotp(array $usuario, string $codigo): bool
    {
        return self::evaluarTotp($usuario, $codigo)['estado'] === 'ok';
    }

    /** ¿Tiene la tabla esta columna? Para no romper con una base sin actualizar. */
    private static function tieneColumna(string $columna): bool
    {
        static $conocidas = [];
        return $conocidas[$columna] ??= Bd::existeColumna('usuario', $columna);
    }

    /**
     * ¿Esta cuenta necesita segundo factor?
     *
     * Obligatorio para administradores: son quienes pueden exportar datos
     * personales y cambiar la configuración. Para operador y consulta es
     * opcional, porque son cuentas que se usan a la carrera en la puerta y en
     * teléfonos prestados, donde exigir una aplicación de códigos frena más de
     * lo que protege.
     */
    public static function exigeSegundoFactor(array $usuario): bool
    {
        return $usuario['rol'] === 'administrador';
    }

    public static function tieneSegundoFactor(array $usuario): bool
    {
        return !empty($usuario['totp_secreto']) && (int) $usuario['totp_confirmado'] === 1;
    }
}
