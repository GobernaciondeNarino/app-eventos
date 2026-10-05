<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Códigos de un solo uso basados en tiempo (RFC 6238).
 *
 * Compatible con Google Authenticator, Authy, FreeOTP y el gestor de
 * contraseñas que ya use la entidad. Son treinta líneas de código y evitan
 * depender de un SMS, que cuesta dinero y no llega en las zonas del
 * departamento donde justamente se hacen estos eventos.
 */
final class Totp
{
    private const DIGITOS = 6;
    private const PERIODO = 30;
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Intervalos de tolerancia a cada lado: ±30 segundos. */
    public const VENTANA = 1;

    /**
     * Hasta dónde se busca un código cuando no cuadra cerca: doce horas a cada
     * lado. Cubre un servidor sin sincronización horaria, que se va corriendo
     * minutos al mes, y también el error clásico de un reloj puesto en hora
     * local como si fuera UTC, que en Colombia son cinco horas exactas.
     */
    public const BUSQUEDA = 1440;

    /** Cuánto se espera el segundo código de una resincronización: cinco minutos. */
    public const PLAZO_CONFIRMAR = 300;

    /** Secreto nuevo en base32, el formato que leen las aplicaciones. */
    public static function generarSecreto(int $bytes = 20): string
    {
        return self::base32Codificar(random_bytes($bytes));
    }

    /**
     * URI otpauth:// para el código QR de alta.
     * El emisor y la cuenta son lo que la aplicación muestra en su listado.
     */
    public static function uri(string $secreto, string $cuenta, string $emisor): string
    {
        return 'otpauth://totp/' . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
            . '?secret=' . $secreto
            . '&issuer=' . rawurlencode($emisor)
            . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PERIODO;
    }

    public static function codigoActual(string $secreto, ?int $momento = null): string
    {
        return self::codigo($secreto, intdiv($momento ?? time(), self::PERIODO));
    }

    /**
     * Verifica el código admitiendo una ventana de tolerancia.
     *
     * ±1 intervalo, es decir hasta treinta segundos de desfase entre el reloj
     * del teléfono y el del servidor. Sin esa tolerancia, un servidor con la
     * hora ligeramente corrida rechaza códigos correctos y nadie entiende por qué.
     */
    public static function verificar(string $secreto, string $codigo, int $ventana = 1): bool
    {
        return self::intervaloValido($secreto, $codigo, $ventana) !== null;
    }

