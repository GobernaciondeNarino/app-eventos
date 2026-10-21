<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Cripto;
use App\Nucleo\Registro;
use App\Nucleo\Url;

/**
 * Enlaces para poner una contraseña nueva.
 *
 * Los usan las dos puertas: el asistente que olvidó la suya y la cuenta del
 * equipo que olvidó la del panel. Los pide la propia persona desde la pantalla
 * de acceso, o se los envía un administrador desde el panel.
 *
 * Cómo está hecho, y por qué:
 *
 *  · El enlace lleva 256 bits al azar y en la base queda solo su hash: quien
 *    copie la tabla no obtiene enlaces que sirvan.
 *  · Sirve una sola vez y vence: una hora si lo pidió la persona, un día si
 *    salió del panel, porque quien lo recibe así puede no estar mirando el
 *    correo en ese momento.
 *  · Pedir uno nuevo deja sin efecto los anteriores.
 *  · Va atado al buzón al que se envió. Si la cuenta cambia de correo, el
 *    enlace que fue a la dirección vieja deja de servir.
 *  · Abrirlo no gasta nada. Los antivirus del correo institucional abren los
 *    enlaces para revisarlos antes que la persona; si abrir bastara, el
 *    enlace llegaría gastado.
 *
 * Para el equipo hay una regla más, en Acceso::verificarSegundoFactor(): el
 * enlace cambia la contraseña pero nunca salta la verificación en dos pasos,
 * y durante un día después el código de respaldo por correo se apaga para esa
 * cuenta. Si no, el buzón solo bastaría para entrar al panel: con él se pide
 * el enlace y con él se recibe el código.
 */
final class Recuperacion
{
    public const TIPOS = ['asistente', 'admin'];

    /** Lo que dura un enlace pedido por la propia persona. */
    public const SEGUNDOS_PROPIO = 3600;

    /** Lo que dura uno enviado desde el panel. */
    public const SEGUNDOS_PANEL = 86400;

    /** Tras recuperar la contraseña del panel, cuánto queda apagado el código de respaldo por correo. */
    public const HORAS_SIN_RESPALDO = 24;

