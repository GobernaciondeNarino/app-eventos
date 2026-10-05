<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * La petición HTTP entrante.
 *
 * Lo importante de esta clase es la detección de la ruta base. La plataforma
 * tiene que funcionar igual colgando de la raíz de un dominio
 * (https://eventos.narino.gov.co/) que de una subcarpeta
 * (https://tic.narino.gov.co/cumbreAI/), sin tocar un solo archivo de
 * configuración. Todo lo demás del sistema construye sus URLs a partir de lo
 * que aquí se calcula.
 */
final class Peticion
{
    private string $metodo;
    private string $ruta;
    private string $base;
    private array $consulta;
    private array $cuerpo;
    private array $archivos;

    public function __construct()
    {
        $this->metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // dirname('/cumbreAI/index.php') = '/cumbreAI'
        // dirname('/index.php')          = '/'   → se normaliza a ''
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = str_replace('\\', '/', dirname($script));
        $this->base = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (($corte = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $corte);
        }
        $uri = rawurldecode($uri);

        // Se descuenta la subcarpeta para quedarse con la ruta de la aplicación.
        if ($this->base !== '' && str_starts_with($uri, $this->base)) {
            $uri = substr($uri, strlen($this->base));
        }

        $this->ruta = '/' . trim($uri, '/');

        // Llegar por /index.php es lo mismo que llegar a la portada. Pasa más
        // de lo que parece: es lo que queda en el portapapeles al copiar la
        // dirección del punto de entrada, y también lo que sirve un servidor
        // sin reescritura de URL. Sin esto respondía 404.
        if ($this->ruta === '/index.php') {
            $this->ruta = '/';
        }
        $this->consulta = $_GET;
        $this->cuerpo = $_POST;
        $this->archivos = $_FILES;
    }

    public function metodo(): string { return $this->metodo; }
    public function ruta(): string { return $this->ruta; }
    public function base(): string { return $this->base; }

    public function esPost(): bool { return $this->metodo === 'POST'; }

    /** Valor de la cadena de consulta, siempre como texto ya recortado. */
    public function query(string $clave, string $porDefecto = ''): string
    {
        $v = $this->consulta[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    /** Campo del formulario. Nunca devuelve arreglos: eso evita sorpresas al validar. */
    public function campo(string $clave, string $porDefecto = ''): string
    {
        $v = $this->cuerpo[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    public function campoCrudo(string $clave): string
    {
        $v = $this->cuerpo[$clave] ?? '';
        return is_string($v) ? $v : '';
    }

    public function marcado(string $clave): bool
    {
        return isset($this->cuerpo[$clave]) && $this->cuerpo[$clave] !== '';
    }

    /**
     * Un campo que llega repetido, como un grupo de casillas: `metodos[]`.
     *
     * Devuelve solo cadenas y solo del primer nivel. Un formulario puede mandar
     * un arreglo anidado —basta con escribir `metodos[a][b]` en el HTML— y sin
     * este filtro eso acabaría en la configuración, que se escribe con
     * var_export a un archivo PHP.
     *
     * @return array<int, string>
     */
    public function campoArreglo(string $clave): array
    {
        $valor = $this->cuerpo[$clave] ?? $this->consulta[$clave] ?? [];
        if (!is_array($valor)) {
            return [];
        }
        return array_values(array_map(
            static fn($v): string => mb_substr(trim((string) $v), 0, 60),
            array_filter($valor, static fn($v): bool => is_scalar($v))
        ));
    }

    /**
     * Un campo con estructura: `listas[genero][3][etiqueta]`.
     *
     * Para los editores que de verdad lo necesitan, como el de las opciones del
     * formulario de registro. Con límites: tres niveles, quinientos elementos
     * por nivel y textos de hasta veinte mil caracteres —el de departamentos y
     * municipios es largo—. Lo que pase de ahí se descarta, y lo que queda sigue
     * sin ser de fiar: quien lo usa lo valida campo por campo.
     *
     * @return array<string|int, mixed>
     */
    public function campoEstructurado(string $clave): array
    {
        $valor = $this->cuerpo[$clave] ?? null;
        return is_array($valor) ? self::recortar($valor, 3) : [];
    }

    private static function recortar(array $datos, int $niveles): array
    {
        $salida = [];
        foreach (array_slice($datos, 0, 500, true) as $clave => $valor) {
            if (is_array($valor)) {
                if ($niveles > 1) {
                    $salida[$clave] = self::recortar($valor, $niveles - 1);
                }
            } elseif (is_scalar($valor)) {
                $salida[$clave] = mb_substr((string) $valor, 0, 20000);
            }
        }
        return $salida;
    }

    public function entero(string $clave, int $porDefecto = 0): int
    {
        $v = $this->cuerpo[$clave] ?? $this->consulta[$clave] ?? null;
        return is_numeric($v) ? (int) $v : $porDefecto;
    }

    public function archivo(string $clave): ?array
    {
        $a = $this->archivos[$clave] ?? null;
        if (!is_array($a) || ($a['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $a;
    }

    public function esAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Dirección del cliente.
     *
     * En Plesk suele haber un nginx por delante de Apache, así que la IP real
     * llega en X-Forwarded-For. Solo se hace caso a esa cabecera cuando la
     * conexión viene de un proxy declarado en la configuración: de otro modo
     * cualquiera podría falsear su origen y saltarse el límite de intentos.
     */
    public function ip(): string
    {
        $remota = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $confiables = Config::obtener('proxies_confiables', []);

        if ($confiables && in_array($remota, $confiables, true)) {
            // Con Cloudflare por delante, CF-Connecting-IP trae la dirección
            // del visitante y Cloudflare descarta la que mande el cliente. Se
            // mira primero porque X-Forwarded-For puede llegar con varios
            // saltos encadenados.
            $directa = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($directa, FILTER_VALIDATE_IP)) {
                return $directa;
            }

            $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            foreach (explode(',', $reenviada) as $candidata) {
                $candidata = trim($candidata);
                if (filter_var($candidata, FILTER_VALIDATE_IP)) {
                    return $candidata;
                }
            }
        }
        return filter_var($remota, FILTER_VALIDATE_IP) ? $remota : '0.0.0.0';
    }

    /**
     * ¿Estamos viendo la dirección de un proxy en vez de la del visitante?
     *
     * Importa más de lo que parece. Si todas las peticiones llegan con la misma
     * dirección —la del nginx que Plesk pone por delante—, el límite de intentos
     * deja de ser por visitante y pasa a ser uno solo para todo el mundo: basta
     * con que alguien falle veinte accesos para dejar fuera al equipo entero.
     *
     * Se detecta por lo que ya se sabe: la conexión viene de una dirección
     * local o privada y además llega una cabecera de reenvío. Un visitante de
     * internet no puede presentarse con una dirección de esas.
     */
    public function detrasDeProxySinConfigurar(): bool
    {
        $remota = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remota === '' || Config::obtener('proxies_confiables', [])) {
            return false;
        }
        $hayReenvio = ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== ''
            || ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') !== ''
            || ($_SERVER['HTTP_X_REAL_IP'] ?? '') !== '';

        return $hayReenvio && self::esDireccionInterna($remota);
    }

    /** Loopback o rango privado: nadie llega desde internet con una de estas. */
    public static function esDireccionInterna(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * El navegador que dice ser.
     *
     * Se recorta por caracteres y no por bytes. Con substr(), un User-Agent de
     * más de 255 bytes podía quedar partido por la mitad de un carácter
     * multibyte; ese byte suelto no es UTF-8 válido y, con el modo estricto de
     * MySQL, el INSERT de la sesión falla y el acceso responde un 500.
     */
    public function agente(): string
    {
        $agente = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return mb_substr($agente, 0, 255, 'UTF-8');
    }

    /** ¿La petición llegó por HTTPS? Contempla el proxy de Plesk. */
    public function esSegura(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $confiables = Config::obtener('proxies_confiables', []);
        if ($confiables && in_array($_SERVER['REMOTE_ADDR'] ?? '', $confiables, true)) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    /** Esquema y host, para armar los enlaces absolutos que van en los correos. */
    public function origen(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // El host llega del cliente: se limita a lo que puede ser un nombre válido.
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?: 'localhost';
        return ($this->esSegura() ? 'https://' : 'http://') . $host;
    }
}
