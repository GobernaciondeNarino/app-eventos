<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Cifrado y derivados.
 *
 * El número de documento se guarda cifrado porque es el dato que más duele
 * perder en una fuga: identifica a la persona ante cualquier otra entidad. Pero
 * también hay que poder detectar registros duplicados sin descifrar toda la
 * tabla, así que junto al valor cifrado se guarda un HMAC con clave, que es
 * determinista y permite buscar por igualdad sin revelar el contenido.
 *
 * Se usa XChaCha20-Poly1305 de libsodium cuando está disponible —cifrado
 * autenticado, con nonce de 24 bytes— y AES-256-GCM de OpenSSL como respaldo.
 */
final class Cripto
{
    private const VERSION_SODIUM = "\x01";
    private const VERSION_OPENSSL = "\x02";

    private static ?string $llave = null;

    /** Genera la llave maestra. Se llama una sola vez, en la instalación. */
    public static function generarLlave(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function llave(): string
    {
        if (self::$llave !== null) {
            return self::$llave;
        }
        $codificada = (string) Config::obtener('llave_cifrado', '');
        $cruda = base64_decode($codificada, true);
        if ($cruda === false || strlen($cruda) !== 32) {
            throw new \RuntimeException(
                'La llave de cifrado no es válida. Sin ella no se pueden leer los documentos guardados.'
            );
        }
        return self::$llave = $cruda;
    }

    /**
     * Olvida la llave leída. Hace falta cuando la configuración en memoria
     * cambia a mitad de la petición, como en el último paso del asistente.
     */
    public static function olvidarLlave(): void
    {
        self::$llave = null;
    }

    public static function hayLlave(): bool
    {
        $cruda = base64_decode((string) Config::obtener('llave_cifrado', ''), true);
        return $cruda !== false && strlen($cruda) === 32;
    }

    public static function cifrar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $llave = self::llave();

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($texto, $nonce, $nonce, $llave);
            return self::VERSION_SODIUM . $nonce . $cifrado;
        }