    /**
     * Crea un enlace y lo envía al correo de la cuenta.
     *
     * $cuenta es la fila de la persona o del usuario. Devuelve true si el
     * correo salió —en el modo «solo registrar» también—, false si no.
     * Quien pide desde la pantalla de acceso no ve nunca la diferencia: decir
     * «no se pudo enviar» le diría que ese correo tiene cuenta.
     */
    public static function enviar(string $tipo, array $cuenta, ?int $enviadoPor = null): bool
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de cuenta desconocido: ' . $tipo);
        }

        $desdeElPanel = $enviadoPor !== null;
        $correo = mb_strtolower(trim((string) $cuenta['correo']));

        // El enlace se arma con la dirección de config/config.php. Sin ella
        // saldría de la cabecera Host de la petición, que la pone quien pide:
        // alguien podría pedir el enlace de otra persona con un Host suyo, y el
        // correo llegaría con un enlace a su servidor. Desde el panel no pasa,
        // porque la petición es del propio administrador.
        if (trim((string) Config::obtener('url_base', '')) === '' && !$desdeElPanel) {
            Registro::error('No se envió un enlace para recuperar la contraseña: falta url_base en '
                . 'config/config.php, y sin ella el enlace dependería de lo que diga la petición.');
            return false;
        }

        $token = self::crear($tipo, (int) $cuenta['id'], $correo, $enviadoPor,
            $desdeElPanel ? self::SEGUNDOS_PANEL : self::SEGUNDOS_PROPIO);

        $evento = App::eventoActivo();
        $enviado = Correo::enlaceParaClave(
            $correo,
            (string) ($cuenta['nombre'] ?? ''),
            (string) ($evento['nombre'] ?? 'Eventos TIC'),
            Url::absoluta(($tipo === 'admin' ? '/admin/recuperar/' : '/entrar/recuperar/') . $token),
            $desdeElPanel ? '24 horas' : '60 minutos',
            $desdeElPanel,
            $tipo === 'admin',
            $tipo === 'admin' && Usuario::tieneSegundoFactor($cuenta)
        );

        if (!$enviado) {
            Registro::error('No se pudo enviar el enlace para recuperar la contraseña', [
                'tipo'      => $tipo,
                'sujeto_id' => (int) $cuenta['id'],
                'detalle'   => Correo::ultimoError(),
            ]);
        }
        return $enviado;
    }

    /** Guarda un enlace nuevo, anula los anteriores y devuelve el token en claro. */
    private static function crear(string $tipo, int $sujetoId, string $correo, ?int $enviadoPor, int $segundos): string
    {
        $token = Cripto::token(32);

        self::anularDe($tipo, $sujetoId);
        Bd::insertar('recuperacion_clave', [
            'tipo'          => $tipo,
            'sujeto_id'     => $sujetoId,
            'token_hash'    => hash('sha256', $token),
            'correo_huella' => self::huellaCorreo($correo),
            'enviado_por'   => $enviadoPor,
            'expira_en'     => date('Y-m-d H:i:s', time() + $segundos),
        ]);

        return $token;
    }

    /**
     * El enlace de ese token, esté como esté, con su estado:
     * 'vigente', 'usado' o 'vencido'. Null si no existe.
     *
     * Se devuelve aunque no sirva para poder decirle a la persona qué pasó:
     * «ya se usó» y «venció» piden cosas distintas.
     */
    public static function buscar(string $tipo, string $token): ?array
    {
        if (!in_array($tipo, self::TIPOS, true) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $fila = Bd::fila(
            'SELECT r.*, (r.expira_en > NOW()) AS en_plazo
               FROM {recuperacion_clave} r
              WHERE r.token_hash = ? AND r.tipo = ?',
            [hash('sha256', $token), $tipo]
        );
        if ($fila === null) {
            return null;
        }

        $fila['estado'] = $fila['usado_en'] !== null ? 'usado'
            : ((int) $fila['en_plazo'] === 1 ? 'vigente' : 'vencido');
        return $fila;
    }

    /** ¿El enlace fue al correo que la cuenta tiene ahora? */
    public static function esDelCorreo(array $enlace, string $correo): bool
    {
        return hash_equals((string) $enlace['correo_huella'], self::huellaCorreo($correo));
    }

    /**
     * Lo marca como usado. True solo para la primera petición que llega:
     * dos envíos del mismo formulario a la vez no cambian la contraseña dos
     * veces.
     */
    public static function gastar(int $id): bool
    {
        return Bd::ejecutar(
            'UPDATE {recuperacion_clave} SET usado_en = NOW()
              WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()',
            [$id]
        )->rowCount() === 1;
    }

    /** Deja sin efecto los enlaces sin usar de esa persona o cuenta. */
    public static function anularDe(string $tipo, int $sujetoId): void
    {
        Bd::ejecutar(
            'UPDATE {recuperacion_clave} SET expira_en = NOW()
              WHERE tipo = ? AND sujeto_id = ? AND usado_en IS NULL AND expira_en > NOW()',
            [$tipo, $sujetoId]
        );
    }

    /**
     * ¿Esta cuenta del equipo cambió su contraseña con un enlace del correo
     * hace menos de un día? Mientras sea así, el segundo factor no se puede
     * completar con un código al correo.
     */
    public static function recienteDeEquipo(int $usuarioId): bool
    {
        try {
            return (int) Bd::valor(
                "SELECT COUNT(*) FROM {recuperacion_clave}
                  WHERE tipo = 'admin' AND sujeto_id = ?
                    AND usado_en > DATE_SUB(NOW(), INTERVAL " . self::HORAS_SIN_RESPALDO . ' HOUR)',
                [$usuarioId]
            ) > 0;
        } catch (\Throwable) {
            // Sin la tabla —la base todavía no se actualizó— no hay ninguno.
            return false;
        }
    }

    /**
     * Limpieza de los que ya no sirven para nada.
     *
     * Se conservan dos días después de vencer: el uso de uno es lo que apaga
     * el código de respaldo por correo durante las veinticuatro horas
     * siguientes, y nunca se usa después de vencer.
     */
    public static function podar(): void
    {
        try {
            Bd::ejecutar('DELETE FROM {recuperacion_clave} WHERE expira_en < DATE_SUB(NOW(), INTERVAL 2 DAY)');
        } catch (\Throwable $e) {
            // Que la limpieza falle no puede tumbar el acceso de nadie.
            Registro::excepcion($e);
        }
    }

    private static function huellaCorreo(string $correo): string
    {
        return Cripto::huella('recuperacion|' . mb_strtolower(trim($correo)));
    }
}