    /**
     * Igual que verificar(), pero devuelve el intervalo con el que cuadró.
     *
     * Sirve para no admitir dos veces el mismo código. El RFC 6238 (§5.2) lo
     * pide: el código vale hasta noventa segundos con la tolerancia de reloj, y
     * en ese rato alguien que lo haya visto —por encima del hombro, en una
     * captura— puede volver a usarlo. Guardando el último intervalo aceptado,
     * la segunda vez ya no entra.
     *
     * @return int|null El intervalo que cuadró, o null si ninguno.
     */
    public static function intervaloValido(string $secreto, string $codigo, int $ventana = 1): ?int
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) !== self::DIGITOS) {
            return null;
        }

        $contador = intdiv(time(), self::PERIODO);
        for ($desvio = -$ventana; $desvio <= $ventana; $desvio++) {
            if (hash_equals(self::codigo($secreto, $contador + $desvio), $codigo)) {
                return $contador + $desvio;
            }
        }
        return null;
    }

    /**
     * Evalúa un código teniendo en cuenta el reloj del servidor.
     *
     * Con la tolerancia de ±30 segundos alcanza mientras el servidor esté en
     * hora. Cuando no lo está, el código correcto se rechaza siempre y el
     * mensaje de «revisa el reloj del teléfono» manda a buscar el problema
     * donde no está: el teléfono pone la hora sola, el servidor no siempre.
     *
     * Así que, como pide el RFC 6238 (§6), se aprende el desfase de cada cuenta:
     *
     *   · Primero se mira cerca de la hora del servidor y cerca del desfase
     *     aprendido la última vez. Eso es lo normal, y si cuadra se entra.
     *
     *   · Si no cuadra, se busca más lejos, hasta doce horas. Un código que
     *     cuadra allá no basta para entrar —con tantos intervalos abiertos, uno
     *     al azar acierta en uno de cada trescientos intentos—: se pide el
     *     siguiente, que tiene que caer justo después con el mismo desfase. Dos
     *     códigos seguidos al azar son una posibilidad en un billón.
     *
     *   · El último intervalo aceptado no se admite dos veces, aunque siga
     *     dentro de la ventana. Pero si un código cuadra cerca de la hora y el
     *     último aceptado está bastante más adelante, no es que se esté
     *     repitiendo: es que el teléfono iba adelantado y lo pusieron en hora.
     *     Rechazarlo bloquearía la cuenta hasta que el reloj real alcanzara ese
     *     intervalo —horas, si el desfase era de horas—. Se trata como una
     *     resincronización: con el código siguiente, se entra. Más allá de lo
     *     que la búsqueda alcanza, el último aceptado se ignora sin más.
     *
     * @param int $deriva  Desfase aprendido, en intervalos (teléfono − servidor).
     * @param int $ultimo  Último intervalo aceptado.
     * @param array{intervalo: int, deriva: int, hasta: int}|null $pendiente
     *        El primer código de una resincronización, si hay una en curso.
     *
     * @return array{estado: string, intervalo?: int, deriva?: int, retroceso?: bool}
     *         estado: 'ok' para entrar; 'repetido' si ese código ya se usó;
     *         'confirmar' si cuadró lejos y hace falta el siguiente; 'no'.
     *         retroceso: el intervalo queda por debajo del último aceptado y
     *         hay que guardarlo igual (el teléfono se puso en hora).
     */
    public static function evaluar(
        string $secreto,
        string $codigo,
        int $deriva = 0,
        int $ultimo = 0,
        ?array $pendiente = null,
        ?int $ahora = null
    ): array {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        $llave = self::base32Decodificar($secreto);
        if (strlen($codigo) !== self::DIGITOS || $llave === '') {
            return ['estado' => 'no'];
        }

        $ahora ??= time();
        $actual = intdiv($ahora, self::PERIODO);
        if ($ultimo > $actual + self::BUSQUEDA) {
            $ultimo = 0;
        }
        $deriva = max(-self::BUSQUEDA, min(self::BUSQUEDA, $deriva));

        // 1. Cerca: la hora del servidor y el desfase aprendido.
        $cercanos = array_values(array_unique(array_merge(
            range(-self::VENTANA, self::VENTANA),
            range($deriva - self::VENTANA, $deriva + self::VENTANA)
        )));
        $repetido = false;
        $atrasado = null;
        foreach ($cercanos as $desvio) {
            $intervalo = $actual + $desvio;
            if (hash_equals(self::codigoDeLlave($llave, $intervalo), $codigo)) {
                if ($intervalo > $ultimo) {
                    return ['estado' => 'ok', 'intervalo' => $intervalo, 'deriva' => $desvio];
                }
                // Repetido de verdad si es de hace un momento; si el último
                // aceptado está mucho más adelante, el teléfono se puso en hora.
                if ($ultimo - $intervalo <= 2 * self::VENTANA + 1) {
                    $repetido = true;
                } else {
                    $atrasado ??= ['intervalo' => $intervalo, 'deriva' => $desvio];
                }
            }
        }

        // 2. El segundo código de una resincronización: tiene que caer después
        //    del primero, con el mismo desfase. Si la resincronización empezó
        //    por un teléfono que se puso en hora, puede quedar por debajo del
        //    último aceptado.
        if ($pendiente !== null && (int) ($pendiente['hasta'] ?? 0) >= $ahora) {
            $base = (int) $pendiente['deriva'];
            $retroceso = !empty($pendiente['retroceso']);
            foreach (range($base - self::VENTANA, $base + self::VENTANA) as $desvio) {
                $intervalo = $actual + $desvio;
                if ($intervalo > (int) $pendiente['intervalo'] && ($intervalo > $ultimo || $retroceso)
                    && hash_equals(self::codigoDeLlave($llave, $intervalo), $codigo)) {
                    return ['estado' => 'ok', 'intervalo' => $intervalo, 'deriva' => $desvio,
                            'retroceso' => $intervalo <= $ultimo];
                }
            }
        }

        if ($repetido) {
            return ['estado' => 'repetido'];
        }
        if ($atrasado !== null) {
            return ['estado' => 'confirmar', 'retroceso' => true] + $atrasado;
        }

        // 3. Lejos, del más cercano al más lejano.
        for ($paso = 1; $paso <= self::BUSQUEDA; $paso++) {
            foreach ([-$paso, $paso] as $desvio) {
                if (in_array($desvio, $cercanos, true)) {
                    continue;
                }
                $intervalo = $actual + $desvio;
                if ($intervalo > $ultimo && hash_equals(self::codigoDeLlave($llave, $intervalo), $codigo)) {
                    return ['estado' => 'confirmar', 'intervalo' => $intervalo, 'deriva' => $desvio];
                }
            }
        }

        return ['estado' => 'no'];
    }

    /**
     * El desfase en palabras: «4 minutos», «5 horas».
     *
     * El signo dice hacia dónde va el servidor respecto al teléfono, que es el
     * que se toma por bueno: con desfase positivo el teléfono va adelante, así
     * que el servidor va atrasado.
     *
     * @return array{0: string, 1: string} [cuánto, 'atrasado'|'adelantado']
     */
    public static function describirDeriva(int $deriva): array
    {
        $segundos = abs($deriva) * self::PERIODO;
        $cuanto = match (true) {
            $segundos < 90    => $segundos . ' segundos',
            $segundos < 5400  => round($segundos / 60) . ' minutos',
            default           => round($segundos / 3600, 1) . ' horas',
        };
        $cuanto = str_replace('.0 horas', ' horas', $cuanto);
        return [$cuanto, $deriva > 0 ? 'atrasado' : 'adelantado'];
    }

    private static function codigo(string $secreto, int $contador): string
    {
        return self::codigoDeLlave(self::base32Decodificar($secreto), $contador);
    }

    private static function codigoDeLlave(string $llave, int $contador): string
    {
        if ($llave === '') {
            return '';
        }

        $binario = pack('J', $contador);              // 64 bits, extremo grande
        $hash = hash_hmac('sha1', $binario, $llave, true);

        // Truncamiento dinámico del RFC 4226.
        $desplazamiento = ord($hash[19]) & 0x0F;
        $valor = ((ord($hash[$desplazamiento]) & 0x7F) << 24)
            | (ord($hash[$desplazamiento + 1]) << 16)
            | (ord($hash[$desplazamiento + 2]) << 8)
            | ord($hash[$desplazamiento + 3]);

        return str_pad((string) ($valor % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function base32Codificar(string $datos): string
    {
        $bits = '';
        foreach (str_split($datos) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $trozo) {
            $salida .= self::ALFABETO[bindec(str_pad($trozo, 5, '0', STR_PAD_RIGHT))];
        }
        return $salida;
    }

    private static function base32Decodificar(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $texto) ?? '');
        if ($texto === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($texto) as $caracter) {
            $indice = strpos(self::ALFABETO, $caracter);
            if ($indice === false) {
                return '';
            }
            $bits .= str_pad(decbin($indice), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 8) as $trozo) {
            if (strlen($trozo) === 8) {
                $salida .= chr(bindec($trozo));
            }
        }
        return $salida;
    }

    /** Agrupa el secreto de cuatro en cuatro, para poder dictarlo o teclearlo. */
    public static function formatear(string $secreto): string
    {
        return trim(chunk_split($secreto, 4, ' '));
    }
}
