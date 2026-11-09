<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Enrutador.
 *
 * Tabla explícita de rutas, sin autodescubrimiento. Que las rutas se lean de
 * corrido en app/rutas.php tiene un valor concreto para la revisión de
 * seguridad: se ve de un vistazo qué expone la aplicación y con qué guardia
 * está protegida cada cosa. Con rutas mágicas hay que ir clase por clase.
 *
 * Los patrones admiten {parametro} y {parametro:formato}, donde el formato es
 * una de las expresiones permitidas más abajo. Nada llega al controlador sin
 * haber pasado ese filtro.
 */
final class Enrutador
{
    private array $rutas = [];

    private const FORMATOS = [
        'num'   => '[0-9]+',
        'token' => '[a-f0-9]{16,64}',
        'slug'  => '[a-z0-9\-]{1,60}',
        'texto' => '[^/]{1,120}',
        // El código de un certificado, como va impreso: «K7M2-9QXR-HT4P», o
        // sin guiones y en minúsculas, que es como lo teclea la gente.
        'codigo' => '[A-Za-z0-9\-]{6,24}',
    ];

    /**
     * @param string          $metodo   GET, POST o «GET|POST»
     * @param string          $patron   /admin/registros, /c/{token:token}
     * @param callable|array  $destino  [Controlador::class, 'metodo']
     * @param string|null     $guardia  null, 'asistente', 'admin', 'admin:rol'
     */
    public function agregar(string $metodo, string $patron, array|callable $destino, ?string $guardia = null): void
    {
        $this->rutas[] = [
            'metodos' => explode('|', strtoupper($metodo)),
            'regex'   => $this->compilar($patron),
            'patron'  => $patron,
            'destino' => $destino,
            'guardia' => $guardia,
        ];
    }

    public function get(string $patron, array|callable $destino, ?string $guardia = null): void
    {
        $this->agregar('GET', $patron, $destino, $guardia);
    }

    public function post(string $patron, array|callable $destino, ?string $guardia = null): void
    {
        $this->agregar('POST', $patron, $destino, $guardia);
    }

    public function ambos(string $patron, array|callable $destino, ?string $guardia = null): void
    {
        $this->agregar('GET|POST', $patron, $destino, $guardia);
    }

    private function compilar(string $patron): string
    {
        $regex = preg_replace_callback(
            '/\{([a-z_]+)(?::([a-z]+))?\}/',
            static function (array $m): string {
                $formato = self::FORMATOS[$m[2] ?? 'texto'] ?? self::FORMATOS['texto'];
                return '(?P<' . $m[1] . '>' . $formato . ')';
            },
            $patron
        );
        // \z y no $: en PCRE, $ también casa justo antes de un salto de línea
        // final, así que «/carnet\n» entraba por la misma puerta que «/carnet».
        return '#^' . $regex . '\z#u';
    }

    public function despachar(Peticion $peticion): never
    {
        $ruta = $peticion->ruta();

        // HEAD es GET sin cuerpo: lo usan los monitores de disponibilidad y
        // algunos proxies antes de cachear. Tratarlo como método desconocido
        // hacía que la portada respondiera 405.
        $metodo = $peticion->metodo() === 'HEAD' ? 'GET' : $peticion->metodo();
        $rutaCoincide = false;

        foreach ($this->rutas as $r) {
            if (!preg_match($r['regex'], $ruta, $coincidencias)) {
                continue;
            }
            $rutaCoincide = true;
            if (!in_array($metodo, $r['metodos'], true)) {
                continue;
            }

            $parametros = [];
            foreach ($coincidencias as $clave => $valor) {
                if (!is_int($clave)) {
                    $parametros[$clave] = $valor;
                }
            }

            Guardia::exigir($r['guardia'], $peticion);

            // El testigo se comprueba después del guardia: así un envío sin
            // sesión da «identifícate» y no un confuso «la página expiró».
            if ($metodo === 'POST') {
                Csrf::exigir($peticion);
            }

            $this->invocar($r['destino'], $peticion, $parametros);
        }

        if ($rutaCoincide) {
            Respuesta::error(405, 'Método no permitido',
                'Esa dirección no admite este tipo de envío.');
        }

        Respuesta::error(404, 'Página no encontrada',
            'La dirección no existe o el enlace ya venció.');
    }

    private function invocar(array|callable $destino, Peticion $peticion, array $parametros): never
    {
        if (is_array($destino)) {
            [$clase, $metodo] = $destino;
            $controlador = new $clase();
            $controlador->$metodo($peticion, $parametros);
        } else {
            $destino($peticion, $parametros);
        }
        exit;
    }
}
