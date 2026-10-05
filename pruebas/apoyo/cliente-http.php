<?php
/**
 * Cliente HTTP con cookies, para las pruebas que hablan con un servidor real.
 *
 * Imita lo justo de un navegador: guarda las cookies, sigue las redirecciones
 * y toma el testigo anti-falsificación del último formulario que vio, que es
 * de donde lo toma un navegador.
 *
 * extremo-a-extremo.php tiene el suyo dentro; este vive aparte para que las
 * suites nuevas no tengan que copiarlo.
 */
declare(strict_types=1);

final class ClienteHttp
{
    private array $cookies = [];
    private string $testigo = '';
    private string $raiz;

    public int $codigo = 0;
    public string $cuerpo = '';
    public array $cabeceras = [];

    public function __construct(private string $base)
    {
        $partes = parse_url($base);
        $this->raiz = $partes['scheme'] . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }

    public function get(string $ruta, bool $seguir = true): string
    {
        return $this->pedir('GET', $ruta, null, $seguir);
    }

    public function post(string $ruta, array $datos = [], bool $seguir = true): string
    {
        if (!isset($datos['_testigo'])) {
            $datos['_testigo'] = $this->testigo !== '' ? $this->testigo : ($this->cookies['evtic_csrf'] ?? '');
        }
        return $this->pedir('POST', $ruta, $datos, $seguir);
    }

    public function cabecera(string $nombre): string
    {
        foreach ($this->cabeceras as $linea) {
            if (stripos($linea, $nombre . ':') === 0) {
                return trim(substr($linea, strlen($nombre) + 1));
            }
        }
        return '';
    }

    private function urlDe(string $ruta): string
    {
        if (str_starts_with($ruta, 'http')) {
            return $ruta;
        }
        $rutaBase = parse_url($this->base, PHP_URL_PATH) ?: '';
        if ($rutaBase !== '' && str_starts_with($ruta, $rutaBase)) {
            return $this->raiz . $ruta;
        }
        return $this->base . $ruta;
    }

    private function pedir(string $metodo, string $ruta, ?array $datos, bool $seguir, int $saltos = 0): string
    {
        $cabeceras = '';
        if ($this->cookies) {
            $partes = [];
            foreach ($this->cookies as $n => $v) {
                $partes[] = $n . '=' . $v;
            }
            $cabeceras .= 'Cookie: ' . implode('; ', $partes) . "\r\n";
        }

        $opciones = ['http' => [
            'method' => $metodo, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
        ]];
        if ($datos !== null) {
            $cuerpo = http_build_query($datos);
            $cabeceras .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($cuerpo) . "\r\n";
            $opciones['http']['content'] = $cuerpo;
        }
        $opciones['http']['header'] = $cabeceras;

        $this->cuerpo = (string) @file_get_contents($this->urlDe($ruta), false, stream_context_create($opciones));
        $this->cabeceras = $http_response_header ?? [];
        $this->codigo = 0;
        foreach (array_reverse($this->cabeceras) as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $this->codigo = (int) $m[1];
                break;
            }
        }
        foreach ($this->cabeceras as $linea) {
            if (stripos($linea, 'Set-Cookie:') === 0
                && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $linea, $m)) {
                if ($m[2] === '' || $m[2] === 'deleted') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        if (preg_match('/name="_testigo" value="([a-f0-9]{64})"/', $this->cuerpo, $m)) {
            $this->testigo = $m[1];
        }

        if ($seguir && in_array($this->codigo, [301, 302, 303, 307, 308], true) && $saltos < 6) {
            $destino = $this->cabecera('Location');
            if ($destino !== '') {
                return $this->pedir('GET', $destino, null, true, $saltos + 1);
            }
        }
        return $this->cuerpo;
    }
}