        $nonce = random_bytes(12);
        $etiqueta = '';
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $nonce, $etiqueta);
        if ($cifrado === false) {
            throw new \RuntimeException('No se pudo cifrar el dato.');
        }
        return self::VERSION_OPENSSL . $nonce . $etiqueta . $cifrado;
    }

    public static function descifrar(string $paquete): string
    {
        if ($paquete === '') {
            return '';
        }
        return self::descifrarCon($paquete, self::llave());
    }

    /**
     * ¿Abre esta llave un dato cifrado?
     *
     * Para el asistente: cuando la base ya tiene datos y config/config.php no
     * está, la llave con que se cifraron solo la puede dar quien guardó una
     * copia del archivo. Antes de aceptarla se prueba contra un dato de verdad;
     * aceptar una equivocada sería peor que no tener ninguna.
     */
    public static function llaveAbre(string $llaveCodificada, string $paquete): bool
    {
        $cruda = base64_decode(trim($llaveCodificada), true);
        if ($cruda === false || strlen($cruda) !== 32 || $paquete === '') {
            return false;
        }
        try {
            self::descifrarCon($paquete, $cruda);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function descifrarCon(string $paquete, string $llave): string
    {
        $version = $paquete[0];
        $resto = substr($paquete, 1);

        if ($version === self::VERSION_SODIUM) {
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($resto, 0, $n);
            $cifrado = substr($resto, $n);
            $claro = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cifrado, $nonce, $nonce, $llave);
            if ($claro === false) {
                throw new \RuntimeException('El dato cifrado no se pudo verificar.');
            }
            return $claro;
        }

        if ($version === self::VERSION_OPENSSL) {
            $nonce = substr($resto, 0, 12);
            $etiqueta = substr($resto, 12, 16);
            $cifrado = substr($resto, 28);
            $claro = openssl_decrypt($cifrado, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $nonce, $etiqueta);
            if ($claro === false) {
                throw new \RuntimeException('El dato cifrado no se pudo verificar.');
            }
            return $claro;
        }

        throw new \RuntimeException('Formato de dato cifrado desconocido.');
    }

    /**
     * Huella determinista para buscar sin descifrar.
     *
     * Es un HMAC y no un hash simple: sin la llave, quien obtenga la tabla no
     * puede probar cédulas por fuerza bruta, que con SHA-256 a secas sería
     * cuestión de minutos por el espacio tan pequeño de los documentos.
     */
    public static function huella(string $valor): string
    {
        return hash_hmac('sha256', $valor, self::llave());
    }

    /** Token opaco para URLs: 128 bits en hexadecimal. */
    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Código numérico de un solo uso, para el acceso por correo. */
    public static function codigoNumerico(int $digitos = 6): string
    {
        $maximo = (10 ** $digitos) - 1;
        return str_pad((string) random_int(0, $maximo), $digitos, '0', STR_PAD_LEFT);
    }

    /**
     * Parámetros de Argon2id, en un solo sitio.
     *
     * `threads => 1` a propósito, y no es una rebaja.
     *
     * PHP puede traer Argon2 de dos sitios: la biblioteca libargon2 suelta, o
     * la que va dentro de libsodium. La segunda **solo admite un hilo**, y
     * pedirle más no degrada nada: lanza
     * «ValueError: A thread value other than 1 is not supported by this
     * implementation». Desde fuera las dos compilaciones son idénticas —misma
     * versión de PHP, misma constante PASSWORD_ARGON2ID, mismo phpinfo—, así
     * que el fallo solo aparece en el servidor que tocó, y allí revienta en el
     * paso 4 del asistente: justo al convertir la contraseña del administrador.
     * La instalación se quedaba con las tablas creadas, la tabla de usuarios
     * vacía y sin manera de entrar. Pasó en producción.
     *
     * Un hilo es además el valor por omisión de PHP y el que recomienda OWASP
     * para Argon2id. El paralelismo no endurece el hash: reparte el mismo
     * trabajo entre varios núcleos. Lo que protege es el coste en memoria, que
     * se mantiene en 64 MiB.
     */
    private const ARGON = [
        'memory_cost' => 65536,   // 64 MiB
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    /** @return array{0: string|int, 1: array<string, int>} Algoritmo y opciones vigentes. */
    public static function algoritmoDeClave(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? [PASSWORD_ARGON2ID, self::ARGON]
            : [PASSWORD_BCRYPT, ['cost' => 12]];
    }

    public static function hashClave(string $clave): string
    {
        [$algoritmo, $opciones] = self::algoritmoDeClave();

        try {
            return password_hash($clave, $algoritmo, $opciones);
        } catch (\Throwable $e) {
            // Cinturón y tirantes. Los parámetros de arriba funcionan en las dos
            // compilaciones conocidas, pero esta llamada es la que decide si
            // alguien puede entrar a la plataforma: si una compilación rara
            // rechaza algo, es mejor un hash con bcrypt —que sigue siendo
            // aceptable— que una instalación muerta sin ninguna cuenta.
            Registro::error('password_hash falló con el algoritmo preferido; se usa bcrypt', [
                'motivo' => get_class($e) . ': ' . $e->getMessage(),
            ]);
            return password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
        }
    }

    /** ¿Este hash se hizo con parámetros viejos y conviene rehacerlo? */
    public static function claveNecesitaRehash(string $hash): bool
    {
        [$algoritmo, $opciones] = self::algoritmoDeClave();

        // Un hash bcrypt en un servidor con Argon2id no se rehace solo: puede
        // venir de una compilación que rechazó Argon2, y entonces reescribirlo
        // en cada acceso es trabajo perdido y ruido en la base.
        if (str_starts_with($hash, '$2y$') && $algoritmo !== PASSWORD_BCRYPT) {
            return false;
        }

        return password_needs_rehash($hash, $algoritmo, $opciones);
    }

    public static function verificarClave(string $clave, string $hash): bool
    {
        return password_verify($clave, $hash);
    }

    /** Comparación en tiempo constante, para tokens que llegan del cliente. */
    public static function iguales(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
