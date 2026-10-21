<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Límite de intentos.
 *
 * Cubre tres puntos donde la fuerza bruta es rentable:
 *   · el acceso del organizador (adivinar contraseñas),
 *   · el código de acceso por correo del asistente (un millón de combinaciones,
 *     que sin límite se agotan en minutos),
 *   · la resolución de tokens de QR (enumerar credenciales para sacar la lista
 *     de asistentes).
 *
 * Se cuenta por acción y por clave —correo, IP o token—, en ventanas
 * deslizantes sobre la tabla de intentos. Guardar esto en base de datos y no en
 * memoria es a propósito: en un alojamiento compartido no hay Redis, y un
 * contador en archivo se pierde o se corrompe con varios procesos a la vez.
 */
final class Limite
{
    /**
     * [intentos permitidos, ventana en segundos, bloqueo en segundos]
     *
     * Hay dos clases de regla y conviene no confundirlas:
     *
     *  · Las que cuentan **fallos**: acceso, códigos de correo, tokens de QR.
     *    Solo se anota cuando algo salió mal, así que a quien acierta no le
     *    afecta.
     *  · Las que cuentan **acciones**, salgan bien o mal: el preregistro y el
     *    intercambio de contactos. Ahí el abuso consiste precisamente en tener
     *    éxito muchas veces, y por eso se anota con Limite::registrar().
     *
     * Los topes de la segunda clase son holgados a propósito. Una sede de
     * evento sale a internet por una sola dirección: si el tope fuera bajo, el
     * décimo asistente que se registrara en la puerta se encontraría con un
     * bloqueo, que es mucho peor que el abuso del que protege.
     */
    private const REGLAS = [
        'acceso_admin'     => [5, 900, 900],     // 5 en 15 min → 15 min de espera
        'acceso_admin_ip'  => [20, 900, 900],    // por IP, contra el rociado de contraseñas
        'codigo_correo'    => [5, 600, 1800],    // 5 códigos fallidos → media hora
        'envio_codigo'     => [4, 3600, 3600],   // 4 correos por hora y destinatario
        'token_qr'         => [30, 300, 900],    // enumeración de credenciales
        'preregistro_ip'   => [60, 3600, 900],   // altas masivas; toda una sede comparte IP
        'contacto'         => [60, 3600, 600],   // intercambios, por persona
        // Enlaces para recuperar la contraseña. Se cuenta cada pedido, exista o
        // no la cuenta, por la misma razón que el envío de códigos: si solo se
        // contaran unos, el bloqueo diría cuáles correos están registrados.
        'recuperar_clave'    => [4, 3600, 3600],   // por correo
        'recuperar_clave_ip' => [30, 3600, 900],   // por IP; toda una sede sale por la misma
        'enlace_clave'       => [30, 300, 900],    // enlaces que no existen, por IP
        // Cada prueba de correo abre una conexión y puede esperar quince
        // segundos. Sin tope, el botón es una forma cómoda de tener ocupado al
        // servidor; y probar contra Google muchas veces seguidas hace que
        // Google bloquee la cuenta por su cuenta, que es peor.
        'probar_correo'    => [10, 300, 300],    // por organizador
    ];

    /** ¿Está bloqueada la combinación acción + clave? Devuelve segundos restantes. */
    public static function bloqueado(string $accion, string $clave): int
    {
        [$maximo, $ventana, $castigo] = self::REGLAS[$accion] ?? [10, 900, 900];
        $huella = self::huella($accion, $clave);

        $fila = Bd::fila(
            'SELECT COUNT(*) AS n, MAX(creado_en) AS ultimo
               FROM {intento}
              WHERE huella = ? AND creado_en > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$huella, $ventana]
        );

        if (!$fila || (int) $fila['n'] < $maximo) {
            return 0;
        }

        $restante = $castigo - (time() - strtotime((string) $fila['ultimo']));
        return max(0, $restante);
    }

    public static function registrarFallo(string $accion, string $clave): void
    {
        self::anotar($accion, $clave);
    }

    /**
     * Anota una acción consumada, haya salido bien o mal.
     *
     * Para los límites donde el abuso consiste en tener éxito muchas veces: dar
     * de alta cien registros o recolectar contactos en cadena. Sin esto, la
     * regla existe en la tabla pero no cuenta nada y nunca llega a saltar, que
     * es peor que no tenerla, porque parece que protege.
     */
    public static function registrar(string $accion, string $clave): void
    {
        self::anotar($accion, $clave);
    }

    private static function anotar(string $accion, string $clave): void
    {
        Bd::insertar('intento', [
            'huella'    => self::huella($accion, $clave),
            'accion'    => $accion,
            'ip'        => @inet_pton(App::peticion()->ip()) ?: null,
            'creado_en' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Tras un acierto se borra el historial: no hay razón para castigar después. */
    public static function limpiar(string $accion, string $clave): void
    {
        Bd::ejecutar('DELETE FROM {intento} WHERE huella = ?', [self::huella($accion, $clave)]);
    }

    /**
     * Corta la petición si está bloqueado.
     * Devuelve los intentos que quedan, para poder avisar antes de llegar al tope.
     */
    public static function exigir(string $accion, string $clave): int
    {
        $espera = self::bloqueado($accion, $clave);
        if ($espera > 0) {
            Bitacora::registrar('limite_alcanzado', 'seguridad', null, [
                'accion' => $accion,
                'espera' => $espera,
            ]);
            $minutos = (int) ceil($espera / 60);
            Respuesta::error(429, 'Demasiados intentos',
                'Por seguridad esta acción quedó bloqueada. Vuelve a intentarlo en '
                . $minutos . ' ' . ($minutos === 1 ? 'minuto' : 'minutos') . '.');
        }

        [$maximo, $ventana] = self::REGLAS[$accion] ?? [10, 900];
        $usados = (int) Bd::valor(
            'SELECT COUNT(*) FROM {intento}
              WHERE huella = ? AND creado_en > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [self::huella($accion, $clave), $ventana]
        );
        return max(0, $maximo - $usados);
    }

    /**
     * La clave no se guarda en claro.
     *
     * Si se guardara, la tabla de intentos sería una lista de correos de
     * personas que fallaron el acceso, útil para quien la lea. Con el HMAC solo
     * sirve para contar.
     */
    private static function huella(string $accion, string $clave): string
    {
        $sal = (string) Config::obtener('llave_cifrado', 'sin-llave');
        return hash_hmac('sha256', $accion . '|' . mb_strtolower($clave), $sal);
    }
}
