<?php
/**
 * Prueba de extremo a extremo contra un servidor real.
 *
 * Instala la plataforma desde cero por el asistente, y luego recorre lo que de
 * verdad va a pasar el día del evento: alguien se preregistra, recibe su
 * carnet, escanea el código de la jornada, un operador lo acredita, dos
 * asistentes intercambian contacto y el equipo exporta los registros.
 *
 * Se hace por HTTP y no llamando a las clases: así se comprueban también el
 * enrutado, las cookies, los testigos anti-falsificación y los guardias, que
 * es donde suelen estar los errores.
 *
 * Uso:
 *   php -S 127.0.0.1:8900 -t /tmp/web /tmp/web/router.php &
 *   php pruebas/extremo-a-extremo.php http://127.0.0.1:8900/cumbreAI
 */
declare(strict_types=1);

// La misma zona horaria que usa la aplicación. Sin esto, entre las 19:00 y la
// medianoche de Bogotá el guion crea el evento con la fecha de mañana en UTC y
// después se extraña de que la jornada «todavía no empieza».
date_default_timezone_set('America/Bogota');

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8900/cumbreAI', '/');
$BD = [
    'nombre'  => getenv('BD_NOMBRE') ?: 'eventos_pruebas',
    'usuario' => getenv('BD_USUARIO') ?: 'eventos_app',
    'clave'   => getenv('BD_CLAVE') ?: 'clave_de_prueba_2026',
    'prefijo' => 'evt_',
];

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre . ($extra ? " → $extra" : '');
        echo "  ✗ $nombre" . ($extra ? "  → $extra" : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n";
}

/* =========================================================================
   Cliente HTTP con cookies, uno por «persona» del guion
   ========================================================================= */
final class Cliente
{
    private array $cookies = [];
    public int $codigo = 0;
    public string $cuerpo = '';
    public array $cabeceras = [];

    private string $raiz;

    public function __construct(private string $base)
    {
        // Las redirecciones llegan como rutas absolutas que ya incluyen la
        // subcarpeta (/cumbreAI/instalar). Sin separar el origen de la base, al
        // seguirlas se duplicaría la subcarpeta.
        $partes = parse_url($base);
        $this->raiz = $partes['scheme'] . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
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

    public function get(string $ruta, bool $seguirRedireccion = true): string
    {
        return $this->pedir('GET', $ruta, null, $seguirRedireccion);
    }

    public function cabeza(string $ruta): string
    {
        return $this->pedir('HEAD', $ruta, null, false);
    }

    /** Último testigo visto en un formulario, como haría un navegador. */
    private string $testigo = '';

    public function post(string $ruta, array $datos, bool $seguirRedireccion = true): string
    {
        // El testigo sale del formulario de la última página, que es de donde
        // lo toma un navegador. Sacarlo de la cookie funcionaba solo mientras el
        // testigo fuera la cookie; con sesión abierta se deriva de ella.
        if (!isset($datos['_testigo'])) {
            $datos['_testigo'] = $this->testigo !== ''
                ? $this->testigo
                : ($this->cookies['evtic_csrf'] ?? '');
        }
        return $this->pedir('POST', $ruta, $datos, $seguirRedireccion);
    }

    /**
     * POST multipart, que es la única forma de probar un campo de archivo.
     *
     * Con http_build_query el servidor recibe los nombres de los campos pero
     * $_FILES llega vacío, así que toda la validación de la subida —incluido
     * is_uploaded_file(), que es la barrera que de verdad importa— se quedaba
     * sin probar. Aquí el cuerpo se arma a mano, tal como lo manda un navegador.
     *
     * @param array<string, array{nombre:string, tipo:string, contenido:string}> $archivos
     */
    public function subir(string $ruta, array $datos, array $archivos, bool $seguirRedireccion = true): string
    {
        if (!isset($datos['_testigo'])) {
            $datos['_testigo'] = $this->testigo !== ''
                ? $this->testigo
                : ($this->cookies['evtic_csrf'] ?? '');
        }

        $limite = '----evtic' . bin2hex(random_bytes(8));
        $cuerpo = '';
        foreach ($datos as $nombre => $valor) {
            $cuerpo .= "--$limite\r\n"
                . 'Content-Disposition: form-data; name="' . $nombre . "\"\r\n\r\n"
                . $valor . "\r\n";
        }
        foreach ($archivos as $nombre => $a) {
            $cuerpo .= "--$limite\r\n"
                . 'Content-Disposition: form-data; name="' . $nombre . '"; filename="'
                . $a['nombre'] . "\"\r\n"
                . 'Content-Type: ' . $a['tipo'] . "\r\n\r\n"
                . $a['contenido'] . "\r\n";
        }
        $cuerpo .= "--$limite--\r\n";

        return $this->pedir('POST', $ruta, null, $seguirRedireccion, 0,
            ['tipo' => 'multipart/form-data; boundary=' . $limite, 'cuerpo' => $cuerpo]);
    }

    private function pedir(
        string $metodo,
        string $ruta,
        ?array $datos,
        bool $seguir,
        int $saltos = 0,
        ?array $crudo = null
    ): string {
        $url = $this->urlDe($ruta);

        $opciones = [
            'http' => [
                'method'        => $metodo,
                'header'        => $this->cabeceraCookies(),
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout'       => 20,
            ],
        ];
        if ($crudo !== null) {
            $opciones['http']['header'] .= 'Content-Type: ' . $crudo['tipo'] . "\r\n"
                . 'Content-Length: ' . strlen($crudo['cuerpo']) . "\r\n";
            $opciones['http']['content'] = $crudo['cuerpo'];
        } elseif ($datos !== null) {
            $cuerpo = http_build_query($datos);
            $opciones['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($cuerpo) . "\r\n";
            $opciones['http']['content'] = $cuerpo;
        }

        $contexto = stream_context_create($opciones);
        $this->cuerpo = (string) @file_get_contents($url, false, $contexto);
        $this->cabeceras = $http_response_header ?? [];
        $this->codigo = $this->codigoDe($this->cabeceras);
        $this->guardarCookies($this->cabeceras);

        if (preg_match('/name="_testigo" value="([a-f0-9]{64})"/', $this->cuerpo, $m)) {
            $this->testigo = $m[1];
        }

        if ($seguir && in_array($this->codigo, [301, 302, 303, 307, 308], true) && $saltos < 5) {
            $destino = $this->cabecera('Location');
            if ($destino !== '') {
                return $this->pedir('GET', $destino, null, true, $saltos + 1);
            }
        }
        return $this->cuerpo;
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

    private function codigoDe(array $cabeceras): int
    {
        foreach (array_reverse($cabeceras) as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function cabeceraCookies(): string
    {
        $partes = [];
        foreach ($this->cookies as $nombre => $valor) {
            if ($valor !== '') {
                $partes[] = "$nombre=$valor";
            }
        }
        $cabecera = "User-Agent: PruebaExtremoAExtremo/1.0\r\n";
        return $partes ? $cabecera . 'Cookie: ' . implode('; ', $partes) . "\r\n" : $cabecera;
    }

    private function guardarCookies(array $cabeceras): void
    {
        foreach ($cabeceras as $linea) {
            if (stripos($linea, 'Set-Cookie:') !== 0) {
                continue;
            }
            $trozo = trim(substr($linea, 11));
            [$par] = explode(';', $trozo, 2);
            if (!str_contains($par, '=')) {
                continue;
            }
            [$nombre, $valor] = explode('=', $par, 2);
            $this->cookies[trim($nombre)] = trim($valor);
        }
    }

    public function cookie(string $nombre): string
    {
        return $this->cookies[$nombre] ?? '';
    }
}

/* =========================================================================
   Utilidades
   ========================================================================= */

/**
 * Contenido legible de la cookie del asistente.
 *
 * setcookie() codifica el valor para URL, así que hay que deshacer eso antes de
 * deshacer el base64. Sin el urldecode, un valor con «+» o «/» —que aparecen o
 * no según el hash, y por eso fallaba a ratos— se decodifica a nada y cualquier
 * comprobación sobre su contenido pasaría sin comprobar nada.
 */
function cargaDeCookie(string $valor): string
{
    return base64_decode(urldecode($valor), true) ?: '';
}

/** Extrae el token de un enlace /d/xxxx o /c/xxxx que aparezca en el HTML. */
function tokenDe(string $html, string $tipo): string
{
    return preg_match('#/(' . $tipo . ')/([a-f0-9]{32})#', $html, $m) ? $m[2] : '';
}

$RAIZ = dirname(__DIR__);

/**
 * Relee config/config.php desde disco.
 *
 * `require` a secas devuelve lo que ya cacheó la primera vez, y estas pruebas
 * comprueban justamente que el archivo cambia entre una petición y la
 * siguiente.
 */
function leerConfig(string $raiz): array
{
    $ruta = $raiz . '/config/config.php';
    if (!is_file($ruta)) {
        return [];
    }
    $codigo = (string) file_get_contents($ruta);
    $datos = @eval('?>' . $codigo);
    return is_array($datos) ? $datos : [];
}

/**
 * Cambia valores de config/config.php, como haría quien administra el servidor.
 * Con null se quita la clave.
 */
function ajustarConfig(string $raiz, array $cambios): void
{
    $actual = leerConfig($raiz);
    foreach ($cambios as $clave => $valor) {
        if ($valor === null) {
            unset($actual[$clave]);
        } else {
            $actual[$clave] = $valor;
        }
    }
    file_put_contents($raiz . '/config/config.php', "<?php\n\nreturn " . var_export($actual, true) . ";\n");
    // El servidor de pruebas tiene opcache y vuelve a mirar la fecha del archivo
    // como mucho cada dos segundos. Desde este proceso no se le puede invalidar
    // la caché, así que se espera: si no, la petición siguiente aún ve la
    // configuración anterior.
    sleep(3);
}

/* =========================================================================
   Archivos de prueba
   -------------------------------------------------------------------------
   Los dos adjuntos del expositor son obligatorios, así que hacen falta desde
   el primer registro de alguien que va a exponer. Por eso viven aquí arriba y
   no en el bloque que los prueba a fondo.
   ========================================================================= */

/** Un PDF mínimo pero con la cabecera que mira el servidor. */
$pdfDePrueba = static fn(string $marca): string => "%PDF-1.4\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Count 0>>endobj\n% $marca\ntrailer<</Root 1 0 R>>\n%%EOF\n";

/** Un PPTX de verdad: un ZIP con el índice que declara la presentación. */
$pptxDePrueba = static function (string $marca): string {
    $ruta = tempnam(sys_get_temp_dir(), 'e2e') . '.pptx';
    @unlink($ruta);
    $z = new ZipArchive();
    $z->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml',
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Override PartName="/ppt/presentation.xml" ContentType='
        . '"application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
    $z->addFromString('ppt/presentation.xml', '<p:presentation><!-- ' . $marca . ' --></p:presentation>');
    $z->close();
    $bytes = (string) file_get_contents($ruta);
    @unlink($ruta);
    return $bytes;
};

/** Los dos campos de archivo listos para Cliente::subir(). */
$adjuntosDe = static function (string $marca) use ($pdfDePrueba, $pptxDePrueba): array {
    return [
        'hoja_vida'  => ['nombre' => 'hv.pdf', 'tipo' => 'application/pdf',
                         'contenido' => $pdfDePrueba('HV-' . $marca)],
        'exposicion' => ['nombre' => 'charla.pptx',
                         'tipo' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                         'contenido' => $pptxDePrueba('EXPO-' . $marca)],
    ];
};

echo "Prueba de extremo a extremo · $BASE\n";
echo str_repeat('=', 62) . "\n";

/* =========================================================================
   0 · Punto de partida limpio
   ========================================================================= */
titulo('Preparación');
@unlink($RAIZ . '/config/config.php');
@unlink($RAIZ . '/config/instalacion.php');
array_map('unlink', glob($RAIZ . '/almacen/registro/*.log.php') ?: []);

$pdo = new PDO(
    "mysql:host=localhost;dbname={$BD['nombre']};charset=utf8mb4",
    $BD['usuario'],
    $BD['clave'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
    $pdo->exec("DROP TABLE IF EXISTS `$tabla`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
comprobar('base de datos vacía', count($pdo->query('SHOW TABLES')->fetchAll()) === 0);

/* =========================================================================
   1 · Instalación por el asistente
   ========================================================================= */
titulo('Instalación');
$instalador = new Cliente($BASE);

$html = $instalador->get('/');
comprobar('sin instalar, todo lleva al asistente', str_contains($html, 'Comprobación del servidor'));

// El diagnóstico es lo único que distingue «nunca se instaló» de «se instaló y
// algo no se pudo leer». Sin instalación es público, como el propio asistente.
$html = $instalador->get('/instalar/diagnostico');
comprobar('el diagnóstico responde sin instalación',
    str_contains($html, 'La instalación no está utilizable'));
comprobar('y dice que falta config/config.php',
    str_contains($html, 'nunca se instaló') || str_contains($html, 'config/config.php'));

$html = $instalador->post('/instalar', ['accion' => 'paso1']);
comprobar('paso 1 → 2', str_contains($html, 'Conexión a la base de datos'));

$respuesta = $instalador->post('/instalar', [
    'accion' => 'probar_conexion',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => $BD['prefijo'],
]);
$json = json_decode($respuesta, true);
comprobar('la prueba de conexión responde', ($json['ok'] ?? false) === true, (string) ($json['mensaje'] ?? $respuesta));

$html = $instalador->post('/instalar', [
    'accion' => 'paso2',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => 'MAL PREFIJO',
]);
comprobar('rechaza un prefijo inválido', str_contains($html, 'prefijo debe empezar'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso2',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => $BD['prefijo'],
]);
comprobar('paso 2 → 3', str_contains($html, 'Tablas de la aplicación'));
comprobar('detecta que la base está vacía', str_contains($html, 'No hay ninguna tabla'));

// La contraseña de la base pasa a config/config.php en cuanto la conexión se
// comprueba, y no sigue viajando en la cookie del asistente paso tras paso.
$cookieTrasPaso2 = cargaDeCookie($instalador->cookie('evtic_instalacion'));
comprobar('la contraseña de la base sale de la cookie en el paso 2',
    !str_contains($cookieTrasPaso2, $BD['clave']));
comprobar('la guarda en config/instalacion.php',
    is_file($RAIZ . '/config/instalacion.php')
    && (require $RAIZ . '/config/instalacion.php')['clave'] === $BD['clave']);
// Que exista config/config.php significa «instalación terminada». Escribirlo a
// medias deja el sitio entero redirigiendo al asistente.
comprobar('y todavía no escribe config/config.php',
    !is_file($RAIZ . '/config/config.php'));

$html = $instalador->post('/instalar', ['accion' => 'paso3', 'modo' => 'limpio']);
comprobar('paso 3 → 4', str_contains($html, 'Cuenta administradora'));

// El número sale del propio esquema, no de una constante escrita a mano:
// agregar una tabla no debería romper una prueba que no habla de ella.
if (!defined('EVENTOS_TIC')) {
    define('EVENTOS_TIC', true);
}
require_once $RAIZ . '/app/Esquema.php';
$esperadas = count(App\Esquema::nombres());

$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
comprobar("creó las $esperadas tablas del esquema", count($tablas) === $esperadas,
    count($tablas) . ' encontradas');
comprobar('respetó el prefijo', str_starts_with((string) $tablas[0], $BD['prefijo']), (string) $tablas[0]);

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co', 'ad_clave' => 'corta', 'ad_clave2' => 'corta',
]);
comprobar('exige contraseña de 12 caracteres', str_contains($html, 'al menos 12 caracteres'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co',
    'ad_clave' => 'una frase larga y facil de recordar',
    'ad_clave2' => 'otra distinta',
]);
comprobar('exige que las contraseñas coincidan', str_contains($html, 'deben coincidir'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co',
    'ad_clave' => 'una frase larga y facil de recordar',
    'ad_clave2' => 'una frase larga y facil de recordar',
    'ad_2fa' => '',   // sin segundo factor, para poder seguir la prueba
]);
comprobar('paso 4 → 5', str_contains($html, 'Primer evento'));

$cookieTrasPaso4 = cargaDeCookie($instalador->cookie('evtic_instalacion'));
comprobar('la contraseña del administrador no viaja en claro en la cookie',
    !str_contains($cookieTrasPaso4, 'una frase larga y facil de recordar'));
comprobar('en su lugar viaja el hash', str_contains($cookieTrasPaso4, 'clave_hash'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso5',
    'ev_nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'ev_dependencia' => 'Secretaría TIC',
    'ev_sede' => 'Pasto',
    'ev_inicio' => date('Y-m-d'),
    'ev_dias' => '3',
    'preset' => 'tic-nocturno',
    'tipografia' => 'tecnologica',
]);
comprobar('paso 5 → 6, instalación terminada', str_contains($html, 'Instalación terminada'));
comprobar('escribió config/config.php', is_file($RAIZ . '/config/config.php'));

$config = require $RAIZ . '/config/config.php';
comprobar('guardó la llave de cifrado', strlen(base64_decode((string) $config['llave_cifrado'], true) ?: '') === 32);
comprobar('borró el archivo de conexión del proceso',
    !is_file($RAIZ . '/config/instalacion.php'));
comprobar('la contraseña de la base no quedó en la cookie de instalación',
    !str_contains(cargaDeCookie($instalador->cookie('evtic_instalacion')), $BD['clave']));

$jornadas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn();
comprobar('creó una jornada por día', $jornadas === 3, (string) $jornadas);

// Dónde queda la cuenta administradora. Se comprueba porque es lo primero que
// alguien busca cuando no puede entrar, y el nombre depende del prefijo.
$fila = $pdo->query("SELECT correo, rol, estado, clave_hash FROM {$BD['prefijo']}usuario")->fetch(PDO::FETCH_ASSOC);
comprobar('la cuenta quedó en la tabla ' . $BD['prefijo'] . 'usuario',
    ($fila['correo'] ?? '') === 'aerazo@narino.gov.co');
comprobar('con rol administrador y activa',
    ($fila['rol'] ?? '') === 'administrador' && ($fila['estado'] ?? '') === 'activo');
comprobar('la contraseña quedó en hash, no en claro',
    str_starts_with((string) ($fila['clave_hash'] ?? ''), '$')
    && !str_contains((string) ($fila['clave_hash'] ?? ''), 'frase larga'));

$html = $instalador->get('/instalar');
comprobar('el asistente se cierra tras instalar', str_contains($html, 'ya está instalada'));

// Y el diagnóstico deja de ser público en cuanto hay algo que proteger.
$curioso = new Cliente($BASE);
$curioso->get('/instalar/diagnostico', false);
comprobar('el diagnóstico pasa a exigir administrador',
    $curioso->codigo === 303 && str_contains($curioso->cabecera('Location'), '/admin/entrar'),
    (string) $curioso->codigo);
comprobar('la respuesta pública no trae el contenido del diagnóstico',
    !str_contains($curioso->cuerpo, 'Archivos y permisos'));

// La queja que originó todo esto: /admin/ con barra final no es una carpeta del
// servidor, es una ruta de la aplicación que lleva al acceso del equipo.
$anonimo = new Cliente($BASE);
foreach (['/admin', '/admin/'] as $ruta) {
    $anonimo->get($ruta, false);
    comprobar("$ruta lleva al acceso del equipo",
        $anonimo->codigo === 303
        && str_contains($anonimo->cabecera('Location'), '/admin/entrar'),
        $anonimo->codigo . ' → ' . $anonimo->cabecera('Location'));
}

/* =========================================================================
   1b · Instalación incompleta y su reparación
   -------------------------------------------------------------------------
   Va aquí, recién instalado y antes de que haya gente registrada, porque
   deja la base sin cuentas ni evento; al terminar la reparación vuelve al
   mismo punto y el resto del guion sigue como si nada.

   Reproduce lo que pasó en producción: config/config.php escrito y marcado
   como instalado, tablas creadas, y ninguna cuenta administradora. Antes eso
   era un callejón sin salida —el asistente respondía «ya está instalada» y el
   acceso «correo o contraseña incorrectos»—, así que se comprueba que ahora
   tiene salida y que se vuelve a cerrar sola.
   ========================================================================= */
titulo('Instalación incompleta');

// Se borran también las dependencias a mano: con FOREIGN_KEY_CHECKS apagado
// las cascadas no se disparan y quedarían jornadas huérfanas de un evento que
// ya no existe, que es un estado que la aplicación nunca produce.
// Algo ajustado a mano que el asistente no pregunta: reparar no puede
// llevárselo por delante.
$configuracion = require $RAIZ . '/config/config.php';
file_put_contents($RAIZ . '/config/config.php', "<?php\n\nreturn "
    . var_export(['proxies_confiables' => ['10.9.9.9']] + $configuracion, true) . ";\n");

$borrar = static function (array $tablas) use ($pdo, $BD): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tablas as $tabla) {
        $pdo->exec("DELETE FROM {$BD['prefijo']}$tabla");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
};
$anotaciones = static fn(): int => (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}bitacora")->fetchColumn();
$antesDeReparar = $anotaciones();

// Con cuenta pero sin evento: es el equipo quien tiene que crearlo.
$borrar(['asistencia', 'charla', 'evento_dia', 'evento_tema', 'evento']);
$visitante = new Cliente($BASE);
$visitante->get('/', false);
comprobar('sin evento, la portada responde 503', $visitante->codigo === 503, (string) $visitante->codigo);
comprobar('y manda al panel a crearlo',
    str_contains($visitante->cuerpo, 'Todavía no hay un evento abierto')
    && str_contains($visitante->cuerpo, 'Entrar al panel'));

// Casi todas las pantallas del equipo hacen $evento['id'] sin más. Sin evento
// eso es un aviso de índice indefinido, y el manejador de errores lo convierte
// en excepción: un 500 sin explicación en cada pantalla. Deben llevar a crear
// el evento, que es lo único que se puede hacer.
$conSesion = new Cliente($BASE);
$conSesion->get('/admin/entrar');
$conSesion->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);
foreach (['/admin', '/admin/escaner', '/admin/registros', '/admin/qr-dias',
          '/admin/expositores', '/admin/identidad'] as $ruta) {
    $conSesion->get($ruta, false);
    comprobar("sin evento, $ruta no revienta",
        $conSesion->codigo === 303 && str_contains($conSesion->cabecera('Location'), '/admin/eventos'),
        $conSesion->codigo . ' → ' . $conSesion->cabecera('Location'));
}
$html = $conSesion->get('/admin/eventos');
comprobar('y la pantalla de eventos sí carga, para poder crearlo',
    str_contains($html, 'Eventos') && !str_contains($html, 'Algo salió mal'));

// Sin ninguna cuenta: ya no hay quien lo cree.
$borrar(['sesion', 'usuario']);
$perdido = new Cliente($BASE);

$perdido->get('/', false);
comprobar('sin cuentas, dice que la instalación quedó a medias',
    $perdido->codigo === 503 && str_contains($perdido->cuerpo, 'quedó a medias'),
    (string) $perdido->codigo);
comprobar('y ofrece terminarla', str_contains($perdido->cuerpo, 'Terminar la instalación'));

$html = $perdido->get('/admin/entrar');
comprobar('el acceso avisa de que no hay ninguna cuenta',
    str_contains($html, 'Todavía no hay ninguna cuenta'));

$html = $perdido->get('/instalar');
comprobar('el asistente vuelve a abrirse en modo reparación',
    str_contains($html, 'Reparación de la instalación'));

$perdido->post('/instalar', ['accion' => 'paso1']);
$html = $perdido->post('/instalar', [
    'accion' => 'paso2',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => $BD['prefijo'],
]);
comprobar('la reparación exige otra vez las credenciales de la base',
    str_contains($html, 'Tablas de la aplicación'));
comprobar('y no ofrece la opción que borra datos',
    !str_contains($html, 'Instalación limpia'));
// El paso 3 tiene que ver las tablas que hay. Cuando no las veía, anunciaba
// «no hay ninguna tabla» y preseleccionaba la instalación limpia sobre una base
// con datos dentro.
comprobar("ve las $esperadas tablas que ya existen",
    str_contains($html, "Ya existen $esperadas tablas"),
    str_contains($html, 'No hay ninguna tabla') ? 'dijo que no había ninguna' : '');

// Se quita una columna a mano para comprobar el camino de actualización: al
// subir de versión, el modo «actualizar» tiene que agregar lo que falte sin
// tocar los datos. Es lo que va a pasar en cada actualización de la plataforma.
$pdo->exec("ALTER TABLE {$BD['prefijo']}usuario DROP COLUMN totp_ultimo");
$columnas = $pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}usuario LIKE 'totp_ultimo'")->fetchAll();
comprobar('se quitó una columna para probar la actualización', $columnas === []);

// Las cuatro de los adjuntos del expositor, que es lo que se encuentra una
// instalación anterior a la 1.5.0 al pulsar «Actualizar la base de datos».
foreach (['hoja_vida', 'hoja_vida_tipo', 'exposicion', 'exposicion_tipo'] as $sinEllas) {
    $pdo->exec("ALTER TABLE {$BD['prefijo']}propuesta DROP COLUMN $sinEllas");
}
comprobar('se quitaron las columnas de los adjuntos',
    $pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}propuesta LIKE 'hoja_vida'")->fetchAll() === []);

// Y el otro camino del ajuste de tipo: una columna que era un ENUM. Es lo que
// se encuentra una instalación anterior a la 1.6.0, donde «staff» todavía no
// existía como perfil de asistencia; desde la 1.9.0 la columna es texto, para
// los perfiles que configure cada evento.
$perfilesAntes = $pdo->query("SELECT rol, COUNT(*) FROM {$BD['prefijo']}persona GROUP BY rol ORDER BY rol")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("ALTER TABLE {$BD['prefijo']}persona MODIFY COLUMN rol
            ENUM('participante','visitante','expositor','organizador','prensa')
            NOT NULL DEFAULT 'participante'");
$tipoDelRol = static fn(): string => (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$BD['prefijo']}persona'
        AND COLUMN_NAME = 'rol'"
)->fetchColumn();
comprobar('se quitó «staff» del enum para probar el ajuste',
    !str_contains($tipoDelRol(), 'staff'));

$pdo->exec("ALTER TABLE {$BD['prefijo']}asistencia DROP COLUMN operador_tipo");
comprobar('y la columna que dice quién selló',
    $pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}asistencia LIKE 'operador_tipo'")->fetchAll() === []);

// Y una columna se devuelve a como era antes —NOT NULL— para probar el otro
// camino: el de las que ya existen pero cambiaron de tipo. Agregar lo que falta
// no alcanza ahí, porque la columna está; hace falta un ALTER ... MODIFY. Es
// exactamente lo que se encuentra una instalación vieja al subir a la 1.4.0.
$pdo->exec("UPDATE {$BD['prefijo']}persona SET documento_huella = REPEAT('0', 64)
             WHERE documento_huella IS NULL");
$pdo->exec("UPDATE {$BD['prefijo']}persona SET documento_cifrado = 'x'
             WHERE documento_cifrado IS NULL");
$pdo->exec("ALTER TABLE {$BD['prefijo']}persona MODIFY COLUMN documento_huella CHAR(64) NOT NULL");
$nulable = static fn(string $columna): string => (string) $pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$BD['prefijo']}persona'
        AND COLUMN_NAME = '$columna'"
)->fetchColumn();
comprobar('se devolvió una columna a NOT NULL para probar el ajuste',
    $nulable('documento_huella') === 'NO');

// Aunque se envíe a mano, «limpio» no se aplica en una reparación.
$html = $perdido->post('/instalar', ['accion' => 'paso3', 'modo' => 'limpio']);
$columnas = $pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}usuario LIKE 'totp_ultimo'")->fetchAll();
comprobar('el modo actualizar devuelve la columna que faltaba', count($columnas) === 1);
comprobar('y vuelve nulable la que había cambiado de tipo',
    $nulable('documento_huella') === 'YES', $nulable('documento_huella'));

$adjuntas = $pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}propuesta")->fetchAll(PDO::FETCH_COLUMN);
comprobar('y devuelve las cuatro columnas de los adjuntos',
    count(array_intersect(['hoja_vida', 'hoja_vida_tipo', 'exposicion', 'exposicion_tipo'],
        $adjuntas)) === 4, implode(', ', $adjuntas));
// MySQL devuelve la cadena vacía y MariaDB devuelve «''» con comillas: las dos
// dicen lo mismo, que las propuestas que ya estaban no se quedan con un NULL
// que nadie sabría pintar.
$porOmision = (string) $pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE()
                                       AND TABLE_NAME = '{$BD['prefijo']}propuesta'
                                       AND COLUMN_NAME = 'hoja_vida'")->fetchColumn();
comprobar('vacías por omisión, para las propuestas que ya estaban',
    trim($porOmision, "'") === '', $porOmision);

comprobar('y la columna del perfil pasa a texto: le entran Staff y los perfiles que se agreguen',
    strtolower($tipoDelRol()) === 'varchar(40)', $tipoDelRol());
comprobar('sin perder el perfil de nadie',
    $pdo->query("SELECT rol, COUNT(*) FROM {$BD['prefijo']}persona GROUP BY rol ORDER BY rol")
        ->fetchAll(PDO::FETCH_KEY_PAIR) === $perfilesAntes);
comprobar('y vuelve la columna que dice quién selló cada ingreso',
    count($pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}asistencia
                        LIKE 'operador_tipo'")->fetchAll()) === 1);
comprobar('paso 3 de la reparación', str_contains($html, 'Cuenta administradora'));
comprobar('no borró nada de lo que ya había',
    $anotaciones() >= $antesDeReparar, $anotaciones() . ' de ' . $antesDeReparar);

$perdido->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co',
    'ad_clave' => 'una frase larga y facil de recordar',
    'ad_clave2' => 'una frase larga y facil de recordar',
    'ad_2fa' => '',
]);
$html = $perdido->post('/instalar', [
    'accion' => 'paso5',
    'ev_nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'ev_dependencia' => 'Secretaría TIC',
    'ev_sede' => 'Pasto',
    'ev_inicio' => date('Y-m-d'), 'ev_dias' => '3',
    'preset' => 'tic-nocturno', 'tipografia' => 'tecnologica',
]);
comprobar('la reparación termina', str_contains($html, 'Instalación terminada'));
comprobar('y dice dónde quedó guardada la cuenta',
    str_contains($html, $BD['prefijo'] . 'usuario'));
comprobar('y respeta lo que estaba ajustado a mano en la configuración',
    (require $RAIZ . '/config/config.php')['proxies_confiables'] === ['10.9.9.9']);

$html = $perdido->get('/instalar');
comprobar('el asistente se cierra otra vez solo', str_contains($html, 'ya está instalada'));

$recuperado = new Cliente($BASE);
$recuperado->get('/admin/entrar');
$html = $recuperado->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);
comprobar('se puede entrar al panel con la cuenta reparada',
    str_contains($html, 'Indicadores') || str_contains($html, 'Panel'),
    (string) $recuperado->codigo);
$html = $recuperado->get('/admin/entrar');
comprobar('y el aviso de «no hay cuentas» desaparece',
    !str_contains($html, 'Todavía no hay ninguna cuenta'));

/* -------------------------------------------------------------------------
   La marca «instalado» perdida
   -------------------------------------------------------------------------
   Es el fallo que dejó el sitio de producción redirigiendo al asistente: la
   base completa y la configuración diciendo que no. Que la plataforma insista
   en el asistente ahí no ayuda a nadie, así que se corrige sola.
   ------------------------------------------------------------------------- */
$configuracion = require $RAIZ . '/config/config.php';
file_put_contents(
    $RAIZ . '/config/config.php',
    "<?php\n\nreturn " . var_export(['instalado' => false] + $configuracion, true) . ";\n"
);
comprobar('la configuración quedó marcada como no instalada',
    (require $RAIZ . '/config/config.php')['instalado'] === false);

$tras = new Cliente($BASE);
$tras->get('/', false);
comprobar('aun así la portada carga, sin mandar al asistente',
    $tras->codigo === 200 && !str_contains($tras->cabecera('Location'), '/instalar'),
    $tras->codigo . ' → ' . $tras->cabecera('Location'));
comprobar('y la marca queda corregida en el archivo',
    (require $RAIZ . '/config/config.php')['instalado'] === true);

/* =========================================================================
   2 · Preregistro de un asistente
   ========================================================================= */
titulo('Preregistro');
$maria = new Cliente($BASE);

$html = $maria->get('/');
comprobar('la portada ya no redirige al asistente', str_contains($html, 'Regístrate una vez'));
comprobar('muestra el nombre del evento', str_contains($html, 'Cumbre Tecnológica CIOS Nariño'));

$maria->get('/preregistro');
$html = $maria->post('/preregistro', [
    'correo' => 'mzambrano@narino.gov.co', 'nombre' => 'Mar',
    'tipo_documento' => 'CC', 'documento' => '12',
]);
comprobar('rechaza datos incompletos', str_contains($html, 'nombre completo'));
comprobar('exige la autorización de datos', str_contains($html, 'autorizar el tratamiento'));

$datosMaria = [
    'correo' => 'mzambrano@narino.gov.co',
    'nombre' => 'María Fernanda Zambrano',
    'tipo_documento' => 'CC', 'documento' => '1085234567',
    'telefono' => '+57 316 220 4471', 'rol' => 'participante',
    'entidad' => 'Gobernación de Nariño',
    'departamento' => 'Nariño', 'municipio' => 'Pasto',
    'genero' => 'F', 'rango_edad' => '26–35', 'etnia' => 'Ninguno', 'discapacidad' => 'No',
    'habeas' => '1',
];
$html = $maria->post('/preregistro', $datosMaria);
comprobar('el preregistro lleva al carnet', str_contains($html, 'Tu carnet digital'), substr(strip_tags($html), 0, 120));
comprobar('el carnet muestra el nombre', str_contains($html, 'María Fernanda Zambrano'));
comprobar('el carnet muestra el documento formateado', str_contains($html, '1.085.234.567'));

$fila = $pdo->query("SELECT * FROM {$BD['prefijo']}persona WHERE correo = 'mzambrano@narino.gov.co'")->fetch(PDO::FETCH_ASSOC);
comprobar('el documento se guardó cifrado', !str_contains((string) $fila['documento_cifrado'], '1085234567'));
comprobar('guardó la huella para buscar sin descifrar', strlen((string) $fila['documento_huella']) === 64);
comprobar('registró la autorización de datos', !empty($fila['autorizo_datos_en']));

$caract = $pdo->query("SELECT * FROM {$BD['prefijo']}persona_caracterizacion")->fetch(PDO::FETCH_ASSOC);
comprobar('la caracterización quedó en su tabla aparte', ($caract['genero'] ?? '') === 'F');

$tokenCarnet = tokenDe($html, 'c');
comprobar('el carnet trae un token en su QR', strlen($tokenCarnet) === 32, $tokenCarnet);
comprobar('el QR es un SVG generado en el servidor', str_contains($html, '<svg') && str_contains($html, 'shape-rendering'));
comprobar('el QR no contiene datos personales',
    !str_contains(substr($html, strpos($html, 'carnet__qrbox') ?: 0, 4000), '1085234567'));

/* =========================================================================
   3 · Un segundo asistente, para el intercambio de contactos
   ========================================================================= */
titulo('Segundo asistente');
$carlos = new Cliente($BASE);
$carlos->get('/preregistro');
$datosCarlos = [
    'correo' => 'cbolanos@tumaco.gov.co', 'nombre' => 'Carlos Andrés Bolaños',
    'tipo_documento' => 'CC', 'documento' => '12994510',
    'telefono' => '+57 315 908 3344', 'rol' => 'expositor',
    'entidad' => 'Alcaldía de Tumaco', 'departamento' => 'Nariño', 'municipio' => 'Tumaco',
    'expositor' => '1', 'tema' => 'Emprender TIC desde el Pacífico',
    'categoria' => 'Emprendimiento y startups TIC',
    'detalle' => 'Panel con cuatro emprendimientos del Pacífico nariñense y su acceso a capital.',
    'dia_preferido' => '2', 'duracion' => '40', 'requerimientos' => 'HDMI',
    'habeas' => '1',
];

// Sin los dos adjuntos no hay propuesta: son obligatorios para quien expone.
$html = $carlos->subir('/preregistro', $datosCarlos, []);
comprobar('un expositor sin documentos no pasa',
    str_contains($html, 'Adjunta tu hoja de vida'), substr(strip_tags($html), 0, 160));
comprobar('y no se registró a medias',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE correo = 'cbolanos@tumaco.gov.co'")->fetchColumn() === 0);

$carlos->get('/preregistro');
$html = $carlos->subir('/preregistro', $datosCarlos, $adjuntosDe('carlos'));
comprobar('el segundo preregistro funciona', str_contains($html, 'Carlos Andrés Bolaños'));

$propuestas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}propuesta")->fetchColumn();
comprobar('guardó la propuesta de exposición', $propuestas === 1);

$otro = new Cliente($BASE);
$otro->get('/preregistro');
$html = $otro->post('/preregistro', [
    'correo' => 'otro@narino.gov.co', 'nombre' => 'Persona Distinta',
    'tipo_documento' => 'CC', 'documento' => '1085234567',   // documento de María
    'habeas' => '1',
]);
comprobar('rechaza un documento ya registrado con otro correo', str_contains($html, 'ya está registrado con otro correo'));

/* -------------------------------------------------------------------------
   Toma de sesión por el preregistro
   -------------------------------------------------------------------------
   Persona::registrar() busca por correo y actualiza si encuentra, y después se
   abría sesión con ese id. Cualquiera que supiera el correo de un asistente
   —en una entidad son públicos— podía reescribir su nombre y su documento y
   quedarse dentro de su cuenta.
   ------------------------------------------------------------------------- */
$intruso = new Cliente($BASE);
$intruso->get('/preregistro');
$html = $intruso->post('/preregistro', [
    'correo' => 'mzambrano@narino.gov.co',      // de alguien ya registrado
    'nombre' => 'Persona Suplantadora',
    'tipo_documento' => 'CC', 'documento' => '1099887766',
    'habeas' => '1',
]);
comprobar('no deja registrar sobre el correo de otra persona',
    str_contains($html, 'ya tiene un registro en este evento'));
comprobar('y ofrece el acceso por código', str_contains($html, 'Entrar con mi código'));

$intruso->get('/carnet', false);
comprobar('no quedó con sesión de esa persona', $intruso->codigo === 303,
    (string) $intruso->codigo);

// Y con sesión propia abierta tampoco: el «readonly» del correo lo decide el
// navegador, y un envío hecho a mano llegaba al registro de otra persona.
$html = $maria->post('/preregistro', [
    'correo' => 'cbolanos@tumaco.gov.co',          // el de otro asistente
    'nombre' => 'María Fernanda Zambrano Corregida',
    'tipo_documento' => 'CC', 'documento' => '1085234567',
    'habeas' => '1',
]);
$ajena = $pdo->query("SELECT nombre FROM {$BD['prefijo']}persona
                       WHERE correo = 'cbolanos@tumaco.gov.co'")->fetchColumn();
comprobar('un asistente identificado no puede escribir sobre otro registro',
    $ajena !== 'María Fernanda Zambrano Corregida', (string) $ajena);
$propia = $pdo->query("SELECT nombre FROM {$BD['prefijo']}persona
                        WHERE correo = 'mzambrano@narino.gov.co'")->fetchColumn();
comprobar('lo enviado se aplica a su propio registro',
    $propia === 'María Fernanda Zambrano Corregida', (string) $propia);

$suplantada = $pdo->query("SELECT nombre FROM {$BD['prefijo']}persona
                            WHERE correo = 'mzambrano@narino.gov.co'")->fetchColumn();
comprobar('y no le cambió el nombre', $suplantada !== 'Persona Suplantadora',
    (string) $suplantada);

/* -------------------------------------------------------------------------
   Documentos con letras
   -------------------------------------------------------------------------
   normalizarDocumento() quitaba las letras, así que dos pasaportes distintos
   —AB123456 y CD123456— quedaban en el mismo «123456» y el segundo se
   rechazaba diciendo que ya estaba registrado con otro correo.
   ------------------------------------------------------------------------- */
$pasaporte1 = new Cliente($BASE);
$pasaporte1->get('/preregistro');
$html = $pasaporte1->post('/preregistro', [
    'correo' => 'visitante.uno@ajeno.example', 'nombre' => 'Visitante Uno Extranjero',
    'tipo_documento' => 'PP', 'documento' => 'AB123456', 'habeas' => '1',
]);
comprobar('un pasaporte con letras se registra', str_contains($html, 'Visitante Uno Extranjero'));

$pasaporte2 = new Cliente($BASE);
$pasaporte2->get('/preregistro');
$html = $pasaporte2->post('/preregistro', [
    'correo' => 'visitante.dos@ajeno.example', 'nombre' => 'Visitante Dos Extranjero',
    'tipo_documento' => 'PP', 'documento' => 'CD123456', 'habeas' => '1',
]);
comprobar('y otro que solo cambia en las letras no choca con el primero',
    str_contains($html, 'Visitante Dos Extranjero'), 'chocaron por el número');

$html = $pasaporte1->post('/preregistro', [
    'correo' => 'visitante.uno@ajeno.example', 'nombre' => 'Visitante Uno Extranjero',
    'tipo_documento' => 'CC', 'documento' => 'AB123456', 'habeas' => '1',
]);
comprobar('pero una cédula con letras se rechaza', str_contains($html, 'solo números'));

/* =========================================================================
   4 · El código QR de la jornada
   ========================================================================= */
titulo('Código QR de la jornada');
$token = (string) $pdo->query("SELECT token FROM {$BD['prefijo']}evento_dia WHERE numero = 1")->fetchColumn();

$anonimo = new Cliente($BASE);
$html = $anonimo->get("/d/$token", false);
$destino = $anonimo->cabecera('Location');
comprobar('sin sesión, el código del día pide identificarse',
    $anonimo->codigo === 303 && str_contains($destino, '/entrar'), $anonimo->codigo . ' ' . $destino);
comprobar('recuerda a dónde iba', str_contains(urldecode($destino), "/d/$token"));

$html = $maria->get("/d/$token");
comprobar('con sesión, registra el ingreso', str_contains($html, 'Ingreso registrado'));

$asistencias = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn();
comprobar('quedó una asistencia', $asistencias === 1);

$html = $maria->get("/d/$token");
comprobar('escanear dos veces no duplica', str_contains($html, 'Ya tenías el ingreso'));
comprobar('sigue habiendo una sola asistencia',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn() === 1);

$tokenDia2 = (string) $pdo->query("SELECT token FROM {$BD['prefijo']}evento_dia WHERE numero = 2")->fetchColumn();
$html = $maria->get("/d/$tokenDia2");
comprobar('el código de otro día no sirve hoy', str_contains($html, 'Todavía no se puede registrar'));

$html = $maria->get('/d/' . str_repeat('a', 32));
comprobar('un token inventado no revela nada', str_contains($html, 'ya no sirve'));

/* =========================================================================
   5 · El QR del carnet: intercambio de contacto
   ========================================================================= */
titulo('QR del carnet');
$html = $anonimo->get("/c/$tokenCarnet");
comprobar('sin sesión, pregunta quién eres', str_contains($html, '¿Cómo participas en el evento?'));
comprobar('no revela de quién es el carnet', !str_contains($html, 'María Fernanda'));

$html = $carlos->get("/c/$tokenCarnet");
comprobar('otro asistente ve el intercambio de contacto', str_contains($html, 'Intercambiar contacto'));
comprobar('muestra los cuatro datos compartidos', str_contains($html, 'mzambrano@narino.gov.co'));

$html = $carlos->post("/c/$tokenCarnet/contacto", []);
comprobar('el intercambio se guarda', str_contains($html, 'Contacto agregado'));

// Los límites que cuentan acciones consumadas —y no fallos— tienen que anotar
// también cuando la acción sale bien. Si no, la regla existe pero nunca cuenta
// nada, y parece que protege sin protegerlo.
$anotados = static fn(string $accion): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM {$BD['prefijo']}intento WHERE accion = '$accion'"
)->fetchColumn();
comprobar('el intercambio cuenta para su límite', $anotados('contacto') > 0);
comprobar('el preregistro también cuenta para el suyo', $anotados('preregistro_ip') > 0);
comprobar('el intercambio es recíproco',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}contacto")->fetchColumn() === 2);

$html = $maria->get('/contactos');
comprobar('María ve a Carlos en sus contactos', str_contains($html, 'Carlos Andrés Bolaños'));

$vcf = $maria->get('/contactos/exportar');
comprobar('exporta vCard', str_contains($vcf, 'BEGIN:VCARD') && str_contains($vcf, 'Carlos'));

/* =========================================================================
   6 · El equipo organizador
   ========================================================================= */
titulo('Equipo organizador');
$admin = new Cliente($BASE);
$admin->get('/admin/entrar');

$html = $admin->get('/admin', false);
comprobar('el panel exige identificarse',
    $admin->codigo === 303 && str_contains($admin->cabecera('Location'), '/admin/entrar'));

$html = $admin->post('/admin/entrar', ['correo' => 'aerazo@narino.gov.co', 'clave' => 'incorrecta']);
comprobar('rechaza la contraseña equivocada', str_contains($html, 'Correo o contraseña incorrectos'));

$html = $admin->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave' => 'una frase larga y facil de recordar',
]);
comprobar('entra con las credenciales correctas', str_contains($html, 'Panel del evento'));
comprobar('el panel cuenta los registrados', str_contains($html, 'Registrados'));

$html = $admin->get('/admin/registros');
comprobar('ve el listado de registros', str_contains($html, 'María Fernanda Zambrano'));
comprobar('muestra el documento descifrado', str_contains($html, '1.085.234.567'));

$csv = $admin->get('/admin/registros/exportar');
comprobar('exporta CSV', str_contains($csv, 'María Fernanda Zambrano') && str_contains($csv, ';'));
comprobar('el CSV no trae caracterización por defecto', !str_contains($csv, 'Género'));

$csv = $admin->get('/admin/registros/exportar?caracterizacion=1');
comprobar('el administrador sí puede exportar la caracterización', str_contains($csv, 'Género'));

$html = $admin->get('/admin/expositores');
comprobar('ve la propuesta pendiente', str_contains($html, 'Emprender TIC desde el Pacífico'));

$idPropuesta = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}propuesta LIMIT 1")->fetchColumn();
$html = $admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idPropuesta, 'decision' => 'observada', 'observacion' => '',
]);
comprobar('devolver exige escribir la observación', str_contains($html, 'Escribe la observación'));

$html = $admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idPropuesta, 'decision' => 'aprobada',
    'dia' => '2', 'hora' => '15:00', 'salon' => 'Auditorio principal',
]);
comprobar('aprobar publica en la agenda', str_contains($html, 'publicada en la agenda'));

$html = $anonimo->get('/agenda?dia=2');
comprobar('la agenda pública muestra la charla aprobada', str_contains($html, 'Emprender TIC desde el Pacífico'));

/* =========================================================================
   7 · Acreditación por el operador
   ========================================================================= */
titulo('Acreditación');
$html = $admin->get("/c/$tokenCarnet");
comprobar('el equipo ve la ficha de acreditación', str_contains($html, 'Carnet reconocido'));
comprobar('la ficha muestra el documento', str_contains($html, '1.085.234.567'));
comprobar('avisa que ya tiene ingreso de hoy', str_contains($html, 'Ya tiene ingreso del día'));

$tokenCarlos = (string) $pdo->query(
    "SELECT c.token FROM {$BD['prefijo']}credencial c
       JOIN {$BD['prefijo']}persona p ON p.id = c.persona_id
      WHERE p.correo = 'cbolanos@tumaco.gov.co'"
)->fetchColumn();

$html = $admin->post("/c/$tokenCarlos/asistencia", ['jornada' => '1']);
comprobar('el operador sella el ingreso', str_contains($html, 'Ingreso de Carlos Andrés Bolaños'));
comprobar('quedan dos asistencias',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn() === 2);
comprobar('la asistencia queda atribuida al operador',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia WHERE operador_id IS NOT NULL")->fetchColumn() === 1);

/* =========================================================================
   8 · Identidad del evento
   ========================================================================= */
titulo('Identidad del evento');
$html = $admin->get('/admin/identidad');
comprobar('la pantalla de identidad carga', str_contains($html, 'Identidad del evento'));
comprobar('muestra la revisión de contraste', str_contains($html, 'Revisión de contraste'));

$html = $admin->post('/admin/identidad', [
    'nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'dependencia' => 'Secretaría TIC', 'sede' => 'Pasto',
    'preset' => 'narino-verde', 'tipografia' => 'neutra',
]);
comprobar('guarda la identidad', str_contains($html, 'Identidad guardada'));

$html = $anonimo->get('/');
comprobar('el color nuevo llega a la parte pública', str_contains($html, '#7CF7C8'));
comprobar('la tipografía nueva también', str_contains($html, 'data-tipografia="neutra"'));

$html = $admin->post('/admin/identidad', [
    'nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'preset' => 'narino-verde', 'tipografia' => 'neutra',
    'color_accent' => 'javascript:alert(1)',
]);
$html = $anonimo->get('/');
comprobar('un color inválido no entra en el CSS', !str_contains($html, 'javascript'));

/* =========================================================================
   9 · Seguridad
   ========================================================================= */
titulo('Seguridad');
$html = $anonimo->get('/admin/registros', false);
comprobar('un anónimo no ve los registros', $anonimo->codigo === 303);

$html = $maria->get('/admin/registros', false);
comprobar('un asistente tampoco entra al backoffice',
    $maria->codigo === 303 && str_contains($maria->cabecera('Location'), '/admin/entrar'));

$sinTestigo = new Cliente($BASE);
$sinTestigo->get('/preregistro');
$html = $sinTestigo->post('/preregistro', ['_testigo' => 'falso', 'correo' => 'x@y.co', 'nombre' => 'Prueba XSS']);
// Con sesión abierta, el testigo se deriva de ella. Un valor plantado en la
// cookie —cosa que puede hacer cualquier subdominio hermano de narino.gov.co—
// ya no sirve para forjar un envío.
$plantado = new Cliente($BASE);
$plantado->get('/admin/entrar');
$plantado->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);
$html = $plantado->post('/admin/identidad',
    ['preset' => 'tic-nocturno', '_testigo' => str_repeat('a', 64)]);
comprobar('un testigo plantado en la cookie no vale con sesión abierta',
    str_contains($html, 'demasiado tiempo abierta'));

comprobar('un envío con testigo falso se rechaza', str_contains($html, 'demasiado tiempo abierta'));

foreach ([
    '/app/Nucleo/Bd.php',
    '/config/config.php',
    '/config/instalacion.php',
    '/almacen/registro/',
    '/pruebas/flujos.js',
    '/herramientas/instalar.php',
    '/herramientas/cuenta.php',
] as $ruta) {
    $c = new Cliente($BASE);
    $c->get($ruta, false);
    comprobar("no se sirve $ruta", $c->codigo === 404 || $c->codigo === 403, (string) $c->codigo);
}

$c = new Cliente($BASE);
$c->get('/');
$cabeceras = implode("\n", $c->cabeceras);
// HEAD lo usan los monitores de disponibilidad; respondía 405 en todo el sitio.
$cabeza = new Cliente($BASE);
$cabeza->cabeza('/');
comprobar('HEAD sobre la portada responde 200', $cabeza->codigo === 200, (string) $cabeza->codigo);

// En PCRE, «$» casa también antes de un salto de línea final.
$colado = new Cliente($BASE);
$colado->get("/agenda\n", false);
comprobar('una ruta con un salto de línea al final no cuela',
    $colado->codigo === 404, (string) $colado->codigo);

comprobar('envía Content-Security-Policy', str_contains($cabeceras, 'Content-Security-Policy'));
comprobar('envía X-Frame-Options', str_contains($cabeceras, 'X-Frame-Options: DENY'));
comprobar('envía X-Content-Type-Options', str_contains($cabeceras, 'nosniff'));
comprobar('las cookies son HttpOnly', str_contains($cabeceras, 'HttpOnly'));
comprobar('las cookies llevan SameSite', str_contains($cabeceras, 'SameSite=Lax'));

// Reflejo de un intento de inyección en un campo de texto
$xss = new Cliente($BASE);
$xss->get('/preregistro');
$html = $xss->post('/preregistro', [
    'correo' => 'xss@prueba.co', 'nombre' => '<script>alert(1)</script>Nombre',
    'tipo_documento' => 'CC', 'documento' => '99887766', 'habeas' => '1',
    'entidad' => '"><img src=x onerror=alert(1)>',
]);
comprobar('el nombre con etiquetas se escapa', !str_contains($html, '<script>alert(1)</script>'));
// La cadena «onerror=alert(1)» sí aparece, pero como texto: lo que importa es
// que no llegue a formarse una etiqueta ni a cerrarse el atributo.
comprobar('la etiqueta inyectada no se forma', !str_contains($html, '<img src=x'));
comprobar('el valor se muestra escapado', str_contains($html, '&lt;img') || str_contains($html, '&quot;&gt;'));

// Redirección abierta
$c = new Cliente($BASE);
$c->get('/entrar?destino=https://sitio-falso.example/roba', false);
$html = $c->get('/entrar?destino=https://sitio-falso.example/roba');
comprobar('no acepta un destino externo', !str_contains($html, 'sitio-falso.example'));

// Bitácora
$entradas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}bitacora")->fetchColumn();
comprobar('la bitácora registró la actividad', $entradas > 5, (string) $entradas);
$conClave = (int) $pdo->query(
    "SELECT COUNT(*) FROM {$BD['prefijo']}bitacora WHERE detalle LIKE '%frase larga%'"
)->fetchColumn();
comprobar('la bitácora no guarda contraseñas', $conClave === 0);

$sesiones = $pdo->query("SELECT id FROM {$BD['prefijo']}sesion LIMIT 1")->fetchColumn();
comprobar('la cookie de sesión no es el identificador guardado',
    $sesiones && $maria->cookie('evtic_asis') !== '' && $sesiones !== $maria->cookie('evtic_asis'));

/* =========================================================================
   8b · Una cuenta nueva tiene que cambiar su contraseña
   -------------------------------------------------------------------------
   Al crear una cuenta del equipo se marca «debe cambiar», pero esa marca no la
   miraba nadie y no existía ninguna pantalla para cambiarla: la persona se
   quedaba para siempre con la clave que otro le escribió y probablemente le
   pasó por chat.
   ========================================================================= */
titulo('Contraseña de una cuenta nueva');

$admin->post('/admin/organizadores/crear', [
    'nombre' => 'Operador De Puerta',
    'correo' => 'puerta@narino.gov.co',
    'clave'  => 'clave temporal del jefe',
    'rol'    => 'operador',
]);
comprobar('el administrador crea una cuenta de operador',
    (int) $pdo->query("SELECT debe_cambiar FROM {$BD['prefijo']}usuario
                        WHERE correo = 'puerta@narino.gov.co'")->fetchColumn() === 1);

$nuevo = new Cliente($BASE);
$nuevo->get('/admin/entrar');
$nuevo->post('/admin/entrar', ['correo' => 'puerta@narino.gov.co', 'clave' => 'clave temporal del jefe']);
$nuevo->get('/admin/escaner', false);
comprobar('con la clave que le puso otro no puede trabajar todavía',
    $nuevo->codigo === 303 && str_contains($nuevo->cabecera('Location'), '/admin/clave'),
    $nuevo->codigo . ' → ' . $nuevo->cabecera('Location'));

$html = $nuevo->get('/admin/clave');
comprobar('y se le explica por qué', str_contains($html, 'la puso otra persona'));

$html = $nuevo->post('/admin/clave', [
    'actual' => 'no es esta', 'nueva' => 'una clave mia y bien larga', 'nueva2' => 'una clave mia y bien larga',
]);
comprobar('sin la contraseña actual no se cambia', str_contains($html, 'no es tu contraseña actual'));

$html = $nuevo->post('/admin/clave', [
    'actual' => 'clave temporal del jefe', 'nueva' => 'corta', 'nueva2' => 'corta',
]);
comprobar('la nueva tiene que ser larga', str_contains($html, 'al menos 12 caracteres'));

$html = $nuevo->post('/admin/clave', [
    'actual' => 'clave temporal del jefe',
    'nueva'  => 'una clave mia y bien larga',
    'nueva2' => 'una clave mia y bien larga',
]);
comprobar('con la actual correcta sí se cambia', str_contains($html, 'Contraseña cambiada'));

$nuevo->get('/admin/escaner', false);
comprobar('y ya puede trabajar', $nuevo->codigo === 200, (string) $nuevo->codigo);

$viejaClave = new Cliente($BASE);
$viejaClave->get('/admin/entrar');
$html = $viejaClave->post('/admin/entrar',
    ['correo' => 'puerta@narino.gov.co', 'clave' => 'clave temporal del jefe']);
comprobar('la contraseña anterior deja de servir', str_contains($html, 'incorrectos'));

/* =========================================================================
   Correo
   -------------------------------------------------------------------------
   La pantalla que configura con qué credencial envía la plataforma en nombre
   de la Gobernación. Dos cosas que no pueden fallar: que solo la vea un
   administrador, y que la contraseña de aplicación no salga nunca del
   servidor.
   ========================================================================= */
titulo('Configuración de correo');

// $nuevo es la sesión del operador, que ya cambió su clave más arriba.
$nuevo->get('/admin/correo', false);
comprobar('un operador no entra a /admin/correo', $nuevo->codigo !== 200,
    'respondió ' . $nuevo->codigo);

$nuevo->post('/admin/correo/red', [], false);
comprobar('ni puede lanzar el diagnóstico de red', $nuevo->codigo !== 200,
    'respondió ' . $nuevo->codigo);

$nuevo->post('/admin/correo/local', [], false);
comprobar('ni cambiar la configuración al correo local', $nuevo->codigo !== 200,
    'respondió ' . $nuevo->codigo);

$html = $admin->get('/admin/correo');
comprobar('el administrador sí, y la pantalla carga',
    str_contains($html, 'Modo de envío') && !str_contains($html, 'Algo salió mal'));
comprobar('trae la revisión de la configuración', str_contains($html, 'Estado de la configuración'));
comprobar('y la tabla de códigos de error', str_contains($html, '535-5.7.8'));

$admin->post('/admin/correo', [
    'modo_correo'                => 'smtp',
    'correo_remitente'           => 'hosting@narino.gov.co',
    'correo_nombre'              => 'Secretaría TIC',
    'smtp_host'                  => 'smtp.gmail.com',
    'smtp_puerto'                => '587',
    'smtp_seguridad'             => 'tls',
    'smtp_usuario'               => 'hosting@narino.gov.co',
    'smtp_clave'                 => 'abcd efgh ijkl mnop',
    'smtp_espera'                => '15',
    'smtp_verificar_certificado' => '1',
]);
$guardado = leerConfig($RAIZ);
comprobar('se guarda el modo SMTP', ($guardado['modo_correo'] ?? '') === 'smtp');
comprobar('y la contraseña sin los espacios con que Google la enseña',
    ($guardado['smtp_clave'] ?? '') === 'abcdefghijklmnop',
    (string) ($guardado['smtp_clave'] ?? '—'));

$html = $admin->get('/admin/correo');
comprobar('la contraseña NUNCA vuelve al navegador',
    !str_contains($html, 'abcdefghijklmnop') && !str_contains($html, 'abcd efgh'));
comprobar('pero se avisa de que hay una guardada', str_contains($html, 'hay una guardada'));

comprobar('la bitácora anota el cambio sin la contraseña',
    (static function () use ($pdo, $BD): bool {
        $fila = $pdo->query("SELECT detalle FROM {$BD['prefijo']}bitacora
                              WHERE accion = 'correo_configurado'
                              ORDER BY id DESC LIMIT 1")->fetchColumn();
        return is_string($fila) && !str_contains($fila, 'abcdefghijklmnop');
    })());

// Un modo inventado no se acepta.
$admin->post('/admin/correo', [
    'modo_correo' => 'lo-que-sea', 'correo_remitente' => 'hosting@narino.gov.co',
    'smtp_puerto' => '587', 'smtp_seguridad' => 'tls',
], false);
$guardado = leerConfig($RAIZ);
comprobar('un modo de envío inventado se rechaza', ($guardado['modo_correo'] ?? '') === 'smtp');

// El diagnóstico de red: no manda correo, solo mira si hay ruta de salida.
$admin->post('/admin/correo/red', []);
$html = $admin->get('/admin/correo');
comprobar('el diagnóstico de red se ejecuta y se muestra',
    str_contains($html, 'Salida de red hasta'));
comprobar('con lo que devolvió el DNS', str_contains($html, 'Qué devuelve el DNS'));
comprobar('y con el estado de mail() en el servidor', str_contains($html, 'sendmail_path'));
comprobar('prueba también el relé de la propia máquina',
    str_contains($html, 'Servidor de correo de esta misma máquina'));

/* El botón de un clic: la salida cuando el proveedor bloquea el SMTP saliente. */
$admin->post('/admin/correo/local', ['puerto' => '25']);
$guardado = leerConfig($RAIZ);
comprobar('«Usar el correo local» deja el modo en SMTP', ($guardado['modo_correo'] ?? '') === 'smtp');
comprobar('apuntando a localhost', ($guardado['smtp_host'] ?? '') === 'localhost');
comprobar('en el puerto 25', (int) ($guardado['smtp_puerto'] ?? 0) === 25);
comprobar('sin cifrado, que es lo que habla el relé local',
    ($guardado['smtp_seguridad'] ?? '') === 'ninguna');
comprobar('y sin credenciales: el relé local no las pide',
    ($guardado['smtp_usuario'] ?? 'x') === '' && ($guardado['smtp_clave'] ?? 'x') === '');
/* Modo API: la salida por HTTPS 443 cuando el cortafuegos rechaza el SMTP. */
$admin->post('/admin/correo', [
    'modo_correo'      => 'api',
    'correo_remitente' => 'hosting@narino.gov.co',
    'correo_nombre'    => 'Secretaría TIC',
    'api_proveedor'    => 'resend',
    'api_clave'        => 'clave-de-prueba-para-la-api',
    'smtp_puerto'      => '587',
    'smtp_seguridad'   => 'tls',
    'smtp_espera'      => '15',
]);
$guardado = leerConfig($RAIZ);
comprobar('se guarda el modo API', ($guardado['modo_correo'] ?? '') === 'api');
comprobar('con el proveedor elegido', ($guardado['api_proveedor'] ?? '') === 'resend');
comprobar('y su clave', ($guardado['api_clave'] ?? '') === 'clave-de-prueba-para-la-api');

$html = $admin->get('/admin/correo');
comprobar('la clave de API tampoco vuelve al navegador',
    !str_contains($html, 'clave-de-prueba-para-la-api'));
comprobar('pero se avisa de que hay una guardada',
    substr_count($html, 'hay una guardada') >= 1);
comprobar('y la revisión recuerda verificar el dominio en el proveedor',
    str_contains($html, 'verificado en el proveedor'));

$admin->post('/admin/correo', [
    'modo_correo' => 'api', 'correo_remitente' => 'hosting@narino.gov.co',
    'api_proveedor' => 'un-proveedor-inventado', 'smtp_puerto' => '587', 'smtp_seguridad' => 'tls',
], false);
comprobar('un proveedor de API inventado se rechaza',
    (leerConfig($RAIZ)['api_proveedor'] ?? '') === 'resend');

comprobar('un puerto inventado cae al 25',
    (static function () use ($admin, $RAIZ): bool {
        $admin->post('/admin/correo/local', ['puerto' => '9999']);
        return (int) (leerConfig($RAIZ)['smtp_puerto'] ?? 0) === 25;
    })());

/* =========================================================================
   9a · Búsqueda por texto
   -------------------------------------------------------------------------
   Sin emulación de sentencias preparadas, MySQL no admite repetir un marcador
   con nombre. Las tres búsquedas de la plataforma repetían «:texto» cuatro
   veces y respondían 500, incluida la del escáner, que es la que se usa en la
   puerta cuando a alguien no le funciona el código.
   ========================================================================= */
titulo('Búsqueda por texto');

$html = $admin->post('/admin/escaner/buscar', ['q' => 'Zambrano']);
comprobar('el escáner encuentra a alguien por su nombre',
    str_contains($html, 'Zambrano') && !str_contains($html, 'Algo salió mal'));

$html = $admin->get('/admin/registros?q=Zambrano');
comprobar('los registros filtran por texto',
    str_contains($html, 'Zambrano') && !str_contains($html, 'Algo salió mal'));

$html = $maria->get('/agenda?q=' . rawurlencode('conectividad'));
comprobar('la agenda pública también busca', !str_contains($html, 'Algo salió mal'));

$html = $admin->get('/admin/registros?q=' . rawurlencode("100%_'"));
comprobar('y los comodines del texto no rompen la consulta',
    !str_contains($html, 'Algo salió mal'));

/* =========================================================================
   9b · Cada pantalla carga su JavaScript
   -------------------------------------------------------------------------
   Las vistas declaraban sus guiones en una variable que la plantilla no podía
   ver, porque se pintan por separado y no comparten ámbito. Ninguno llegaba al
   navegador: ni el escáner de la puerta, ni el selector de municipios, ni la
   vista previa de la identidad, ni el «Probar conexión» del instalador. Todo
   estaba en el HTML y nada se cargaba.
   ========================================================================= */
titulo('JavaScript de cada pantalla');

foreach ([
    ['/preregistro',      'preregistro.js', $maria],
    ['/carnet',           'carnet.js',      $maria],
    ['/checkin',          'escaner.js',     $maria],
    ['/admin/escaner',    'escaner.js',     $admin],
    ['/admin/identidad',  'identidad.js',   $admin],
] as [$ruta, $guion, $cliente]) {
    $html = $cliente->get($ruta);
    comprobar("$ruta carga $guion", str_contains($html, 'assets/js/' . $guion));
}

$html = $maria->get('/');
comprobar('y una pantalla sin guion propio no arrastra ninguno',
    str_contains($html, 'assets/js/app.js')
    && !preg_match('#assets/js/(escaner|identidad|preregistro)\.js#', $html));

titulo('QR de acceso y dispositivo recordado');

// El método QR tiene que venir encendido de fábrica: si dependiera de que un
// administrador lo active, la instalación recién hecha seguiría colgando de
// que el correo salga, que es el fallo que se quiso quitar de raíz.
$configActual = leerConfig($RAIZ);
comprobar('el QR de acceso viene encendido de fábrica',
    in_array('qr', (array) ($configActual['auth_metodos'] ?? ['correo', 'qr']), true),
    json_encode($configActual['auth_metodos'] ?? null));

$html = $maria->get('/carnet');
comprobar('el carnet trae el QR de acceso, aparte del de contacto',
    str_contains($html, 'Mi QR de acceso'));
comprobar('y avisa de que no se comparte',
    str_contains($html, 'No lo compartas'));

preg_match('#/entrar/qr/([a-f0-9]{32})#', $html, $m);
$tokenAcceso = $m[1] ?? '';
comprobar('el token de acceso son 128 bits', strlen($tokenAcceso) === 32, $tokenAcceso);
comprobar('el de acceso y el de contacto son distintos',
    $tokenAcceso !== '' && $tokenAcceso !== $tokenCarnet);

// Un teléfono nuevo: sin cookies, como quien abre el enlace desde el correo.
$telefono = new Cliente($BASE);
$html = $telefono->get('/c/' . $tokenCarnet);
comprobar('el QR de contacto sigue sin identificar a nadie',
    str_contains($html, 'Escaneaste el carnet'),
    substr(strip_tags($html), 0, 90));

$html = $telefono->get('/entrar/qr/' . $tokenAcceso);
comprobar('el QR de acceso sí abre la sesión', str_contains($html, 'Tu carnet digital'),
    substr(strip_tags($html), 0, 90));
comprobar('y entra como su dueño', str_contains($html, 'María Fernanda Zambrano'));
comprobar('dejó la marca del dispositivo', $telefono->cookie('evtic_disp') !== '');
comprobar('la marca lleva selector y validador',
    (bool) preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', urldecode($telefono->cookie('evtic_disp'))));

$guardadas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}dispositivo")->fetchColumn();
comprobar('quedó anotado en la base', $guardadas >= 1, (string) $guardadas);

[$selector, $validador] = explode('.', urldecode($telefono->cookie('evtic_disp')));
$filaDisp = $pdo->query("SELECT * FROM {$BD['prefijo']}dispositivo
                          WHERE selector = '$selector'")->fetch(PDO::FETCH_ASSOC);
comprobar('del validador solo se guarda el hash',
    is_array($filaDisp)
    && (string) $filaDisp['validador_hash'] === hash('sha256', $validador)
    && !str_contains((string) json_encode($filaDisp), $validador));

// Un token de acceso que no existe no puede identificar a nadie.
$intruso = new Cliente($BASE);
$intruso->get('/entrar/qr/' . str_repeat('a', 32));
comprobar('un token inventado no entra', $intruso->codigo === 404, (string) $intruso->codigo);

// Y ahora lo importante: se borra la cookie de sesión pero se deja la del
// dispositivo, que es exactamente lo que pasa a los treinta días.
$pdo->exec("DELETE FROM {$BD['prefijo']}sesion WHERE tipo = 'asistente'");
$html = $telefono->get('/carnet');
comprobar('sin sesión, el dispositivo recordado la vuelve a abrir',
    str_contains($html, 'María Fernanda Zambrano'), substr(strip_tags($html), 0, 90));

// Salir tiene que significar salir: la marca del dispositivo también se va.
$telefono->post('/salir', []);
// PHP borra una cookie mandándola con el valor «deleted» y fecha pasada, así
// que lo que se comprueba es que ya no tenga la forma de una marca válida.
comprobar('al salir se olvida el dispositivo',
    !preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', urldecode($telefono->cookie('evtic_disp'))),
    $telefono->cookie('evtic_disp'));
comprobar('y la fila desaparece de la base',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}dispositivo
                        WHERE selector = '$selector'")->fetchColumn() === 0);
$html = $telefono->get('/carnet');
comprobar('y ya no se entra solo', !str_contains($html, 'María Fernanda Zambrano'));

// Un validador equivocado con un selector real es un intento de robo: se borra
// la fila entera, no solo se rechaza.
$otroTelefono = new Cliente($BASE);
$otroTelefono->get('/entrar/qr/' . $tokenAcceso);
[$sel2] = explode('.', urldecode($otroTelefono->cookie('evtic_disp')));

// Se cambia el hash guardado: para el servidor, la cookie que trae ese teléfono
// pasa a ser un validador equivocado sobre un selector real, que es la forma que
// tiene una cookie robada y modificada. La fila entera tiene que desaparecer.
$pdo->exec("UPDATE {$BD['prefijo']}dispositivo SET validador_hash = REPEAT('c', 64)
             WHERE selector = '$sel2'");
$pdo->exec("DELETE FROM {$BD['prefijo']}sesion WHERE tipo = 'asistente'");
$otroTelefono->get('/carnet');
$quedan = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}dispositivo
                              WHERE selector = '$sel2'")->fetchColumn();
comprobar('un validador que no cuadra invalida el dispositivo entero', $quedan === 0, (string) $quedan);

/* =========================================================================
   Fotografía del carnet
   ========================================================================= */
titulo('Fotografía');

$html = $maria->get('/preregistro');
comprobar('«Mis datos» ofrece cargar la fotografía', str_contains($html, 'Fotografía del carnet'));
comprobar('el formulario admite archivos', str_contains($html, 'multipart/form-data'));
// accept="image/*" y NO la lista de tipos: con la lista, varios navegadores de
// Android esconden la opción de cámara y solo dejan el explorador de archivos.
// El tipo real lo comprueba el guion antes de subir y el servidor al recibir.
comprobar('el campo deja escoger entre cámara y galería',
    str_contains($html, 'accept="image/*"'));
comprobar('no fuerza la cámara con capture, que quitaría la galería',
    !str_contains($html, 'capture='));
comprobar('trae el editor para centrar y acercar',
    str_contains($html, 'data-foto-visor') && str_contains($html, 'data-foto-zoom'));
comprobar('y los campos del encuadre viajan con el formulario',
    str_contains($html, 'name="foto_lado"') && str_contains($html, 'name="foto_ancho"'));

/* =========================================================================
   Portada: el botón cambia con la fecha del evento
   ========================================================================= */
titulo('Portada');

$anonimo = new Cliente($BASE);

// Las fechas se escriben con el reloj de PHP y no con CURDATE(), que es el del
// servidor de base de datos. La aplicación fija la zona de su conexión (ver
// Bd::desplazamientoHorario), pero esta prueba usa la suya propia: entre las
// 7 de la tarde y la medianoche de Bogotá, CURDATE() ya está en el día
// siguiente y la comprobación fallaba sin que hubiera nada roto.
$hoy = date('Y-m-d');
$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . date('Y-m-d', strtotime('+30 day')) . "' WHERE numero = 1");
$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . date('Y-m-d', strtotime('+31 day')) . "' WHERE numero = 2");
$html = $anonimo->get('/');
comprobar('antes del evento el botón dice «Preregistrarme»', str_contains($html, '>Preregistrarme<'));
comprobar('y no dice «REGISTRARME»', !str_contains($html, '>REGISTRARME<'));
comprobar('«Entra con tu correo» es un botón y no un enlace suelto',
    str_contains($html, 'Entrar y ver mi carnet'));

$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '$hoy' WHERE numero = 1");
$html = $anonimo->get('/');
comprobar('el día del evento el botón dice «REGISTRARME»', str_contains($html, '>REGISTRARME<'));

/* =========================================================================
   Panel: ficha, contraseña, rol y jornadas
   ========================================================================= */
titulo('Ficha de una persona');

$idMaria = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                               WHERE correo = 'mzambrano@narino.gov.co'")->fetchColumn();

$html = $admin->get('/admin/registros');
comprobar('la tabla trae el botón de perfil',
    str_contains($html, 'Ver la ficha de María Fernanda Zambrano'));

$html = $admin->get('/admin/registros/' . $idMaria);
comprobar('la ficha se abre en su propia dirección', $admin->codigo === 200, (string) $admin->codigo);
comprobar('muestra los datos de la persona', str_contains($html, 'María Fernanda Zambrano'));
comprobar('dice claramente que la contraseña no se puede ver',
    str_contains($html, 'no se puede ver'));
comprobar('y explica por qué', str_contains($html, 'huella irreversible'));

$registrada = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}bitacora
                                  WHERE accion = 'ficha_consultada'")->fetchColumn();
comprobar('leer una ficha queda en la bitácora', $registrada >= 1, (string) $registrada);

$html = $admin->post('/admin/registros/clave', ['persona' => (string) $idMaria]);
preg_match('#letter-spacing:\.14em[^>]*>([A-Z2-9]{10})<#', $html, $m);
$claveNueva = $m[1] ?? '';
comprobar('genera una contraseña nueva y la enseña una vez',
    (bool) preg_match('/^[A-Z2-9]{10}$/', $claveNueva), $claveNueva);
comprobar('sin letras ni números que se confundan al dictarlos',
    !preg_match('/[IO01]/', $claveNueva), $claveNueva);

$hash = (string) $pdo->query("SELECT clave_hash FROM {$BD['prefijo']}persona WHERE id = $idMaria")->fetchColumn();
comprobar('en la base queda el hash, nunca la contraseña',
    $hash !== '' && !str_contains($hash, $claveNueva));
comprobar('y es Argon2id o bcrypt', str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'), substr($hash, 0, 12));

// Con la contraseña nueva se entra de verdad. Primero hay que encender el
// método: por omisión están correo y QR, no la contraseña.
$admin->get('/admin/autenticacion');
$admin->post('/admin/autenticacion', [
    'seccion' => 'metodos',
    'metodos' => ['correo', 'qr', 'clave'],
    'metodo_preferido' => 'correo',
]);
$pdo->exec("UPDATE {$BD['prefijo']}intento SET creado_en = DATE_SUB(NOW(), INTERVAL 2 DAY)");
$conClave = new Cliente($BASE);
$conClave->get('/entrar?metodo=clave');
$html = $conClave->post('/entrar', [
    'metodo' => 'clave',
    'correo' => 'mzambrano@narino.gov.co',
    'clave'  => $claveNueva,
]);
comprobar('la contraseña nueva sirve para entrar',
    str_contains($html, 'María Fernanda Zambrano'), substr(strip_tags($html), 0, 110));

titulo('Organizadores: rol y estado');

$idConsulta = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}usuario
                                  WHERE correo <> 'aerazo@narino.gov.co' LIMIT 1")->fetchColumn();
if ($idConsulta > 0) {
    $html = $admin->get('/admin/organizadores');
    comprobar('la tabla permite cambiar el rol', str_contains($html, '/admin/organizadores/rol'));

    $admin->post('/admin/organizadores/rol', ['usuario' => (string) $idConsulta, 'rol' => 'consulta']);
    $rol = (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}usuario WHERE id = $idConsulta")->fetchColumn();
    comprobar('el rol cambia de verdad', $rol === 'consulta', $rol);

    $admin->post('/admin/organizadores/rol', ['usuario' => (string) $idConsulta, 'rol' => 'operador']);
    comprobar('y se puede devolver',
        (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}usuario WHERE id = $idConsulta")->fetchColumn() === 'operador');
}

$idAdmin = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}usuario
                               WHERE correo = 'aerazo@narino.gov.co'")->fetchColumn();
$admin->post('/admin/organizadores/rol', ['usuario' => (string) $idAdmin, 'rol' => 'consulta']);
comprobar('nadie puede cambiar su propio rol',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}usuario WHERE id = $idAdmin")->fetchColumn() === 'administrador');

titulo('Jornadas: agregar y eliminar');

$antes = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn();
$html = $admin->get('/admin/qr-dias');
comprobar('la pantalla ofrece agregar un día', str_contains($html, 'Agregar un día'));

$admin->post('/admin/qr-dias/agregar', ['fecha' => date('Y-m-d', strtotime('+45 day'))]);
$despues = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn();
comprobar('se agrega la jornada', $despues === $antes + 1, "$antes → $despues");

$conteoEvento = (int) $pdo->query("SELECT jornadas FROM {$BD['prefijo']}evento WHERE activo = 1")->fetchColumn();
comprobar('el contador del evento queda cuadrado', $conteoEvento === $despues, "$conteoEvento vs $despues");

$nueva = $pdo->query("SELECT * FROM {$BD['prefijo']}evento_dia ORDER BY numero DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
comprobar('la jornada nueva trae su propio código', strlen((string) $nueva['token']) === 32);

$admin->post('/admin/qr-dias/agregar', ['fecha' => date('Y-m-d', strtotime('+45 day'))]);
comprobar('no deja repetir la fecha',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn() === $despues);

$admin->post('/admin/qr-dias/eliminar', ['numero' => (string) $nueva['numero']]);
comprobar('se elimina la jornada',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn() === $antes);

// La jornada 1 tiene ingresos de las pruebas anteriores: no se puede borrar.
$conIngresos = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn();
if ($conIngresos > 0) {
    $numeroConIngreso = (int) $pdo->query(
        "SELECT d.numero FROM {$BD['prefijo']}evento_dia d
           JOIN {$BD['prefijo']}asistencia a ON a.evento_dia_id = d.id LIMIT 1"
    )->fetchColumn();
    $html = $admin->post('/admin/qr-dias/eliminar', ['numero' => (string) $numeroConIngreso]);
    comprobar('no se borra una jornada con ingresos registrados',
        (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn() === $antes);
}

titulo('Autenticación por pestañas');

$html = $admin->get('/admin/autenticacion');
comprobar('la pantalla se organiza en pestañas', str_contains($html, 'data-pestanas'));
foreach (['panel-metodos', 'panel-correo', 'panel-qr', 'panel-clave', 'panel-whatsapp', 'panel-sms'] as $panelId) {
    comprobar('existe el panel ' . $panelId, str_contains($html, 'id="' . $panelId . '"'));
}

// Guardar una pestaña no puede borrar lo de las otras: es el fallo clásico al
// partir un formulario largo en varios.
$admin->post('/admin/autenticacion', [
    'seccion' => 'whatsapp', 'wa_proveedor' => 'meta',
    'wa_cuenta' => '1234567890', 'wa_remitente' => '',
]);
$admin->post('/admin/autenticacion', [
    'seccion' => 'sms', 'sms_proveedor' => 'twilio',
    'sms_cuenta' => 'ACprueba', 'sms_remitente' => '+573001112233',
]);
$config = leerConfig($RAIZ);
comprobar('guardar SMS no borró lo de WhatsApp',
    ($config['wa_cuenta'] ?? '') === '1234567890', (string) ($config['wa_cuenta'] ?? 'vacío'));
comprobar('y los métodos activos siguen ahí',
    !empty($config['auth_metodos']), json_encode($config['auth_metodos'] ?? null));

$admin->post('/admin/autenticacion', ['seccion' => 'metodos', 'metodos' => []]);
comprobar('no se pueden apagar todos los métodos',
    !empty(leerConfig($RAIZ)['auth_metodos']));

/* =========================================================================
   El registro: nombre, fase plegada y siempre abierto
   ========================================================================= */
titulo('Formulario de registro');

$visitante = new Cliente($BASE);
$html = $visitante->get('/registro');
comprobar('/registro responde', $visitante->codigo === 200, (string) $visitante->codigo);
comprobar('se llama «Registro», no «preregistro»',
    str_contains($html, 'Formulario de registro') && !str_contains($html, 'Formulario de preregistro'));

$viejo = new Cliente($BASE);
$viejo->get('/preregistro');
comprobar('la dirección anterior sigue funcionando: hay correos con ella',
    $viejo->codigo === 200, (string) $viejo->codigo);

// La fase 02 arranca plegada. Lo que se comprueba es el HTML que manda el
// servidor, que es lo que decide: el guion solo alterna a partir de ahí.
comprobar('la caracterización arranca plegada',
    (bool) preg_match('/id="bloque-opcional"[^>]*class="[^"]*hidden/', $html)
    || (bool) preg_match('/class="[^"]*hidden[^"]*"[^>]*id="bloque-opcional"/', $html),
    'no se encontró la clase hidden en el bloque');
comprobar('y su botón dice «Mostrar»',
    (bool) preg_match('/data-plegar="bloque-opcional"[^>]*aria-expanded="false"/s', $html)
    && str_contains($html, 'Mostrar'));

// Un error dentro de esa fase la abre sola: si no, nadie encuentra por qué no
// se guardó.
$conError = new Cliente($BASE);
$conError->get('/registro');
$html = $conError->post('/registro', [
    'correo' => 'municipio.malo@narino.gov.co', 'nombre' => 'Persona De Prueba',
    'tipo_documento' => 'CC', 'documento' => '98765432',
    'departamento' => 'Nariño', 'municipio' => 'Ciudad Que No Existe',
    'habeas' => '1',
]);
comprobar('un error en la caracterización la deja abierta',
    (bool) preg_match('/data-plegar="bloque-opcional"[^>]*aria-expanded="true"/s', $html));

/* =========================================================================
   Crear el acceso con correo y contraseña
   ========================================================================= */
titulo('Acceso con correo y contraseña');

$html = $visitante->get('/entrar');
comprobar('la pantalla de ingreso ofrece crear el acceso',
    str_contains($html, '/entrar/crear'));

$nuevo = new Cliente($BASE);
$html = $nuevo->get('/entrar/crear');
comprobar('la pantalla de crear acceso responde', $nuevo->codigo === 200, (string) $nuevo->codigo);

$html = $nuevo->post('/entrar/crear', [
    'correo' => 'rapida@narino.gov.co', 'clave' => 'corta', 'clave2' => 'corta', 'habeas' => '1',
]);
comprobar('exige el largo mínimo de la contraseña', str_contains($html, 'al menos'));

$html = $nuevo->post('/entrar/crear', [
    'correo' => 'rapida@narino.gov.co', 'clave' => 'clave-de-prueba', 'clave2' => 'otra-distinta',
    'habeas' => '1',
]);
comprobar('exige que las dos contraseñas coincidan', str_contains($html, 'no coinciden'));

$html = $nuevo->post('/entrar/crear', [
    'correo' => 'rapida@narino.gov.co', 'clave' => 'clave-de-prueba', 'clave2' => 'clave-de-prueba',
]);
comprobar('exige la autorización de tratamiento de datos',
    str_contains($html, 'autorizar el tratamiento'));

$html = $nuevo->post('/entrar/crear', [
    'correo' => 'rapida@narino.gov.co', 'clave' => 'clave-de-prueba', 'clave2' => 'clave-de-prueba',
    'habeas' => '1',
]);
comprobar('con todo correcto queda dentro y se le pide completar',
    str_contains($html, 'Completa tu registro'), substr(strip_tags($html), 0, 120));

$rapida = $pdo->query("SELECT * FROM {$BD['prefijo']}persona
                        WHERE correo = 'rapida@narino.gov.co'")->fetch(PDO::FETCH_ASSOC);
comprobar('la persona existe con el correo', is_array($rapida));
comprobar('sin documento, que es lo que permite el registro a medias',
    $rapida['documento_huella'] === null && $rapida['documento_cifrado'] === null,
    json_encode([$rapida['documento_huella'], $rapida['documento_cifrado']]));
comprobar('con la contraseña guardada como hash, nunca en claro',
    $rapida['clave_hash'] !== '' && !str_contains((string) $rapida['clave_hash'], 'clave-de-prueba'));
comprobar('y quedó la autorización de datos con su fecha', !empty($rapida['autorizo_datos_en']));

comprobar('todavía no tiene carnet: no hay nada que poner en él',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}credencial
                        WHERE persona_id = {$rapida['id']}")->fetchColumn() === 0);

// Lo que hay detrás del guardia de asistente lleva a completar, no a una
// pantalla vacía.
$nuevo->get('/carnet', false);
comprobar('el carnet redirige a completar el registro',
    $nuevo->codigo === 303 && str_contains($nuevo->cabecera('Location'), '/registro'),
    $nuevo->codigo . ' → ' . $nuevo->cabecera('Location'));

$nuevo->get('/checkin', false);
comprobar('el check-in también', $nuevo->codigo === 303);

// El mismo correo no se puede crear dos veces.
$otroMas = new Cliente($BASE);
$otroMas->get('/entrar/crear');
$html = $otroMas->post('/entrar/crear', [
    'correo' => 'rapida@narino.gov.co', 'clave' => 'clave-de-prueba', 'clave2' => 'clave-de-prueba',
    'habeas' => '1',
]);
comprobar('un correo ya usado no crea otra cuenta', str_contains($html, 'ya tiene acceso'));

// Y al completar el formulario sí se emite el carnet.
$nuevo->get('/registro');
$html = $nuevo->post('/registro', [
    'nombre' => 'Registro Rápido Nariño', 'tipo_documento' => 'CC', 'documento' => '77712345',
    'rol' => 'participante', 'habeas' => '1',
]);
comprobar('al completar los datos sale el carnet', str_contains($html, 'Tu carnet digital'),
    substr(strip_tags($html), 0, 120));
comprobar('y ahora sí tiene credencial',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}credencial
                        WHERE persona_id = {$rapida['id']}")->fetchColumn() === 1);

// Entrar con esa contraseña funciona de verdad.
$pdo->exec("UPDATE {$BD['prefijo']}intento SET creado_en = DATE_SUB(NOW(), INTERVAL 2 DAY)");
$vuelve = new Cliente($BASE);
$vuelve->get('/entrar?metodo=clave');
$html = $vuelve->post('/entrar', [
    'metodo' => 'clave', 'correo' => 'rapida@narino.gov.co', 'clave' => 'clave-de-prueba',
]);
comprobar('la contraseña elegida sirve para entrar',
    str_contains($html, 'Registro Rápido Nariño'), substr(strip_tags($html), 0, 110));

$html = $admin->get('/admin/registros');
comprobar('el panel lista al que entró por la puerta corta',
    str_contains($html, 'Registro Rápido Nariño'));

/* =========================================================================
   Eventos: editar, desactivar y eliminar
   ========================================================================= */
titulo('Eventos');

$idEvento = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}evento WHERE activo = 1")->fetchColumn();

$html = $admin->get('/admin/eventos');
comprobar('la pantalla ofrece editar', str_contains($html, '/admin/eventos/editar'));
comprobar('y eliminar', str_contains($html, '/admin/eventos/eliminar'));
comprobar('y dice cuántos registros se perderían',
    str_contains($html, 'Personas registradas'));

$fechaVieja = (string) $pdo->query("SELECT fecha_inicio FROM {$BD['prefijo']}evento
                                     WHERE id = $idEvento")->fetchColumn();
$diaUnoViejo = (string) $pdo->query("SELECT fecha FROM {$BD['prefijo']}evento_dia
                                      WHERE evento_id = $idEvento ORDER BY numero LIMIT 1")->fetchColumn();

$admin->post('/admin/eventos/editar', [
    'evento' => (string) $idEvento,
    'nombre' => 'Cumbre Tecnológica CIOS Nariño · corregida',
    'dependencia' => 'Secretaría TIC', 'sede' => 'Centro de Convenciones',
    'fecha_inicio' => $fechaVieja, 'estado' => 'en_curso',
]);
$ev = $pdo->query("SELECT * FROM {$BD['prefijo']}evento WHERE id = $idEvento")->fetch(PDO::FETCH_ASSOC);
comprobar('el nombre se corrige', str_contains((string) $ev['nombre'], 'corregida'), (string) $ev['nombre']);
comprobar('y el estado también', $ev['estado'] === 'en_curso', (string) $ev['estado']);
comprobar('sin mover las jornadas si no se pidió',
    (string) $pdo->query("SELECT fecha FROM {$BD['prefijo']}evento_dia
                           WHERE evento_id = $idEvento ORDER BY numero LIMIT 1")->fetchColumn() === $diaUnoViejo);

// Ahora sí, moviendo las jornadas con la fecha.
$nuevaFecha = date('Y-m-d', strtotime($fechaVieja . ' +7 day'));
$admin->post('/admin/eventos/editar', [
    'evento' => (string) $idEvento,
    'nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'dependencia' => 'Secretaría TIC', 'sede' => 'Centro de Convenciones',
    'fecha_inicio' => $nuevaFecha, 'estado' => 'en_curso', 'mover_jornadas' => '1',
]);
comprobar('con la casilla marcada, las jornadas se mueven los mismos días',
    (string) $pdo->query("SELECT fecha FROM {$BD['prefijo']}evento_dia
                           WHERE evento_id = $idEvento ORDER BY numero LIMIT 1")->fetchColumn()
    === date('Y-m-d', strtotime($diaUnoViejo . ' +7 day')));

// Desactivar y volver a activar.
$admin->post('/admin/eventos/desactivar', ['evento' => (string) $idEvento]);
comprobar('desactivar apaga el evento',
    (int) $pdo->query("SELECT activo FROM {$BD['prefijo']}evento WHERE id = $idEvento")->fetchColumn() === 0);

$sinEvento = new Cliente($BASE);
$sinEvento->get('/');
comprobar('sin evento activo la portada no se rompe',
    in_array($sinEvento->codigo, [200, 503], true), (string) $sinEvento->codigo);

$admin->post('/admin/eventos/activar', ['evento' => (string) $idEvento]);
comprobar('y se vuelve a activar',
    (int) $pdo->query("SELECT activo FROM {$BD['prefijo']}evento WHERE id = $idEvento")->fetchColumn() === 1);

// Eliminar: primero uno de prueba, con datos dentro.
$admin->get('/admin/eventos');
$admin->post('/admin/eventos/crear', [
    'nombre' => 'Evento de prueba para borrar', 'dependencia' => 'TIC', 'sede' => 'Ninguna',
    'fecha_inicio' => date('Y-m-d', strtotime('+90 day')), 'jornadas' => '2',
]);
$idPrueba = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}evento
                                WHERE nombre = 'Evento de prueba para borrar'")->fetchColumn();
comprobar('se crea el evento de prueba', $idPrueba > 0);

$pdo->exec("INSERT INTO {$BD['prefijo']}persona
              (evento_id, nombre, correo, documento_cifrado, documento_huella, autorizo_datos_en)
            VALUES ($idPrueba, 'Alguien De Prueba', 'prueba.borrar@narino.gov.co',
                    " . $pdo->quote('cifrado-falso') . ", " . $pdo->quote(str_repeat('a', 64)) . ", NOW())");
$idPersonaPrueba = (int) $pdo->lastInsertId();

// Con una propuesta y un archivo en el disco: una hoja de vida trae teléfono,
// dirección y trayectoria laboral, así que no puede quedarse ahí cuando se
// borra el evento al que pertenecía.
$carpetaBorrado = dirname(__DIR__) . '/almacen/documentos';
@mkdir($carpetaBorrado, 0750, true);
$archivoHuerfano = 'pr0-hv-' . bin2hex(random_bytes(8)) . '.pdf';
file_put_contents($carpetaBorrado . '/' . $archivoHuerfano, "%PDF-1.4\n%%EOF\n");
$pdo->exec("INSERT INTO {$BD['prefijo']}propuesta
              (persona_id, titulo, categoria, detalle, hoja_vida, hoja_vida_tipo)
            VALUES ($idPersonaPrueba, 'Propuesta de prueba', 'Gobierno digital', 'Detalle',
                    " . $pdo->quote($archivoHuerfano) . ", 'application/pdf')");

$admin->post('/admin/eventos/eliminar', ['evento' => (string) $idPrueba, 'confirmacion' => 'quizá']);
comprobar('sin escribir ELIMINAR no se borra nada',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento WHERE id = $idPrueba")->fetchColumn() === 1);

$admin->post('/admin/eventos/eliminar', ['evento' => (string) $idPrueba, 'confirmacion' => 'ELIMINAR']);
comprobar('escribiéndolo sí se borra',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento WHERE id = $idPrueba")->fetchColumn() === 0);
comprobar('y se lleva sus jornadas',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                        WHERE evento_id = $idPrueba")->fetchColumn() === 0);
comprobar('y sus personas',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE evento_id = $idPrueba")->fetchColumn() === 0);
comprobar('y sus propuestas',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}propuesta
                        WHERE persona_id = $idPersonaPrueba")->fetchColumn() === 0);
comprobar('los adjuntos de sus expositores no se quedan en el disco',
    !is_file($carpetaBorrado . '/' . $archivoHuerfano));
comprobar('el evento activo sigue intacto',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento WHERE id = $idEvento")->fetchColumn() === 1);

/* =========================================================================
   Jornadas con ingresos: ahora se pueden eliminar
   ========================================================================= */
titulo('Eliminar una jornada con ingresos');

$conIngresos = $pdo->query(
    "SELECT d.numero, d.id, COUNT(a.id) AS n
       FROM {$BD['prefijo']}evento_dia d
       JOIN {$BD['prefijo']}asistencia a ON a.evento_dia_id = d.id
      WHERE d.evento_id = $idEvento
   GROUP BY d.id ORDER BY n DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$conIngresos) {
    comprobar('hay una jornada con ingresos para la prueba', false, 'ninguna tiene');
} else {
    $numero = (int) $conIngresos['numero'];
    $antes = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                                 WHERE evento_id = $idEvento")->fetchColumn();

    $admin->get('/admin/qr-dias');
    $admin->post('/admin/qr-dias/eliminar', ['numero' => (string) $numero]);
    comprobar('sin confirmar, una jornada con ingresos no se elimina',
        (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                            WHERE evento_id = $idEvento")->fetchColumn() === $antes);

    $admin->post('/admin/qr-dias/eliminar', ['numero' => (string) $numero, 'forzar' => '1']);
    comprobar('confirmando sí, y con sus ingresos',
        (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                            WHERE evento_id = $idEvento")->fetchColumn() === $antes - 1);
    comprobar('las asistencias de ese día se fueron con él',
        (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia
                            WHERE evento_dia_id = {$conIngresos['id']}")->fetchColumn() === 0);
    comprobar('el contador de jornadas del evento queda cuadrado',
        (int) $pdo->query("SELECT jornadas FROM {$BD['prefijo']}evento
                            WHERE id = $idEvento")->fetchColumn() === $antes - 1);
}

// La última jornada no se puede quitar ni forzando: un evento sin días no
// tiene dónde registrar un ingreso.
$pdo->exec("DELETE FROM {$BD['prefijo']}evento_dia
             WHERE evento_id = $idEvento AND numero <> (
               SELECT n FROM (SELECT MIN(numero) AS n FROM {$BD['prefijo']}evento_dia
                               WHERE evento_id = $idEvento) t)");
$ultima = (int) $pdo->query("SELECT numero FROM {$BD['prefijo']}evento_dia
                              WHERE evento_id = $idEvento LIMIT 1")->fetchColumn();
$admin->get('/admin/qr-dias');
$admin->post('/admin/qr-dias/eliminar', ['numero' => (string) $ultima, 'forzar' => '1']);
comprobar('la única jornada que queda no se puede eliminar',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                        WHERE evento_id = $idEvento")->fetchColumn() === 1);

// Se devuelve el evento a tres jornadas. Este bloque es destructivo por
// definición, y lo que deja montado lo usan después la prueba de navegador y
// quien mire la instalación a mano: dejarla con un solo día sería dejarla
// distinta de como se encuentra un evento de verdad.
$admin->get('/admin/qr-dias');
foreach ([1, 2] as $mas) {
    $admin->post('/admin/qr-dias/agregar', [
        'fecha' => date('Y-m-d', strtotime('+' . $mas . ' day')),
    ]);
}
comprobar('el evento vuelve a tener tres jornadas para lo que sigue',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia
                        WHERE evento_id = $idEvento")->fetchColumn() === 3);

/* =========================================================================
   Al eliminar un día, los siguientes se renumeran
   -------------------------------------------------------------------------
   Si se elimina el día 1, el 2 pasa a ser el 1 y el 3 pasa a ser el 2. Cada
   día conserva su fecha, su código y lo que cuelga de él; el día preferido de
   las propuestas, que es un número, se traduce con su día.
   ========================================================================= */
titulo('Renumerar al eliminar un día');

$PF = $BD['prefijo'];
$diasDel = static fn(): array => $pdo->query(
    "SELECT id, numero, fecha, token FROM {$PF}evento_dia WHERE evento_id = $idEvento ORDER BY numero"
)->fetchAll(PDO::FETCH_ASSOC);
$numerosDe = static fn(array $dias): array => array_map('intval', array_column($dias, 'numero'));

$antes = $diasDel();
comprobar('se parte de tres días seguidos', $numerosDe($antes) === [1, 2, 3], implode(',', $numerosDe($antes)));
[$d1, $d2, $d3] = $antes;

// Tres propuestas: una pide el día que se va a eliminar, otra el último, y la
// tercera ya está agendada en el último.
$duenia = (int) $pdo->query("SELECT id FROM {$PF}persona WHERE evento_id = $idEvento ORDER BY id LIMIT 1")->fetchColumn();
$nuevaPropuesta = static function (string $titulo, int $dia, string $estado = 'pendiente') use ($pdo, $PF, $duenia): int {
    $pdo->prepare("INSERT INTO {$PF}propuesta (persona_id, titulo, categoria, detalle, dia_preferido, estado)
                   VALUES (?, ?, 'Gobierno digital', 'Propuesta de la prueba de renumeración, larga a propósito.', ?, ?)")
        ->execute([$duenia, $titulo, $dia, $estado]);
    return (int) $pdo->lastInsertId();
};
$pideElPrimero = $nuevaPropuesta('Renumerar: pide el día que se elimina', 1);
$pideElUltimo = $nuevaPropuesta('Renumerar: pide el último', 3);
$agendada = $nuevaPropuesta('Renumerar: agendada en el último', 3, 'aprobada');
$pdo->exec("INSERT INTO {$PF}charla (propuesta_id, evento_dia_id, hora_inicio, salon) VALUES ($agendada, {$d3['id']}, '10:00:00', 'Sala Renumeración')");
$preferido = static fn(int $id): int => (int) $pdo->query("SELECT dia_preferido FROM {$PF}propuesta WHERE id = $id")->fetchColumn();

$html = $admin->get('/admin/qr-dias');
comprobar('la confirmación dice qué día pasa a cuál antes de eliminar',
    str_contains($html, 'Los siguientes se renumeran: el día 2 pasa a ser el 1 y el 3 pasa a ser el 2'));
comprobar('y cada botón lleva la identidad de su día', str_contains($html, 'name="jornada" value="' . $d1['id'] . '"'));

$html = $admin->post('/admin/qr-dias/eliminar', ['numero' => '1', 'jornada' => (string) $d1['id'], 'forzar' => '1']);
$despues = $diasDel();
comprobar('al eliminar el día 1, los demás quedan como 1 y 2', $numerosDe($despues) === [1, 2], implode(',', $numerosDe($despues)));
comprobar('el 2 pasó a ser el 1 y el 3 el 2, con sus fechas y sus códigos',
    $despues[0]['id'] === $d2['id'] && $despues[0]['fecha'] === $d2['fecha'] && $despues[0]['token'] === $d2['token']
    && $despues[1]['id'] === $d3['id'] && $despues[1]['fecha'] === $d3['fecha'] && $despues[1]['token'] === $d3['token']);
comprobar('el mensaje lo dice', str_contains($html, 'Los siguientes se renumeraron: el día 2 pasa a ser el 1 y el 3 pasa a ser el 2'));
comprobar('quien pidió el último sigue pidiendo esa fecha, que ahora es el día 2', $preferido($pideElUltimo) === 2,
    (string) $preferido($pideElUltimo));
comprobar('quien pidió el día eliminado queda sin día preferido', $preferido($pideElPrimero) === 0,
    (string) $preferido($pideElPrimero));
comprobar('la charla agendada sigue en su misma fecha',
    (int) $pdo->query("SELECT evento_dia_id FROM {$PF}charla WHERE propuesta_id = $agendada")->fetchColumn() === (int) $d3['id']);
$bitacora = (string) $pdo->query("SELECT detalle FROM {$PF}bitacora WHERE accion = 'jornada_eliminada' ORDER BY id DESC LIMIT 1")->fetchColumn();
comprobar('la bitácora anota la renumeración', str_contains($bitacora, 'pasa a ser'), $bitacora);

$html = $admin->get('/admin/expositores');
comprobar('la lista de propuestas muestra el día asignado, con su número nuevo', str_contains($html, 'Día 2 · 10:00'));
comprobar('y la que pidió el día eliminado dice que no tiene', str_contains($html, 'Sin día preferido'));

// Una pantalla abierta antes de eliminar todavía dice «día 1» para la fecha
// que ya no existe. Su botón no puede llevarse por delante el día 1 de ahora.
$html = $admin->post('/admin/qr-dias/eliminar', ['numero' => '1', 'jornada' => (string) $d1['id'], 'forzar' => '1']);
comprobar('un formulario de antes de renumerar no elimina otro día',
    count($diasDel()) === 2 && str_contains($html, 'cambiaron de número'), substr(strip_tags($html), 0, 200));
$html = $admin->post('/admin/qr-dias/ajustar', [
    'numero' => '2', 'jornada' => (string) $d2['id'], 'fecha' => date('Y-m-d', strtotime('+200 day')),
]);
comprobar('ni le cambia la fecha a otro', $diasDel()[1]['fecha'] === $d3['fecha'] && str_contains($html, 'cambiaron de número'));

// Aprobar elige la jornada por su identidad: si se eliminó mientras se
// revisaba, no se aprueba en otra.
$html = $admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $pideElUltimo, 'decision' => 'aprobada', 'jornada' => (string) $d1['id'], 'hora' => '11:00',
]);
comprobar('aprobar en un día que ya no existe no aprueba nada',
    (string) $pdo->query("SELECT estado FROM {$PF}propuesta WHERE id = $pideElUltimo")->fetchColumn() === 'pendiente'
    && str_contains($html, 'ya no existe'), substr(strip_tags($html), 0, 200));
$admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $pideElUltimo, 'decision' => 'aprobada', 'jornada' => (string) $d3['id'], 'hora' => '11:00',
]);
comprobar('y con su identidad se agenda en esa fecha, aunque su número haya cambiado',
    (int) $pdo->query("SELECT evento_dia_id FROM {$PF}charla WHERE propuesta_id = $pideElUltimo")->fetchColumn() === (int) $d3['id']);

// Un evento que ya traía huecos de una versión anterior: se avisa y se ofrece
// dejarlos seguidos.
$pdo->exec("UPDATE {$PF}evento_dia SET numero = numero + 1 WHERE evento_id = $idEvento ORDER BY numero DESC");
$html = $admin->get('/admin/qr-dias');
comprobar('con huecos, la pantalla lo avisa y ofrece dejarlos seguidos',
    str_contains($html, 'no van seguidos') && str_contains($html, 'Dejarlos seguidos')
    && str_contains($html, 'el día 2 pasa a ser el 1 y el 3 pasa a ser el 2'));
$html = $admin->post('/admin/qr-dias/renumerar', []);
comprobar('y el botón los deja seguidos', $numerosDe($diasDel()) === [1, 2] && str_contains($html, 'Los códigos QR no cambiaron'));
comprobar('sin que cambien los códigos', array_column($diasDel(), 'token') === [$d2['token'], $d3['token']]);
comprobar('ya no hay aviso', !str_contains($admin->get('/admin/qr-dias'), 'no van seguidos'));
comprobar('queda en la bitácora',
    (int) $pdo->query("SELECT COUNT(*) FROM {$PF}bitacora WHERE accion = 'jornadas_renumeradas'")->fetchColumn() === 1);

// Dejarlo como estaba: tres días y sin las propuestas de la prueba.
$pdo->exec("DELETE FROM {$PF}propuesta WHERE id IN ($pideElPrimero, $pideElUltimo, $agendada)");
$admin->get('/admin/qr-dias');
$admin->post('/admin/qr-dias/agregar', ['fecha' => date('Y-m-d', strtotime($d3['fecha'] . ' +1 day'))]);
comprobar('el evento vuelve a tener tres días seguidos', $numerosDe($diasDel()) === [1, 2, 3], implode(',', $numerosDe($diasDel())));

/* =========================================================================
   Los adjuntos del expositor
   -------------------------------------------------------------------------
   Se prueba con subidas multipart de verdad y no llamando a las clases: lo que
   importa aquí es justamente el camino completo —$_FILES, is_uploaded_file(),
   el guardia de la descarga y las cabeceras— y eso no aparece de otra forma.
   ========================================================================= */
titulo('Adjuntos del expositor');

$carpetaDocs = dirname(__DIR__) . '/almacen/documentos';

$html = (new Cliente($BASE))->get('/registro');
comprobar('el formulario ofrece subir la hoja de vida',
    str_contains($html, 'name="hoja_vida"'));
comprobar('y la exposición', str_contains($html, 'name="exposicion"'));
comprobar('la hoja de vida se pide en PDF',
    (bool) preg_match('/Hoja de vida.{0,200}\(PDF, máximo 8 MB\)/su', $html));
comprobar('la exposición admite PDF o PPTX',
    (bool) preg_match('/Exposición.{0,200}\(PDF o PPTX, máximo 25 MB\)/su', $html));
comprobar('el campo de exposición acepta .pptx',
    str_contains($html, 'presentationml.presentation'));
comprobar('se dice que los dos son obligatorios',
    str_contains($html, 'Los dos son obligatorios para exponer'));
comprobar('y cuál es la salida de quien no los tiene a mano',
    str_contains($html, 'sin marcar «voy a exponer»'));

// Sin JavaScript no puede haber «required» puesto en el HTML: el bloque del
// expositor llega oculto, y un campo obligatorio dentro de algo oculto hace que
// el navegador se niegue a enviar el formulario sin poder decir por qué. La
// regla la pone el guion al marcar la casilla, y el servidor siempre.
comprobar('los campos no llevan required de fábrica',
    !preg_match('/name="hoja_vida"[^>]*\srequired/', $html));
comprobar('pero sí la marca que el guion usa para ponerlo',
    str_contains($html, 'data-exige-expositor'));

/* ---- Una expositora nueva: primero sin archivos, luego con ellos ---- */
$lucia = new Cliente($BASE);
$lucia->get('/registro');
$datosLucia = [
    'correo' => 'lvillota@narino.gov.co', 'nombre' => 'Lucía Villota Erazo',
    'tipo_documento' => 'CC', 'documento' => '27998144',
    'rol' => 'expositor', 'entidad' => 'Universidad de Nariño',
    'expositor' => '1', 'tema' => 'Inteligencia artificial en el aula rural',
    'categoria' => 'Emprendimiento y startups TIC',
    'detalle' => 'Resultados de dos años enseñando programación en sedes rurales de Nariño.',
    'dia_preferido' => '1', 'duracion' => '40', 'habeas' => '1',
];

$html = $lucia->subir('/registro', $datosLucia, []);
comprobar('sin ningún archivo se piden los dos',
    str_contains($html, 'Adjunta tu hoja de vida')
    && str_contains($html, 'Adjunta tu exposición'));

// Solo uno: el que falta se pide y el que vino no se da por bueno, porque el
// envío entero se rechaza y el navegador vacía los dos campos.
$lucia->get('/registro');
$html = $lucia->subir('/registro', $datosLucia, [
    'hoja_vida' => ['nombre' => 'hv.pdf', 'tipo' => 'application/pdf',
                    'contenido' => $pdfDePrueba('SOLO-UNA')],
]);
comprobar('con solo uno se pide el que falta',
    str_contains($html, 'Adjunta tu exposición') && !str_contains($html, 'Adjunta tu hoja de vida'));
comprobar('y se avisa de que hay que volver a elegir los archivos',
    str_contains($html, 'Vuelve a elegir los archivos'));
comprobar('nada de eso creó un registro a medias',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE correo = 'lvillota@narino.gov.co'")->fetchColumn() === 0);

// Un archivo que no es lo que dice ser falla con los demás campos, antes de
// guardar nada: es un campo obligatorio, no un adorno que se avise después.
$lucia->get('/registro');
$html = $lucia->subir('/registro', $datosLucia, [
    'hoja_vida'  => ['nombre' => 'hv.pdf', 'tipo' => 'application/pdf',
                     'contenido' => "<?php system(\$_GET['c']); ?>\n"],
    'exposicion' => ['nombre' => 'charla.pptx',
                     'tipo' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                     'contenido' => $pptxDePrueba('DA-IGUAL')],
]);
comprobar('un archivo que no es lo que dice frena el envío',
    str_contains($html, 'no es PDF'), substr(strip_tags($html), 0, 160));
comprobar('y tampoco guarda el registro',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE correo = 'lvillota@narino.gov.co'")->fetchColumn() === 0);

// Quien no va a exponer no tiene que adjuntar nada.
$sinExponer = new Cliente($BASE);
$sinExponer->get('/registro');
$html = $sinExponer->subir('/registro', [
    'correo' => 'tsolarte@narino.gov.co', 'nombre' => 'Tomás Solarte Caicedo',
    'tipo_documento' => 'CC', 'documento' => '13088477', 'habeas' => '1',
], []);
comprobar('quien no expone se registra sin adjuntar nada',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE correo = 'tsolarte@narino.gov.co'")->fetchColumn() === 1);

$lucia->get('/registro');
$lucia->subir('/registro', $datosLucia, [
    'hoja_vida'  => ['nombre' => 'hv.pdf', 'tipo' => 'application/pdf',
                     'contenido' => $pdfDePrueba('HOJA-DE-VIDA-DE-LUCIA')],
    'exposicion' => ['nombre' => 'charla.pptx',
                     'tipo' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                     'contenido' => $pptxDePrueba('CHARLA-DE-LUCIA')],
]);

$pr = $pdo->query("SELECT pr.* FROM {$BD['prefijo']}propuesta pr
                     JOIN {$BD['prefijo']}persona p ON p.id = pr.persona_id
                    WHERE p.correo = 'lvillota@narino.gov.co'")->fetch(PDO::FETCH_ASSOC);
comprobar('se guarda la propuesta con sus adjuntos', is_array($pr));

$idPr = (int) ($pr['id'] ?? 0);
comprobar('la hoja de vida queda como PDF',
    str_ends_with((string) ($pr['hoja_vida'] ?? ''), '.pdf'), (string) ($pr['hoja_vida'] ?? '—'));
comprobar('la exposición queda como PPTX',
    str_ends_with((string) ($pr['exposicion'] ?? ''), '.pptx'), (string) ($pr['exposicion'] ?? '—'));
comprobar('el tipo guardado es el real y no el que declaró el navegador',
    ($pr['hoja_vida_tipo'] ?? '') === 'application/pdf'
    && str_contains((string) ($pr['exposicion_tipo'] ?? ''), 'presentationml.presentation'));
comprobar('el nombre del archivo lo puso el servidor, con azar',
    (bool) preg_match('/^pr' . $idPr . '-hv-[a-f0-9]{16}\.pdf$/', (string) ($pr['hoja_vida'] ?? '')));
comprobar('los dos archivos están en el disco',
    is_file($carpetaDocs . '/' . $pr['hoja_vida']) && is_file($carpetaDocs . '/' . $pr['exposicion']));

/* ---- Quién puede bajarlos ---- */
$lucia->get('/medios/documento/' . $idPr . '/hoja-de-vida');
comprobar('su dueña puede bajar su hoja de vida', $lucia->codigo === 200, (string) $lucia->codigo);
comprobar('y llega el PDF completo', str_contains($lucia->cuerpo, 'HOJA-DE-VIDA-DE-LUCIA'));
comprobar('sale como descarga, no incrustada',
    str_contains($lucia->cabecera('Content-Disposition'), 'attachment'),
    $lucia->cabecera('Content-Disposition'));
comprobar('con un nombre legible en vez del del disco',
    str_contains($lucia->cabecera('Content-Disposition'), 'Hoja-de-vida-Lucia-Villota-Erazo.pdf'),
    $lucia->cabecera('Content-Disposition'));
comprobar('y con el tipo declarado por el servidor',
    str_contains($lucia->cabecera('Content-Type'), 'application/pdf'));
comprobar('sin dejar que el navegador adivine el tipo',
    str_contains($lucia->cabecera('X-Content-Type-Options'), 'nosniff'));

$lucia->get('/medios/documento/' . $idPr . '/exposicion');
comprobar('también baja su exposición', $lucia->codigo === 200);
comprobar('con el nombre y la extensión que le toca',
    str_contains($lucia->cabecera('Content-Disposition'), 'Exposicion-Lucia-Villota-Erazo.pptx'),
    $lucia->cabecera('Content-Disposition'));

// El número de una propuesta es correlativo: sin el guardia, contar desde uno
// bastaría para bajarse las hojas de vida de todos los expositores.
$curioso = new Cliente($BASE);
$curioso->get('/medios/documento/' . $idPr . '/hoja-de-vida', false);
comprobar('un desconocido no baja la hoja de vida de nadie',
    $curioso->codigo === 404, (string) $curioso->codigo);

$carlos->get('/medios/documento/' . $idPr . '/hoja-de-vida', false);
comprobar('ni otro asistente con sesión abierta',
    $carlos->codigo === 404, (string) $carlos->codigo);

$admin->get('/admin');
$admin->get('/medios/documento/' . $idPr . '/hoja-de-vida');
comprobar('el equipo que revisa las propuestas sí puede', $admin->codigo === 200,
    (string) $admin->codigo);

// La ranura se interpola en el SELECT, así que solo puede nombrar una de las
// dos columnas. Cualquier otra cosa es un 404 antes de tocar la base.
foreach (['estado', 'detalle', 'titulo', 'hoja-vida'] as $inventada) {
    $admin->get('/medios/documento/' . $idPr . '/' . $inventada, false);
    comprobar('la ranura «' . $inventada . '» no sirve para leer otra columna',
        $admin->codigo === 404, (string) $admin->codigo);
}

$admin->get('/medios/documento/999999/hoja-de-vida', false);
comprobar('una propuesta que no existe da 404', $admin->codigo === 404);

/* ---- Ya registrada: lo que pasa al volver al formulario ---- */
$antesHv = (string) $pr['hoja_vida'];
// Van todos los campos, no solo los que cambian: el formulario los manda
// todos, y uno que falte se guarda vacío. Es lo que hace el navegador.
$sinAdjuntar = [
    'nombre' => 'Lucía Villota Erazo', 'tipo_documento' => 'CC', 'documento' => '27998144',
    'telefono' => '+57 318 440 2211', 'entidad' => 'Universidad de Nariño',
    'rol' => 'expositor', 'expositor' => '1',
    'tema' => 'Inteligencia artificial en el aula rural',
    'categoria' => 'Emprendimiento y startups TIC',
    'detalle' => 'Resultados de dos años enseñando programación en sedes rurales de Nariño.',
    'dia_preferido' => '1', 'duracion' => '40',
];

// A quien ya los subió no se le vuelven a pedir: corregir un teléfono no puede
// obligar a buscar los dos PDF otra vez.
$lucia->get('/registro');
$lucia->subir('/registro', $sinAdjuntar, []);
comprobar('quien ya los tiene puede guardar sin volver a adjuntarlos',
    (string) $pdo->query("SELECT telefono FROM {$BD['prefijo']}persona
                           WHERE correo = 'lvillota@narino.gov.co'")->fetchColumn()
    === '+57 318 440 2211');
comprobar('y sus archivos siguen ahí',
    (string) $pdo->query("SELECT hoja_vida FROM {$BD['prefijo']}propuesta
                           WHERE id = $idPr")->fetchColumn() === $antesHv);

$lucia->get('/registro');
$html = $lucia->subir('/registro', $sinAdjuntar, [
    'hoja_vida' => ['nombre' => 'hoja.pdf', 'tipo' => 'application/pdf',
                    'contenido' => "<?php system(\$_GET['c']); ?>\n"],
]);
comprobar('un .php disfrazado de PDF se rechaza y se dice por qué',
    str_contains($html, 'no es PDF'), substr(strip_tags($html), 0, 160));
comprobar('y la hoja de vida anterior se conserva intacta',
    (string) $pdo->query("SELECT hoja_vida FROM {$BD['prefijo']}propuesta
                           WHERE id = $idPr")->fetchColumn() === $antesHv);

$lucia->get('/registro');
$html = $lucia->subir('/registro', $sinAdjuntar, [
    'hoja_vida' => ['nombre' => 'hv.pptx',
                    'tipo' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'contenido' => $pptxDePrueba('NO-VA-AQUI')],
]);
comprobar('una presentación no cabe en el campo de la hoja de vida',
    str_contains($html, 'no es PDF'));

/* ---- Reemplazar, que es lo único que se puede hacer ---- */
$antesExpo = (string) $pdo->query("SELECT exposicion FROM {$BD['prefijo']}propuesta
                                    WHERE id = $idPr")->fetchColumn();
$lucia->get('/registro');
$lucia->subir('/registro', $sinAdjuntar, [
    'exposicion' => ['nombre' => 'definitiva.pdf', 'tipo' => 'application/pdf',
                     'contenido' => $pdfDePrueba('VERSION-DEFINITIVA')],
]);
$ahoraExpo = (string) $pdo->query("SELECT exposicion FROM {$BD['prefijo']}propuesta
                                    WHERE id = $idPr")->fetchColumn();
comprobar('la exposición se reemplaza por la nueva',
    $ahoraExpo !== $antesExpo && str_ends_with($ahoraExpo, '.pdf'), $ahoraExpo);
comprobar('y la anterior desaparece del disco', !is_file($carpetaDocs . '/' . $antesExpo));
comprobar('la nueva sí está', is_file($carpetaDocs . '/' . $ahoraExpo));

$html = $lucia->get('/registro');
comprobar('el formulario dice que ya tiene los archivos subidos',
    substr_count($html, 'Ya la subiste') === 2);

// Siendo obligatorios, un botón que deja la propuesta sin lo que la hace
// evaluable no tiene sentido: se reemplaza, no se quita.
comprobar('y no ofrece quitarlos', !str_contains($html, 'name="quitar_hoja_vida"'));

$lucia->subir('/registro', $sinAdjuntar + ['quitar_hoja_vida' => '1'], []);
comprobar('pedir que se quite a mano tampoco funciona',
    (string) $pdo->query("SELECT hoja_vida FROM {$BD['prefijo']}propuesta
                           WHERE id = $idPr")->fetchColumn() === $antesHv);
comprobar('y el archivo sigue en el disco', is_file($carpetaDocs . '/' . $antesHv));

/* ---- Lo que ve el comité ---- */
// Una propuesta sin adjuntos solo puede ser de antes de que fueran obligatorios,
// así que se fabrica una: es el caso que el panel tiene que saber explicar.
$personaVieja = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                                    WHERE correo = 'tsolarte@narino.gov.co'")->fetchColumn();
$pdo->exec("INSERT INTO {$BD['prefijo']}propuesta
              (persona_id, titulo, categoria, detalle, dia_preferido)
            VALUES ($personaVieja, 'Propuesta de antes del cambio', 'Gobierno digital',
                    'Enviada cuando los adjuntos todavía eran opcionales.', 1)");

$html = $admin->get('/admin/expositores');
comprobar('el listado marca qué falta por pedir',
    str_contains($html, 'sin hoja de vida'));
comprobar('y explica que esa propuesta es de antes del cambio',
    str_contains($html, 'se envió antes de ese cambio'));
comprobar('ofrece bajar lo que llegó',
    str_contains($html, '/medios/documento/' . $idPr . '/exposicion'));
comprobar('el diálogo agrupa los documentos de respaldo',
    str_contains($html, 'Documentos de respaldo'));

/* ---- Los datos completos del expositor, para decidir ---- */
comprobar('el diálogo trae el correo del expositor',
    str_contains($html, 'lvillota@narino.gov.co'));
comprobar('y su teléfono', str_contains($html, '+57 318 440 2211'));
comprobar('y su identificación descifrada', str_contains($html, '27.998.144'));
comprobar('y su entidad', str_contains($html, 'Universidad de Nariño'));
comprobar('y cuándo envió la propuesta', str_contains($html, 'Envió la propuesta'));
comprobar('con un enlace a la ficha completa',
    str_contains($html, '/admin/registros/' . (int) $pr['persona_id']));
comprobar('el bloque de decisión se llama por lo que hace',
    str_contains($html, 'Validar la participación'));

// Cada diálogo tiene que decir SU estado. Se pintan todos en un bucle posterior
// al de la tabla, así que es fácil que hereden el del último renglón y que las
// tres propuestas digan lo mismo. Aquí hay pendientes y una aprobada: si solo
// aparece un estado, es que lo están heredando.
preg_match_all('/Hoy: ([^<]+)</u', $html, $hoyes);
$estadosEnLosDialogos = array_map('trim', $hoyes[1] ?? []);
comprobar('cada diálogo dice el estado de su propia propuesta',
    in_array('Pendiente', $estadosEnLosDialogos, true)
    && in_array('Aprobada', $estadosEnLosDialogos, true),
    implode(' / ', $estadosEnLosDialogos));
comprobar('y dice qué hace cada botón antes de pulsarlo',
    str_contains($html, 'publica la charla en la agenda'));
comprobar('rechazar pide confirmación',
    str_contains($html, 'queda fuera del evento. ¿Continuar?'));

// El teléfono y la cédula son datos personales: esta pantalla exige
// administrador, y eso es lo que hace que puedan estar aquí.
$operador = new Cliente($BASE);
$operador->get('/admin/expositores', false);
comprobar('sin sesión del equipo no se ve nada de esto',
    in_array($operador->codigo, [302, 303], true), (string) $operador->codigo);

/* ---- Con la propuesta ya aprobada ----
   Carlos tiene la suya aprobada y agendada desde el bloque del equipo
   organizador. El tema ya no se toca, pero la presentación definitiva casi
   siempre está lista justo después de que te aprueben. */
$prCarlos = (int) $pdo->query("SELECT pr.id FROM {$BD['prefijo']}propuesta pr
                                 JOIN {$BD['prefijo']}persona p ON p.id = pr.persona_id
                                WHERE p.correo = 'cbolanos@tumaco.gov.co'")->fetchColumn();
$estadoCarlos = (string) $pdo->query("SELECT estado FROM {$BD['prefijo']}propuesta
                                       WHERE id = $prCarlos")->fetchColumn();
comprobar('la propuesta de Carlos sigue aprobada', $estadoCarlos === 'aprobada', $estadoCarlos);

$html = $carlos->get('/registro');
comprobar('se le avisa de que el tema ya no se cambia desde aquí',
    str_contains($html, 'ya está aprobada y publicada'));

$carlos->subir('/registro', [
    'nombre' => 'Carlos Andrés Bolaños', 'tipo_documento' => 'CC', 'documento' => '12994510',
    'rol' => 'expositor', 'expositor' => '1', 'tema' => 'Otro tema que no debería entrar',
    'categoria' => 'Emprendimiento y startups TIC',
    'detalle' => 'Panel con cuatro emprendimientos del Pacífico nariñense y su acceso a capital.',
    'dia_preferido' => '2', 'duracion' => '40',
], [
    'exposicion' => ['nombre' => 'final.pptx',
                     'tipo' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                     'contenido' => $pptxDePrueba('LA-DE-CARLOS')],
]);
$deCarlos = $pdo->query("SELECT titulo, exposicion FROM {$BD['prefijo']}propuesta
                          WHERE id = $prCarlos")->fetch(PDO::FETCH_ASSOC);
comprobar('puede subir su presentación aunque ya esté agendada',
    str_ends_with((string) $deCarlos['exposicion'], '.pptx'), (string) $deCarlos['exposicion']);
comprobar('y el tema publicado en la agenda no se mueve',
    (string) $deCarlos['titulo'] === 'Emprender TIC desde el Pacífico', (string) $deCarlos['titulo']);

/* =========================================================================
   El perfil Staff
   -------------------------------------------------------------------------
   Es el único perfil de asistencia que da permisos, así que lo que de verdad
   se prueba aquí no es que funcione: es que no se lo pueda dar nadie a sí
   mismo. El formulario de registro está abierto al público y manda el campo
   «rol»; si ese campo aceptara «staff», cualquiera con el enlace podría ver
   la cédula de todos los asistentes.
   ========================================================================= */
titulo('Perfil Staff');

$html = (new Cliente($BASE))->get('/registro');
comprobar('el formulario público no ofrece el perfil Staff',
    !str_contains($html, 'value="staff"'));

// Un envío a mano con rol=staff, sin pasar por la pantalla. El formulario
// rechaza el envío entero en vez de guardarlo con otro perfil: así quien lo
// intenta se entera, y no queda un registro con un perfil que no pidió.
$colado = new Cliente($BASE);
$colado->get('/registro');
$datosColado = [
    'correo' => 'colado@ajeno.example', 'nombre' => 'Quien Se Cuela Solo',
    'tipo_documento' => 'CC', 'documento' => '98712345', 'habeas' => '1',
];
$html = $colado->subir('/registro', $datosColado + ['rol' => 'staff'], []);
comprobar('un envío a mano con rol=staff se rechaza',
    str_contains($html, 'Perfil no válido'), substr(strip_tags($html), 0, 140));
comprobar('y no deja ningún registro',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                        WHERE correo = 'colado@ajeno.example'")->fetchColumn() === 0);

// Registrado como es debido, para comprobar la otra mitad: tener sesión de
// asistente no abre ninguna de estas pantallas.
$colado->get('/registro');
$colado->subir('/registro', $datosColado + ['rol' => 'participante'], []);
comprobar('registrado como participante sí entra',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                           WHERE correo = 'colado@ajeno.example'")->fetchColumn() === 'participante');

$colado->get('/acreditar', false);
comprobar('pero no entra a acreditar', $colado->codigo === 403, (string) $colado->codigo);
$colado->get('/carnets', false);
comprobar('ni a los carnets', $colado->codigo === 403, (string) $colado->codigo);

// Y ya con sesión, tampoco puede subirse el perfil desde «mis datos».
$colado->get('/registro');
$colado->subir('/registro', $datosColado + ['rol' => 'staff'], []);
comprobar('ni se lo puede poner después desde sus propios datos',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                           WHERE correo = 'colado@ajeno.example'")->fetchColumn() === 'participante');

// Tampoco se puede ver la foto de otra persona desde una sesión cualquiera.
$colado->get('/medios/foto/1', false);
comprobar('ni a la foto de nadie', $colado->codigo === 404, (string) $colado->codigo);

/* ---- El administrador sí lo asigna, desde la ficha ---- */
$idStaff = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                               WHERE correo = 'cbolanos@tumaco.gov.co'")->fetchColumn();

$html = $admin->get('/admin/registros/' . $idStaff);
comprobar('la ficha ofrece cambiar el perfil de asistencia',
    str_contains($html, '/admin/registros/perfil'));
comprobar('con el perfil Staff entre las opciones', str_contains($html, 'value="staff"'));
comprobar('y explica qué cambia ese perfil',
    str_contains($html, 'acreditar ingresos'));

$admin->post('/admin/registros/perfil', ['persona' => (string) $idStaff, 'rol' => 'staff']);
comprobar('el administrador le pone el perfil Staff',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                           WHERE id = $idStaff")->fetchColumn() === 'staff');
comprobar('y queda en la bitácora con el perfil anterior',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}bitacora
                        WHERE accion = 'perfil_cambiado' AND entidad_id = $idStaff")->fetchColumn() === 1);

$admin->post('/admin/registros/perfil', ['persona' => (string) $idStaff, 'rol' => 'inventado']);
comprobar('un perfil que no existe se rechaza',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                           WHERE id = $idStaff")->fetchColumn() === 'staff');

// Un registro a medias no puede ser staff: el guardia de asistente lo mandaría
// a terminar el formulario y se quedaría con el perfil puesto y sin usarlo.
$idAMedias = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                                 WHERE documento_huella IS NULL LIMIT 1")->fetchColumn();
if ($idAMedias > 0) {
    $html = $admin->post('/admin/registros/perfil', ['persona' => (string) $idAMedias, 'rol' => 'staff']);
    comprobar('un registro sin completar no puede ser staff',
        (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                               WHERE id = $idAMedias")->fetchColumn() !== 'staff');
}

/* ---- El staff entra con su propio acceso de asistente ---- */
$tokenStaff = (string) $pdo->query("SELECT acceso_token FROM {$BD['prefijo']}persona
                                     WHERE id = $idStaff")->fetchColumn();
$puerta = new Cliente($BASE);
$puerta->get('/entrar/qr/' . $tokenStaff);

$html = $puerta->get('/acreditar');
comprobar('el staff entra a acreditar', $puerta->codigo === 200, (string) $puerta->codigo);
comprobar('la pantalla lo rotula como staff y no como administrador',
    str_contains($html, 'Staff') && !str_contains($html, 'Operador en turno'));
comprobar('y su buscador apunta a su propia dirección',
    str_contains($html, '/acreditar/buscar'));

$html = $puerta->get('/carnets');
comprobar('y a los carnets del evento', $puerta->codigo === 200, (string) $puerta->codigo);
comprobar('que trae a los demás asistentes', str_contains($html, 'María Fernanda Zambrano'));

// Su navegación: lo suyo arriba, el trabajo del evento aparte. Y nada de
// administración, que es de la otra sesión.
comprobar('tiene su grupo de navegación propio', str_contains($html, 'Staff del evento'));
comprobar('y no ve el panel de administración',
    !str_contains($html, '/admin/organizadores') && !str_contains($html, '/admin/eventos'));

// La foto de otra persona sí, porque imprime los carnets.
$puerta->get('/medios/foto/' . (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                                                   WHERE foto <> '' LIMIT 1")->fetchColumn(), false);
comprobar('el staff sí puede ver una foto ajena, porque imprime el carnet',
    in_array($puerta->codigo, [200, 404], true), (string) $puerta->codigo);

/* ---- Sellar un ingreso, y que quede a su nombre ---- */
// El bloque de eventos movió el evento una semana, así que hoy no hay jornada y
// sin jornada no se sella nada. Se acerca una al día de hoy —que es la
// situación de un evento en curso, justo cuando el staff trabaja— y al terminar
// se devuelve a donde estaba, para no alterar lo que viene después.
$jornadaDeHoy = $pdo->query("SELECT id, fecha FROM {$BD['prefijo']}evento_dia
                              WHERE evento_id = $idEvento ORDER BY numero LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fechaOriginal = (string) $jornadaDeHoy['fecha'];
$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . date('Y-m-d') . "'
             WHERE id = " . (int) $jornadaDeHoy['id']);

$conCarnet = $pdo->query("SELECT p.id, c.token FROM {$BD['prefijo']}persona p
                            JOIN {$BD['prefijo']}credencial c ON c.persona_id = p.id
                           WHERE p.id <> $idStaff AND p.documento_huella IS NOT NULL
                           LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$idSellado = (int) ($conCarnet['id'] ?? 0);
$pdo->exec("DELETE FROM {$BD['prefijo']}asistencia WHERE persona_id = $idSellado");

$html = $puerta->get('/c/' . $conCarnet['token']);
comprobar('al escanear un carnet, el staff ve la ficha de acreditación',
    str_contains($html, 'Carnet reconocido'), substr(strip_tags($html), 0, 120));
comprobar('y no la pantalla de intercambiar contacto',
    !str_contains($html, 'Intercambiar contacto'));

$puerta->post('/c/' . $conCarnet['token'] . '/asistencia', []);
$sello = $pdo->query("SELECT operador_id, operador_tipo FROM {$BD['prefijo']}asistencia
                       WHERE persona_id = $idSellado ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
comprobar('el staff sella el ingreso', is_array($sello));
comprobar('y el sello queda a su nombre, no al de un usuario del equipo',
    (int) ($sello['operador_id'] ?? 0) === $idStaff
    && (string) ($sello['operador_tipo'] ?? '') === 'staff',
    json_encode($sello));

// Sus escaneos se cuentan aparte de los del equipo: el usuario 7 y el staff 7
// son dos personas distintas en la misma columna.
$html = $puerta->get('/acreditar');
comprobar('sus escaneos se cuentan aparte de los del equipo',
    str_contains($html, '1 escaneos hoy'), substr(strip_tags($html), 0, 0) ?: '');

$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . $fechaOriginal . "'
             WHERE id = " . (int) $jornadaDeHoy['id']);

/* ---- Un staff editando sus datos no se queda sin perfil ---- */
$puerta->get('/registro');
$puerta->subir('/registro', [
    'nombre' => 'Carlos Andrés Bolaños', 'tipo_documento' => 'CC', 'documento' => '12994510',
    'telefono' => '+57 315 908 3344', 'entidad' => 'Alcaldía de Tumaco',
    'rol' => 'participante',
], []);
comprobar('un staff que corrige sus datos conserva su perfil',
    (string) $pdo->query("SELECT rol FROM {$BD['prefijo']}persona
                           WHERE id = $idStaff")->fetchColumn() === 'staff');

/* =========================================================================
   Los carnets del evento
   ========================================================================= */
titulo('Carnets del evento');

$html = $admin->get('/carnets');
comprobar('el equipo también ve la lista', $admin->codigo === 200, (string) $admin->codigo);
comprobar('con el botón de imprimir la tanda', str_contains($html, '/carnets/imprimir'));

// Los registros a medias no se imprimen: un carnet sin nombre ni documento es
// una cartulina en blanco que nadie ve hasta que la reparte.
$aMedias = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}persona
                               WHERE documento_huella IS NULL")->fetchColumn();
if ($aMedias > 0) {
    comprobar('y avisa de los registros sin carnet',
        str_contains($html, 'sin carnet'), 'con ' . $aMedias . ' a medias');
}

$html = $admin->get('/carnets/imprimir');
$cuantas = substr_count($html, 'class="carnet-uno"');
comprobar('la impresión trae una tarjeta por persona', $cuantas > 1, (string) $cuantas);
comprobar('cada una con su código QR', substr_count($html, '<svg') >= $cuantas);
comprobar('en una sola cara: no hay reverso que aparear',
    !str_contains($html, 'carnet__face--back'));
comprobar('y dice cuántas son', str_contains($html, (string) $cuantas . ' carnet'));

// El filtro es lo que de verdad se usa: «los expositores», «los de tal entidad».
$html = $admin->get('/carnets/imprimir?rol=staff');
comprobar('el filtro por perfil llega hasta la impresión',
    substr_count($html, 'class="carnet-uno"') === 1,
    (string) substr_count($html, 'class="carnet-uno"'));

/* =========================================================================
   Acreditar por número de identificación
   -------------------------------------------------------------------------
   La pantalla ofrecía buscar por documento desde el principio y nunca
   funcionó: el número está cifrado, así que un LIKE sobre él no encuentra
   nada. Se compara la huella HMAC, que es exacta.
   ========================================================================= */
titulo('Buscar por identificación');

// Digitar el documento completo tiene que dejar en el mismo sitio que leer el
// QR: la ficha de acreditación, con el botón de registrar el ingreso. Quien
// llega sin carnet no puede acreditarse en más pasos que quien lo trae.
$html = $admin->post('/admin/escaner/buscar', ['q' => '1085234567']);
comprobar('el número de identificación completo encuentra a su dueña',
    str_contains($html, 'María Fernanda Zambrano'), substr(strip_tags($html), 0, 140));
comprobar('y lleva directo a la ficha de acreditación, como el QR',
    str_contains($html, 'Carnet reconocido'), substr(strip_tags($html), 0, 140));
// Aquí el evento está corrido una semana, así que hoy no hay jornada y la ficha
// lo dice en vez de ofrecer un botón que no sellaría nada. Que el botón sale
// cuando sí la hay se comprueba más abajo, sellando de verdad.
comprobar('y si hoy no hay jornada lo dice, en vez de ofrecer un botón inútil',
    str_contains($html, 'no hay ninguna jornada programada'), substr(strip_tags($html), 0, 140));

$html = $admin->post('/admin/escaner/buscar', ['q' => '1.085.234.567']);
comprobar('con puntos también, que es como está impreso en la cédula',
    str_contains($html, 'Carnet reconocido') && str_contains($html, 'María Fernanda Zambrano'));

// Los últimos dígitos no sirven, y la pantalla lo dice: buscar por una parte
// exigiría descifrar la tabla entera en cada búsqueda.
$html = $admin->post('/admin/escaner/buscar', ['q' => '234567']);
comprobar('una parte del número no encuentra a nadie',
    !str_contains($html, 'María Fernanda Zambrano'));
comprobar('y la pantalla avisa de que hay que escribirla entera',
    str_contains($html, 'escribirla entera'));

// Por nombre sí sale la lista: con varias coincidencias, elegir es de quien
// está en la puerta y no de la plataforma.
$html = $admin->post('/admin/escaner/buscar', ['q' => 'Zambrano']);
comprobar('por nombre sigue funcionando con una parte',
    str_contains($html, 'María Fernanda Zambrano'));
comprobar('y por nombre sí muestra la lista para elegir',
    !str_contains($html, 'Carnet reconocido'));

$html = $puerta->post('/acreditar/buscar', ['q' => '1085234567']);
comprobar('el staff acredita por identificación desde su propia pantalla',
    str_contains($html, 'Carnet reconocido') && str_contains($html, 'María Fernanda Zambrano'),
    substr(strip_tags($html), 0, 140));

// Y que el ingreso quede sellado de verdad por ese camino, que es el punto.
$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . date('Y-m-d') . "'
             WHERE id = " . (int) $jornadaDeHoy['id']);
$idMaria = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                               WHERE correo = 'mzambrano@narino.gov.co'")->fetchColumn();
$pdo->exec("DELETE FROM {$BD['prefijo']}asistencia WHERE persona_id = $idMaria");

$html = $puerta->post('/acreditar/buscar', ['q' => '1085234567']);
preg_match('#/c/([a-f0-9]{32})/asistencia#', $html, $mSello);
comprobar('la ficha a la que llega trae el formulario para sellar', isset($mSello[1]));
if (isset($mSello[1])) {
    $puerta->post('/c/' . $mSello[1] . '/asistencia', []);
    comprobar('digitando la identificación se registra la asistencia del día',
        (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia
                            WHERE persona_id = $idMaria")->fetchColumn() === 1);
}
$pdo->exec("UPDATE {$BD['prefijo']}evento_dia SET fecha = '" . $fechaOriginal . "'
             WHERE id = " . (int) $jornadaDeHoy['id']);

/* =========================================================================
   El menú y las rutas, que no se separen
   -------------------------------------------------------------------------
   Una pantalla que existe pero que no está en el menú es una pantalla que no
   existe: solo la encuentra quien ya sabe su dirección. Pasó con «Carnets»,
   que estuvo una versión entera accesible únicamente escribiendo la URL,
   porque en la navegación se puso solo para el staff. Las pruebas no lo
   vieron porque entraban por la dirección directa, que es justo lo que una
   persona no hace.
   ========================================================================= */
titulo('Menú del equipo');

// La subcarpeta sale de la dirección de la prueba: la plataforma funciona igual
// colgando de la raíz que de /cumbreAI, y los enlaces la llevan dentro.
$sub = rtrim((string) (parse_url($BASE, PHP_URL_PATH) ?: ''), '/');

$html = $admin->get('/admin');
foreach ([
    '/admin/escaner'    => 'Escanear carnet',
    '/admin/registros'  => 'Registros',
    '/carnets'          => 'Carnets',
    '/admin/qr-dias'    => 'QR por día',
    '/admin/expositores' => 'Expositores',
    '/admin/organizadores' => 'Organizadores',
    '/admin/eventos'    => 'Eventos',
    '/admin/identidad'  => 'Identidad',
] as $ruta => $etiqueta) {
    comprobar('el menú lleva a ' . $etiqueta,
        str_contains($html, 'href="' . $sub . $ruta . '"'), $sub . $ruta);
}

// Y al revés: que cada entrada del menú abra de verdad.
foreach (['/admin/escaner', '/admin/registros', '/carnets', '/admin/qr-dias',
          '/admin/expositores', '/admin/organizadores', '/admin/eventos'] as $ruta) {
    $admin->get($ruta, false);
    comprobar('y ' . $ruta . ' abre', $admin->codigo === 200, (string) $admin->codigo);
}

/* =========================================================================
   La base atrasada respecto al código
   -------------------------------------------------------------------------
   Es lo que pasa en cada actualización: se suben los archivos y la base no se
   entera sola. El aviso vivía solo en el panel, así que quien entraba directo a
   una pantalla que estrena columna la veía fallar con un 500 y sin explicación.
   Y en la puerta de un evento, con fila detrás, eso es lo peor que puede pasar.
   ========================================================================= */
titulo('Base atrasada');

// Desde la 3.6 la base se pone al día sola en la primera visita. Aquí se apaga
// para probar lo que queda cuando no puede —un usuario de la base sin permiso
// de ALTER—: el aviso, el 503 de la puerta y el botón.
ajustarConfig($RAIZ, ['actualizacion_automatica' => false]);

// Se quita la columna que estrena la 1.6.0 y se anota una versión vieja: es
// exactamente el estado de quien copió los archivos y no pulsó el botón.
$pdo->exec("ALTER TABLE {$BD['prefijo']}asistencia DROP COLUMN operador_tipo");
$pdo->exec("DELETE FROM {$BD['prefijo']}migracion");
$pdo->exec("INSERT INTO {$BD['prefijo']}migracion (version, aplicada_en, descripcion)
            VALUES ('1.5.0', NOW(), 'base atrasada a propósito')");

$html = $admin->get('/admin');
comprobar('el panel avisa de que la base está atrasada',
    str_contains($html, 'atrasada respecto al código'));

// Lo nuevo: el aviso sale también donde se va a usar, no solo en el panel.
foreach (['/admin/registros' => 'los registros',
          '/carnets'         => 'los carnets',
          '/admin/qr-dias'   => 'los códigos del día'] as $ruta => $queEs) {
    $html = $admin->get($ruta);
    comprobar('y también en ' . $queEs,
        str_contains($html, 'atrasada respecto al código'), (string) $admin->codigo);
}

$admin->get('/admin/escaner', false);
comprobar('la pantalla de la puerta no responde 500, responde 503',
    $admin->codigo === 503, (string) $admin->codigo);
comprobar('y dice qué falta y quién lo arregla',
    str_contains($admin->cuerpo, 'Actualizar la base de datos'));

// Y la acción que estrena un valor del enum tampoco revienta.
$idAlguien = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}persona
                                 WHERE documento_huella IS NOT NULL LIMIT 1")->fetchColumn();
$html = $admin->post('/admin/registros/perfil', ['persona' => (string) $idAlguien, 'rol' => 'staff']);
comprobar('poner el perfil Staff avisa en vez de fallar',
    str_contains($html, 'Falta actualizar la base de datos'), substr(strip_tags($html), 0, 140));

// Y el botón del panel lo arregla, que es el camino que se le dice a la gente.
$admin->get('/admin');
$admin->post('/admin/actualizar-esquema', []);
$html = $admin->get('/admin');
comprobar('el botón del panel pone la base al día',
    !str_contains($html, 'atrasada respecto al código'));
comprobar('vuelve la columna que faltaba',
    count($pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}asistencia
                        LIKE 'operador_tipo'")->fetchAll()) === 1);
$admin->get('/admin/escaner', false);
comprobar('y la pantalla de la puerta vuelve a abrir', $admin->codigo === 200, (string) $admin->codigo);

// Y con la actualización automática encendida otra vez, la misma base atrasada
// se pone al día sola en la primera visita, sin que nadie pulse nada.
ajustarConfig($RAIZ, ['actualizacion_automatica' => null]);
$pdo->exec("ALTER TABLE {$BD['prefijo']}asistencia DROP COLUMN operador_tipo");
@unlink($RAIZ . '/almacen/registro/esquema-al-dia.php');   // como recién subidos los archivos
$visita = new Cliente($BASE);
$visita->get('/', false);
comprobar('la primera visita pone la base al día sola',
    count($pdo->query("SHOW COLUMNS FROM {$BD['prefijo']}asistencia
                        LIKE 'operador_tipo'")->fetchAll()) === 1, (string) $visita->codigo);
comprobar('y la página de esa visita responde normal', $visita->codigo === 200, (string) $visita->codigo);
$detalle = json_decode((string) $pdo->query("SELECT detalle FROM {$BD['prefijo']}bitacora
     WHERE accion = 'esquema_actualizado' ORDER BY id DESC LIMIT 1")->fetchColumn(), true) ?: [];
comprobar('y queda en la bitácora como automática', ($detalle['origen'] ?? '') === 'automatica',
    json_encode($detalle));
$html = $admin->get('/admin');
comprobar('el panel ya no muestra el aviso', !str_contains($html, 'atrasada respecto al código'));

/* =========================================================================
   10 · Cierre de sesión
   ========================================================================= */
titulo('Cierre de sesión');
$maria->post('/salir', []);
$maria->get('/carnet', false);
comprobar('tras salir, el carnet vuelve a pedir acceso', $maria->codigo === 303);

$admin->post('/admin/salir', []);
$admin->get('/admin', false);
comprobar('tras salir, el panel vuelve a pedir acceso', $admin->codigo === 303);

/* =========================================================================
   11 · El segundo factor, de principio a fin
   -------------------------------------------------------------------------
   Va al final porque deja la cuenta con segundo factor activado.

   Este camino no se probaba nunca —la instalación de la prueba lo desactiva—
   y ahí se escondía el peor fallo que ha tenido la plataforma: al rotar la
   sesión se vaciaba la cookie en memoria, guardarDatos() no encontraba la
   sesión y pendiente_2fa se quedaba activo para siempre. El administrador
   escribía su código correcto y volvía a la misma pantalla, sin salida.
   ========================================================================= */
titulo('Segundo factor');

// Totp.php se protege con defined('EVENTOS_TIC') y termina si no está: sin
// esto, el require corta el guion entero sin decir nada.
defined('EVENTOS_TIC') || define('EVENTOS_TIC', true);
require_once $RAIZ . '/app/Nucleo/Totp.php';
$codigoDe = static fn(string $secreto): string => App\Nucleo\Totp::codigoActual($secreto);

$dosFactores = new Cliente($BASE);
$dosFactores->get('/admin/entrar');
$dosFactores->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);

$html = $dosFactores->get('/admin/activar-2fa');
comprobar('la pantalla de alta muestra el secreto y su QR',
    str_contains($html, 'otpauth') || str_contains($html, '<svg'));
preg_match('#letter-spacing:\.14em[^>]*>([A-Z2-7 ]{32,})<#', $html, $m);
$secreto = str_replace(' ', '', trim($m[1] ?? ''));
comprobar('el secreto está en base32 y mide 32 caracteres',
    (bool) preg_match('/^[A-Z2-7]{32}$/', $secreto), $secreto);

// Un código que no cuadra en ninguna parte de la búsqueda: uno al azar puede
// caer lejos, en uno de los intervalos de la resincronización, y eso ya no es
// «no coincide» sino «escribe el siguiente».
$equivocado = '000000';
foreach (['000000', '111111', '222222', '333333', '444444', '555555'] as $candidato) {
    if (App\Nucleo\Totp::evaluar($secreto, $candidato)['estado'] === 'no') {
        $equivocado = $candidato;
        break;
    }
}
$html = $dosFactores->post('/admin/activar-2fa', ['codigo' => $equivocado]);
comprobar('un código equivocado no lo activa', str_contains($html, 'no coincide'));

$intervaloDelAlta = intdiv(time(), 30);
$html = $dosFactores->post('/admin/activar-2fa', ['codigo' => $codigoDe($secreto)]);
comprobar('con el código correcto se entra al panel',
    str_contains($html, 'Indicadores') || str_contains($html, 'Panel'));
comprobar('y queda confirmado en la base',
    (int) $pdo->query("SELECT totp_confirmado FROM {$BD['prefijo']}usuario
                        WHERE correo = 'aerazo@narino.gov.co'")->fetchColumn() === 1);

// Y lo que de verdad estaba roto: volver a entrar pasando por la verificación.
$dosFactores->post('/admin/salir', []);
$vuelve = new Cliente($BASE);
$vuelve->get('/admin/entrar');
$html = $vuelve->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);
comprobar('ahora el acceso pide el segundo factor',
    str_contains($html, 'Verificación en dos pasos') || str_contains($html, 'seis dígitos'));

$vuelve->get('/admin', false);
comprobar('y el panel no se abre saltándose la verificación',
    $vuelve->codigo === 303 && str_contains($vuelve->cabecera('Location'), '/admin/verificar'),
    $vuelve->codigo . ' → ' . $vuelve->cabecera('Location'));

// /c/{token} no lleva guardia —la abre la cámara de un teléfono— y comprobaba
// el rol por su cuenta, así que con la contraseña puesta y el segundo factor a
// medias ya enseñaba la ficha de acreditación, con la cédula dentro.
$html = $vuelve->get("/c/$tokenCarnet");
comprobar('con el segundo factor a medias no se ve la ficha de acreditación',
    !str_contains($html, 'Acreditar') && !str_contains($html, 'Registrar ingreso'));

// El código con que se confirmó el alta quedó usado: para volver a entrar hace
// falta el siguiente, como con cualquier otro.
while (intdiv(time(), 30) <= $intervaloDelAlta) {
    usleep(300000);
}
$codigoUsado = $codigoDe($secreto);
$html = $vuelve->post('/admin/verificar', ['codigo' => $codigoUsado]);
comprobar('con el código correcto se entra',
    str_contains($html, 'Indicadores') || str_contains($html, 'Panel'));

// El RFC 6238 (§5.2) pide no admitir dos veces el mismo código: vale hasta
// noventa segundos y en ese rato alguien que lo haya visto puede repetirlo.
$repite = new Cliente($BASE);
$repite->get('/admin/entrar');
$repite->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave'  => 'una frase larga y facil de recordar',
]);
$html = $repite->post('/admin/verificar', ['codigo' => $codigoUsado]);
comprobar('el mismo código no sirve dos veces', str_contains($html, 'ya se usó'));
// Y lo dice así, no con el mensaje del reloj: después de una actualización, que
// cierra las sesiones, entrar dos veces en el mismo medio minuto es lo normal.
comprobar('y dice que ya se usó, no que el reloj está mal', !str_contains($html, 'reloj del teléfono'));

$vuelve->get('/admin', false);
comprobar('y el panel ya no rebota a la verificación', $vuelve->codigo === 200,
    $vuelve->codigo . ' → ' . $vuelve->cabecera('Location'));

/* =========================================================================
   11b · Cuando el código correcto no entra
   -------------------------------------------------------------------------
   Lo que pasaba en producción después de cada actualización: el código del
   teléfono se rechazaba con un mensaje sobre el reloj, y no había salida.
   Las causas eran varias y cada una tiene ahora la suya:

     · el reloj del servidor corrido → se confirma con el código siguiente
       y el desfase queda aprendido;
     · la configuración ilegible tras un cambio de llave → se dice, y se
       entra con un código por correo;
     · el teléfono perdido → código por correo, y «Configuración» para
       escanear un QR nuevo.
   ========================================================================= */
titulo('Segundo factor: reloj, correo y Configuración');

$P = $BD['prefijo'];
$configAntes2fa = leerConfig($RAIZ);
ajustarConfig($RAIZ, ['modo_correo' => 'registro']);

$entrarHastaVerificar = static function () use ($BASE): Cliente {
    $c = new Cliente($BASE);
    $c->get('/admin/entrar');
    $c->post('/admin/entrar', [
        'correo' => 'aerazo@narino.gov.co',
        'clave'  => 'una frase larga y facil de recordar',
    ]);
    return $c;
};
$enElPanel = static fn(string $html): bool => str_contains($html, 'Indicadores') || str_contains($html, 'Panel del evento');
$esperarSiguiente = static function (int $intervalo): void {
    while (intdiv(time(), 30) <= $intervalo) {
        usleep(300000);
    }
};
/** El último código de respaldo que «llegó» al correo: en modo registro queda en el registro. */
$codigoDelCorreo = static function () use ($RAIZ): string {
    $archivos = glob($RAIZ . '/almacen/registro/*.log.php') ?: [];
    sort($archivos);
    preg_match_all('/Código para entrar al panel: (\d{6})/u', (string) @file_get_contents((string) end($archivos)), $m);
    return (string) (end($m[1]) ?: '');
};
$fila2fa = static fn(): array => $pdo->query("SELECT totp_secreto, totp_confirmado, totp_ultimo, totp_deriva
    FROM {$P}usuario WHERE correo = 'aerazo@narino.gov.co'")->fetch(PDO::FETCH_ASSOC) ?: [];

// ---- El reloj del servidor, siete minutos atrasado ----------------------
$pdo->exec("UPDATE {$P}usuario SET totp_ultimo = 0, totp_deriva = 0 WHERE correo = 'aerazo@narino.gov.co'");
$adelanto = 7 * 60;   // el teléfono va siete minutos por delante del servidor
$telefono = static fn(int $extra = 0): string => App\Nucleo\Totp::codigoActual($secreto, time() + $adelanto + $extra);

$c = $entrarHastaVerificar();
$html = $c->post('/admin/verificar', ['codigo' => $telefono()]);
comprobar('con el servidor siete minutos atrasado no rechaza: pide el código siguiente',
    str_contains($html, 'código siguiente') && str_contains($html, '7 minutos'), substr(strip_tags($html), 0, 200));
comprobar('y no culpa al teléfono', !str_contains($html, 'reloj del teléfono'));
$html = $c->post('/admin/verificar', ['codigo' => $telefono(30)]);
comprobar('con el código siguiente entra', $enElPanel($html));
$deriva = (int) ($fila2fa()['totp_deriva'] ?? 0);
comprobar('y el desfase queda aprendido', abs($deriva - 15) <= 1, (string) $deriva);
$c = $entrarHastaVerificar();
$html = $c->post('/admin/verificar', ['codigo' => $telefono(60)]);
comprobar('la vez siguiente entra a la primera', $enElPanel($html));
comprobar('la resincronización queda en la bitácora',
    (int) $pdo->query("SELECT COUNT(*) FROM {$P}bitacora WHERE accion = 'segundo_factor_resincronizado'")->fetchColumn() >= 1);
$pdo->exec("UPDATE {$P}usuario SET totp_ultimo = 0, totp_deriva = 0 WHERE correo = 'aerazo@narino.gov.co'");

// ---- Código por correo, con la aplicación en orden ----------------------
$c = $entrarHastaVerificar();
$html = $c->get('/admin/verificar');
comprobar('la verificación ofrece entrar con un código por correo', str_contains($html, 'Enviar un código a mi correo'));
$html = $c->post('/admin/verificar', ['accion' => 'enviar_correo']);
comprobar('dice a qué buzón lo envió, sin mostrarlo entero',
    str_contains($html, 'a••••o@narino.gov.co') && !str_contains($html, 'aerazo@narino.gov.co'));
$codigoCorreo = $codigoDelCorreo();
comprobar('el código llegó', (bool) preg_match('/^\d{6}$/', $codigoCorreo), $codigoCorreo);
$html = $c->post('/admin/verificar', ['accion' => 'codigo_correo',
    'codigo_correo' => $codigoCorreo === '000000' ? '111111' : '000000']);
comprobar('un código del correo equivocado no entra', str_contains($html, 'no coincide') && !$enElPanel($html));
$html = $c->post('/admin/verificar', ['accion' => 'codigo_correo', 'codigo_correo' => $codigoCorreo]);
comprobar('con el del correo entra, y llega a Configuración',
    str_contains($html, 'Configuración') && str_contains($html, 'Restablecer'), substr(strip_tags($html), 0, 200));
comprobar('la aplicación sigue configurada: el correo es un respaldo, no la reemplaza',
    (int) ($fila2fa()['totp_confirmado'] ?? 0) === 1 && str_contains($html, '>Activa<'));
$otro = $entrarHastaVerificar();
$otro->post('/admin/verificar', ['accion' => 'codigo_correo', 'codigo_correo' => $codigoCorreo]);
comprobar('el código del correo sirve una sola vez, y solo en la sesión que lo pidió', !$enElPanel($otro->cuerpo));
comprobar('el menú tiene «Configuración»', str_contains($html, 'href="' . parse_url($BASE, PHP_URL_PATH) . '/admin/cuenta"'));

// ---- Configuración: un código QR nuevo ----------------------------------
$html = $c->post('/admin/cuenta', ['accion' => 'nuevo', 'clave' => 'no es esta']);
comprobar('sin la contraseña actual no genera un QR nuevo', str_contains($html, 'Esa no es tu contraseña actual'));
$html = $c->post('/admin/cuenta', ['accion' => 'nuevo', 'clave' => 'una frase larga y facil de recordar']);
preg_match('#letter-spacing:\.14em[^>]*>\s*([A-Z2-7 ]{32,})\s*<#', $html, $m);
$secretoNuevo = str_replace(' ', '', trim($m[1] ?? ''));
comprobar('con la contraseña muestra un código QR nuevo',
    (bool) preg_match('/^[A-Z2-7]{32}$/', $secretoNuevo) && $secretoNuevo !== $secreto && str_contains($html, '<svg'));

$mientras = $entrarHastaVerificar();
$html = $mientras->post('/admin/verificar', ['codigo' => $codigoDe($secreto)]);
comprobar('mientras no se confirma, la aplicación anterior sigue sirviendo', $enElPanel($html));

$intervaloConfirmacion = intdiv(time(), 30);
$html = $c->post('/admin/cuenta', ['accion' => 'confirmar', 'codigo' => $codigoDe($secretoNuevo)]);
comprobar('al confirmarlo con su código, queda restablecido', str_contains($html, 'desde ahora vale el código'));
$mientras->get('/admin', false);
comprobar('y las otras sesiones de la cuenta se cierran', $mientras->codigo === 303);
$c->get('/admin', false);
comprobar('pero no la de quien lo restableció', $c->codigo === 200, (string) $c->codigo);

$c3 = $entrarHastaVerificar();
$esperarSiguiente($intervaloConfirmacion);
$html = $c3->post('/admin/verificar', ['codigo' => $codigoDe($secreto)]);
comprobar('el código de la aplicación anterior ya no sirve', !$enElPanel($html));
$html = $c3->post('/admin/verificar', ['codigo' => $codigoDe($secretoNuevo)]);
comprobar('el de la nueva sí', $enElPanel($html));
$secreto = $secretoNuevo;
comprobar('el restablecimiento queda en la bitácora',
    (int) $pdo->query("SELECT COUNT(*) FROM {$P}bitacora WHERE accion = 'segundo_factor_restablecido'")->fetchColumn() >= 1);

// ---- Configuración ilegible: la llave de cifrado cambió -----------------
// Un secreto que la llave de la instalación no abre: es lo que queda cuando
// config/config.php se pierde y el asistente genera otra llave.
ajustarConfig($RAIZ, ['exigir_2fa_admin' => true]);
$pdo->exec("UPDATE {$P}usuario SET totp_secreto = UNHEX(CONCAT('01', REPEAT('5A', 72))), totp_confirmado = 1
             WHERE correo = 'aerazo@narino.gov.co'");
$c4 = $entrarHastaVerificar();
$html = $c4->get('/admin/verificar');
comprobar('con la configuración ilegible lo dice, en vez de culpar al reloj',
    str_contains($html, 'no se puede leer') && str_contains($html, 'llave de cifrado') && !str_contains($html, 'reloj del teléfono'));
comprobar('y no pide un código de la aplicación, que no puede cuadrar', !str_contains($html, 'name="codigo"'));
$c4->post('/admin/verificar', ['codigo' => $codigoDe($secreto)]);
comprobar('aunque se envíe uno igual, no entra', !$enElPanel($c4->cuerpo));
$c4->post('/admin/verificar', ['accion' => 'enviar_correo']);
$html = $c4->post('/admin/verificar', ['accion' => 'codigo_correo', 'codigo_correo' => $codigoDelCorreo()]);
comprobar('con el código del correo entra a configurar la aplicación otra vez',
    str_contains($html, 'Activa la verificación en dos pasos'), substr(strip_tags($html), 0, 200));
preg_match('#letter-spacing:\.14em[^>]*>([A-Z2-7 ]{32,})<#', $html, $m);
$secretoRehecho = str_replace(' ', '', trim($m[1] ?? ''));
$html = $c4->post('/admin/activar-2fa', ['codigo' => $codigoDe($secretoRehecho)]);
comprobar('y con el QR nuevo queda dentro', $enElPanel($html));
comprobar('queda anotado por qué se quitó el anterior',
    (int) $pdo->query("SELECT COUNT(*) FROM {$P}bitacora WHERE accion = 'segundo_factor_ilegible'")->fetchColumn() >= 1);
$secreto = $secretoRehecho;
ajustarConfig($RAIZ, ['exigir_2fa_admin' => $configAntes2fa['exigir_2fa_admin'] ?? null]);

// ---- Organizadores: restablecer el de otra persona ----------------------
$otraCuenta = (int) $pdo->query("SELECT id FROM {$P}usuario WHERE correo <> 'aerazo@narino.gov.co' LIMIT 1")->fetchColumn();
if ($otraCuenta > 0) {
    $pdo->exec("UPDATE {$P}usuario SET totp_secreto = UNHEX(CONCAT('01', REPEAT('5A', 72))), totp_confirmado = 1
                 WHERE id = $otraCuenta");
    $html = $c4->get('/admin/organizadores');
    comprobar('Organizadores marca el segundo factor ilegible de otra cuenta', str_contains($html, '2FA ilegible'));
    $c4->post('/admin/organizadores/segundo-factor', ['usuario' => (string) $otraCuenta]);
    comprobar('y una administradora se lo puede restablecer',
        $pdo->query("SELECT totp_secreto FROM {$P}usuario WHERE id = $otraCuenta")->fetchColumn() === null);
}
$miId = (int) $pdo->query("SELECT id FROM {$P}usuario WHERE correo = 'aerazo@narino.gov.co'")->fetchColumn();
$c4->post('/admin/organizadores/segundo-factor', ['usuario' => (string) $miId], false);
comprobar('el propio se restablece en Configuración, no desde ahí',
    $c4->codigo === 303 && str_contains($c4->cabecera('Location'), '/admin/cuenta')
    && (int) ($fila2fa()['totp_confirmado'] ?? 0) === 1);

ajustarConfig($RAIZ, ['modo_correo' => $configAntes2fa['modo_correo'] ?? null]);

// Se deja la cuenta como estaba. Los otros guiones —pantallas.js, entre ellos—
// entran con contraseña y se quedarían atascados en la verificación revisando
// ocho veces la misma pantalla.
$pdo->exec("UPDATE {$BD['prefijo']}usuario
               SET totp_secreto = NULL, totp_confirmado = 0, totp_ultimo = 0, totp_deriva = 0
             WHERE correo = 'aerazo@narino.gov.co'");
comprobar('el guion deja la cuenta como la encontró',
    (int) $pdo->query("SELECT totp_confirmado FROM {$BD['prefijo']}usuario
                        WHERE correo = 'aerazo@narino.gov.co'")->fetchColumn() === 0);

/* =========================================================================
   Entrar desde el propio carnet, y lo que el panel puede hacer con eso
   -------------------------------------------------------------------------
   Este bloque cubre lo que se rompía en producción: la persona se preregistra
   en el navegador, abre el enlace del correo desde otra aplicación —que no
   comparte cookies— y acaba en «identifícate» con el carnet ya emitido.
   ========================================================================= */
/* =========================================================================
   Un apunte para la prueba de navegador
   -------------------------------------------------------------------------
   pruebas/interacciones.js necesita entrar como asistente y no puede pedir un
   código por correo. Se le deja aquí el token del QR de acceso de María, en la
   carpeta de salidas de las pruebas, que no va al repositorio.
   ========================================================================= */
$carpetaSalidas = $RAIZ . '/pruebas/capturas';
if (!is_dir($carpetaSalidas)) {
    @mkdir($carpetaSalidas, 0755, true);
}
$tokenDeMaria = (string) ($pdo->query(
    "SELECT acceso_token FROM {$BD['prefijo']}persona WHERE correo = 'mzambrano@narino.gov.co'"
)->fetchColumn() ?: '');
if ($tokenDeMaria !== '') {
    file_put_contents($carpetaSalidas . '/token-asistente.txt', $tokenDeMaria);
}

// Y el de alguien con perfil Staff, que la prueba de navegador necesita para
// entrar a las pantallas de la puerta sin ser del equipo organizador.
$tokenDelStaff = (string) ($pdo->query(
    "SELECT acceso_token FROM {$BD['prefijo']}persona WHERE rol = 'staff' LIMIT 1"
)->fetchColumn() ?: '');
if ($tokenDelStaff !== '') {
    file_put_contents($carpetaSalidas . '/token-staff.txt', $tokenDelStaff);
}

/* =========================================================================
   12 · Configuración → Registro
   -------------------------------------------------------------------------
   Cada evento decide qué pide su formulario: qué campos, cuáles obligatorios,
   qué opciones trae cada lista y si arriba va un banner. Lo que se prueba
   aquí es que lo decidido llegue al formulario público y a la validación del
   servidor, y que nada de eso borre lo que la gente ya había registrado.
   ========================================================================= */
titulo('Configuración del formulario de registro');

defined('EVENTOS_TIC') || define('EVENTOS_TIC', true);
require_once $RAIZ . '/app/Datos.php';
require_once $RAIZ . '/app/Modelos/Persona.php';
require_once $RAIZ . '/app/Modelos/Formulario.php';

$P = $BD['prefijo'];
$configAntesForm = leerConfig($RAIZ);
ajustarConfig($RAIZ, ['modo_correo' => 'registro']);
$eventoId = (int) $pdo->query("SELECT id FROM {$P}evento WHERE activo = 1 ORDER BY id LIMIT 1")->fetchColumn();

/** campos[x] y listas[x][0][y] como los manda un navegador en multipart. */
$aplanar = static function (array $datos, string $prefijo = '') use (&$aplanar): array {
    $salida = [];
    foreach ($datos as $clave => $valor) {
        $nombre = $prefijo === '' ? (string) $clave : $prefijo . '[' . $clave . ']';
        if (is_array($valor)) {
            $salida += $aplanar($valor, $nombre);
        } else {
            $salida[$nombre] = (string) $valor;
        }
    }
    return $salida;
};

/** Lo que manda la pantalla de configuración tal como viene de fábrica. */
$listasDeFabrica = static function (): array {
    $listas = [];
    foreach (App\Modelos\Formulario::LISTAS as $clave => $def) {
        $valor = App\Modelos\Formulario::listaPorDefecto($clave);
        $listas[$clave] = match ($def['clase']) {
            'codigos', 'perfiles' => array_map(
                static fn(array $o): array => ['valor' => $o['valor'], 'etiqueta' => $o['etiqueta'], 'activo' => '1'], $valor
            ),
            'territorio' => App\Modelos\Formulario::territorioComoTexto($valor),
            'minutos'    => implode(', ', $valor),
            default      => implode("\n", $valor),
        };
    }
    return $listas;
};
$camposDeFabrica = array_map(static fn(array $d): string => $d['defecto'], App\Modelos\Formulario::CAMPOS);

$jefa = new Cliente($BASE);
$jefa->get('/admin/entrar');
$jefa->post('/admin/entrar', ['correo' => 'aerazo@narino.gov.co', 'clave' => 'una frase larga y facil de recordar']);

// ---- El módulo y sus pestañas ---------------------------------------------
$html = $jefa->get('/admin');
$base = (string) parse_url($BASE, PHP_URL_PATH);
comprobar('el menú tiene «Configuración»', str_contains($html, 'href="' . $base . '/admin/configuracion"'));
comprobar('e Identidad y Autenticación ya no van sueltas en el menú',
    !preg_match('#class="navlink[^"]*"\s+href="[^"]*/admin/(identidad|autenticacion)"#', $html));
$jefa->get('/admin/configuracion', false);
comprobar('Configuración abre en la pestaña Registro',
    $jefa->codigo === 303 && str_contains($jefa->cabecera('Location'), '/admin/configuracion/registro'),
    $jefa->codigo . ' → ' . $jefa->cabecera('Location'));
$html = $jefa->get('/admin/configuracion/registro');
comprobar('con sus cuatro pestañas',
    str_contains($html, 'tabs--enlaces') && str_contains($html, '>Registro<') && str_contains($html, '>Identidad<')
    && str_contains($html, '>Acceso y correo<') && str_contains($html, '>Mi cuenta<'));
foreach (['/admin/identidad' => 'Identidad del evento', '/admin/autenticacion' => 'Acceso y correo', '/admin/cuenta' => 'Mi cuenta'] as $ruta => $rotulo) {
    $html = $jefa->get($ruta);
    comprobar("«{$rotulo}» es una pestaña de Configuración, en su dirección de siempre",
        $jefa->codigo === 200 && str_contains($html, 'tabs--enlaces') && str_contains($html, $rotulo));
}
// Quien no es administrador llega a su cuenta, ve solo esa pestaña, y no
// alcanza la configuración del registro ni escribiendo la dirección.
$puerta = new Cliente($BASE);
$puerta->get('/admin/entrar');
$puerta->post('/admin/entrar', ['correo' => 'puerta@narino.gov.co', 'clave' => 'una clave mia y bien larga']);
$puerta->get('/admin/configuracion', false);
comprobar('a un operador, Configuración lo lleva a su cuenta',
    $puerta->codigo === 303 && str_contains($puerta->cabecera('Location'), '/admin/cuenta'),
    $puerta->codigo . ' → ' . $puerta->cabecera('Location'));
$html = $puerta->get('/admin/cuenta');
comprobar('donde ve solo la pestaña «Mi cuenta»',
    str_contains($html, 'tabs--enlaces') && str_contains($html, '>Mi cuenta<')
    && !str_contains($html, '/admin/configuracion/registro"') && !str_contains($html, '/admin/autenticacion"'));
$puerta->get('/admin/configuracion/registro', false);
comprobar('y no llega a la configuración del registro', $puerta->codigo !== 200, (string) $puerta->codigo);

$html = $jefa->get('/admin/configuracion/registro');
comprobar('el formulario de fábrica: todos los campos y las listas de siempre',
    str_contains($html, 'usa el formulario de fábrica') && str_contains($html, 'Cédula de ciudadanía'));

// Alguien que se registra con el formulario de fábrica, para ver después que
// ocultar un campo no le borra lo que ya había dado.
$paula = new Cliente($BASE);
$paula->get('/registro');
$paula->post('/registro', [
    'correo' => 'pmontenegro@narino.gov.co', 'nombre' => 'Paula Montenegro Erazo',
    'tipo_documento' => 'CC', 'documento' => '27155331', 'telefono' => '+57 301 555 0101',
    'rol' => 'participante', 'entidad' => 'Gobernación de Nariño', 'etnia' => 'Indígena',
    'genero' => 'F', 'discapacidad' => 'No', 'habeas' => '1',
]);
$idPaula = (int) $pdo->query("SELECT id FROM {$P}persona WHERE correo = 'pmontenegro@narino.gov.co'")->fetchColumn();
comprobar('alguien se registra con el formulario de fábrica', $idPaula > 0);

// ---- Guardar una configuración --------------------------------------------
$campos = ['telefono' => 'oculto', 'entidad' => 'obligatorio', 'etnia' => 'oculto',
           // Manipulado a mano: un dato sensible no se puede exigir.
           'genero' => 'obligatorio'] + $camposDeFabrica;
$listas = $listasDeFabrica();
$listas['tipo_documento'][] = ['codigo' => 'ppt', 'etiqueta' => 'Permiso por Protección Temporal', 'activo' => '1'];
$listas['categoria'] .= "\nRobótica educativa";

$imagen = imagecreatetruecolor(1600, 400);
imagefilledrectangle($imagen, 0, 0, 1600, 400, imagecolorallocate($imagen, 12, 46, 60));
ob_start();
imagepng($imagen);
$png = (string) ob_get_clean();

$jefa->get('/admin/configuracion/registro');
$html = $jefa->subir('/admin/configuracion/registro', $aplanar([
    'accion' => 'guardar', 'campos' => $campos, 'listas' => $listas,
    'banner_activo' => '1', 'banner_titulo' => 'Inscripciones abiertas',
    'banner_texto' => 'Del 1 al 3 de septiembre en Pasto.', 'banner_alt' => 'Afiche de la cumbre',
]), ['banner_imagen' => ['nombre' => 'banner.png', 'tipo' => 'image/png', 'contenido' => $png]]);
comprobar('la configuración se guarda', str_contains($html, 'Formulario guardado'), substr(strip_tags($html), 0, 200));

$fila = $pdo->query("SELECT * FROM {$P}evento_formulario WHERE evento_id = $eventoId")->fetch(PDO::FETCH_ASSOC) ?: [];
$guardados = json_decode((string) ($fila['campos'] ?? ''), true) ?: [];
comprobar('queda en la tabla del formulario del evento', ($guardados['telefono'] ?? '') === 'oculto' && ($guardados['entidad'] ?? '') === 'obligatorio');
comprobar('un dato sensible no se puede volver obligatorio aunque se mande a mano', ($guardados['genero'] ?? '') === 'opcional');
comprobar('el banner queda con su imagen', (string) ($fila['banner_imagen'] ?? '') !== '' && (int) ($fila['banner_activo'] ?? 0) === 1);
$listasGuardadas = json_decode((string) ($fila['listas'] ?? ''), true) ?: [];
comprobar('de las listas se guarda solo lo que cambió: las demás siguen a la plataforma',
    array_keys($listasGuardadas) === ['tipo_documento', 'categoria'], implode(', ', array_keys($listasGuardadas)));

// ---- El formulario público --------------------------------------------------
$visita = new Cliente($BASE);
$html = $visita->get('/registro');
comprobar('el banner sale arriba del formulario, con su título',
    str_contains($html, 'registro-banner') && str_contains($html, 'Inscripciones abiertas') && str_contains($html, 'Afiche de la cumbre'));
preg_match('#src="([^"]*/medios/banner/\d+\?v=[^"]+)"#', $html, $m);
$imagenBanner = new Cliente($BASE);
$imagenBanner->get(html_entity_decode($m[1] ?? '/medios/banner/0'));
comprobar('y la imagen se sirve a cualquiera, regenerada',
    $imagenBanner->codigo === 200 && str_contains($imagenBanner->cabecera('Content-Type'), 'image/'),
    $imagenBanner->codigo . ' ' . $imagenBanner->cabecera('Content-Type'));

// El de un evento que no es el activo —un borrador que puede no estar
// anunciado— no lo ve cualquiera.
$pdo->exec("INSERT INTO {$P}evento (nombre, fecha_inicio, estado) VALUES ('Evento sin anunciar', CURDATE(), 'borrador')");
$borrador = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO {$P}evento_formulario (evento_id, banner_activo, banner_imagen, banner_tipo) VALUES (?, 1, ?, ?)")
    ->execute([$borrador, (string) $fila['banner_imagen'], (string) $fila['banner_tipo']]);
$ajeno = new Cliente($BASE);
$ajeno->get('/medios/banner/' . $borrador);
$jefa->get('/medios/banner/' . $borrador);
comprobar('el banner de un evento que no es el activo solo lo ve el equipo',
    $ajeno->codigo === 404 && $jefa->codigo === 200, $ajeno->codigo . ' / ' . $jefa->codigo);
$pdo->exec("DELETE FROM {$P}evento WHERE id = $borrador");
comprobar('el campo oculto no está', !str_contains($html, 'name="telefono"') && !str_contains($html, 'name="etnia"'));
comprobar('el obligatorio lleva su asterisco y el atributo que lee el aviso',
    (bool) preg_match('#name="entidad"[^>]*required#', $html));
comprobar('el tipo de documento nuevo está en la lista', str_contains($html, 'value="PPT"'));
comprobar('y la categoría nueva', str_contains($html, 'Robótica educativa'));
comprobar('el encabezado dice qué es obligatorio', str_contains($html, 'Son obligatorios el correo, el nombre, la identificación y la entidad'));

// ---- Registro incompleto: no se guarda, y se dice ---------------------------
$nadie = new Cliente($BASE);
$nadie->get('/registro');
$html = $nadie->post('/registro', [
    'correo' => 'sinentidad@narino.gov.co', 'nombre' => 'Persona Sin Entidad',
    'tipo_documento' => 'CC', 'documento' => '1085999001', 'rol' => 'participante', 'habeas' => '1',
]);
comprobar('sin la entidad obligatoria, no se guarda',
    (int) $pdo->query("SELECT COUNT(*) FROM {$P}persona WHERE correo = 'sinentidad@narino.gov.co'")->fetchColumn() === 0);
comprobar('y la ventana «tu registro no fue guardado» se abre sola',
    str_contains($html, 'Tu registro no fue guardado') && str_contains($html, 'data-abrir-al-cargar'));
comprobar('con lo que falta', str_contains($html, 'Escribe tu entidad u organización'));

// ---- Con el tipo de documento nuevo -----------------------------------------
$ppt = new Cliente($BASE);
$ppt->get('/registro');
$html = $ppt->post('/registro', [
    'correo' => 'yramirez@narino.gov.co', 'nombre' => 'Yulimar Ramírez Pérez',
    'tipo_documento' => 'PPT', 'documento' => '5x99812', 'rol' => 'participante',
    'entidad' => 'Fundación Frontera', 'habeas' => '1',
]);
comprobar('se registra con el permiso por protección temporal', str_contains($html, 'Registro completo'), substr(strip_tags($html), 0, 160));
comprobar('y el carnet lo muestra con sus letras', str_contains($html, 'PPT 5X99812'));

// ---- Ocultar un campo no borra lo que ya estaba -----------------------------
$paula->get('/registro');
$paula->post('/registro', [
    'nombre' => 'Paula Montenegro Erazo', 'tipo_documento' => 'CC', 'documento' => '27155331',
    'rol' => 'participante', 'entidad' => 'Gobernación de Nariño — TIC', 'genero' => 'F', 'discapacidad' => 'No',
]);
$dePaula = $pdo->query("SELECT p.telefono, p.entidad, c.etnia FROM {$P}persona p
                         LEFT JOIN {$P}persona_caracterizacion c ON c.persona_id = p.id WHERE p.id = $idPaula")->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('quien ya tenía teléfono lo conserva aunque el campo ahora esté oculto',
    ($dePaula['telefono'] ?? '') === '+57 301 555 0101', json_encode($dePaula, JSON_UNESCAPED_UNICODE));
comprobar('y su grupo étnico también', ($dePaula['etnia'] ?? '') === 'Indígena');
comprobar('lo que sí está en el formulario se actualiza', ($dePaula['entidad'] ?? '') === 'Gobernación de Nariño — TIC');

// ---- Identificación opcional ------------------------------------------------
$jefa->get('/admin/configuracion/registro');
$jefa->subir('/admin/configuracion/registro', $aplanar([
    'accion' => 'guardar', 'campos' => ['documento' => 'opcional'] + $campos, 'listas' => $listas,
    'banner_activo' => '1', 'banner_titulo' => 'Inscripciones abiertas',
]), []);
$sinCedula = new Cliente($BASE);
$sinCedula->get('/registro');
$html = $sinCedula->post('/registro', [
    'correo' => 'sincedula@narino.gov.co', 'nombre' => 'Asistente Sin Cédula', 'tipo_documento' => 'CC',
    'documento' => '', 'rol' => 'participante', 'entidad' => 'Colegio INEM', 'habeas' => '1',
]);
comprobar('con la identificación opcional, se registra sin ella', str_contains($html, 'Registro completo'), substr(strip_tags($html), 0, 160));
comprobar('y su carnet sale con el nombre, sin «CC —»', str_contains($html, 'Asistente Sin Cédula') && !str_contains($html, 'CC —'));

// ---- Fotografía obligatoria ---------------------------------------------------
// Obligatoria, la foto se revisa con los demás campos: un archivo que no sirve
// no puede terminar en un registro guardado con un carnet sin foto.
$jefa->get('/admin/configuracion/registro');
$jefa->subir('/admin/configuracion/registro', $aplanar([
    'accion' => 'guardar', 'campos' => ['foto' => 'obligatorio', 'documento' => 'opcional'] + $campos, 'listas' => $listas,
    'banner_activo' => '1', 'banner_titulo' => 'Inscripciones abiertas',
]), []);
$conFoto = new Cliente($BASE);
$conFoto->get('/registro');
$datosConFoto = [
    'correo' => 'confoto@narino.gov.co', 'nombre' => 'Asistente Con Foto', 'tipo_documento' => 'CC',
    'documento' => '', 'rol' => 'participante', 'entidad' => 'Colegio INEM', 'habeas' => '1',
];
$fotoDe = static fn(): string => (string) $pdo->query(
    "SELECT foto FROM {$P}persona WHERE correo = 'confoto@narino.gov.co'"
)->fetchColumn();
$html = $conFoto->subir('/registro', $datosConFoto, []);
comprobar('con la foto obligatoria, sin foto no se guarda', $fotoDe() === '' && str_contains($html, 'Sube tu fotografía'),
    substr(strip_tags($html), 0, 160));
$html = $conFoto->subir('/registro', $datosConFoto, [
    'foto' => ['nombre' => 'foto.jpg', 'tipo' => 'image/jpeg', 'contenido' => 'esto no es una imagen'],
]);
comprobar('ni con un archivo que no sirve: se dice antes de guardar nada',
    (int) $pdo->query("SELECT COUNT(*) FROM {$P}persona WHERE correo = 'confoto@narino.gov.co'")->fetchColumn() === 0
    && str_contains($html, 'formato que podamos usar'), substr(strip_tags($html), 0, 160));
$imagen = imagecreatetruecolor(600, 600);
imagefilledrectangle($imagen, 0, 0, 600, 600, imagecolorallocate($imagen, 200, 120, 80));
ob_start();
imagejpeg($imagen, null, 85);
$jpg = (string) ob_get_clean();
$html = $conFoto->subir('/registro', $datosConFoto, ['foto' => ['nombre' => 'foto.jpg', 'tipo' => 'image/jpeg', 'contenido' => $jpg]]);
comprobar('con una foto que sirve, se registra con ella', str_contains($html, 'Registro completo') && $fotoDe() !== '',
    substr(strip_tags($html), 0, 160));
comprobar('y no se ofrece quitarla, porque el evento la pide',
    !str_contains($conFoto->get('/registro'), 'name="quitar_foto"'));

// ---- Volver al de fábrica -----------------------------------------------------
$jefa->get('/admin/configuracion/registro');
$html = $jefa->post('/admin/configuracion/registro', ['accion' => 'restablecer']);
comprobar('se puede volver al formulario de fábrica', str_contains($html, 'volvió a ser el de fábrica'));
$html = (new Cliente($BASE))->get('/registro');
comprobar('y el teléfono vuelve a aparecer', str_contains($html, 'name="telefono"') && !str_contains($html, 'value="PPT"'));
comprobar('el banner se conserva al restablecer', str_contains($html, 'registro-banner'));
$html = $jefa->get('/admin/configuracion/registro');
comprobar('y la pantalla dice que el formulario es el de fábrica, aunque tenga banner',
    str_contains($html, 'usa el formulario de fábrica') && !str_contains($html, 'Volver al formulario de fábrica'));
$jefa->subir('/admin/configuracion/registro', $aplanar([
    'accion' => 'guardar', 'campos' => $camposDeFabrica, 'listas' => $listasDeFabrica(), 'quitar_banner' => '1',
]), []);
$html = (new Cliente($BASE))->get('/registro');
comprobar('y se apaga y se quita cuando se quiere', !str_contains($html, 'registro-banner'));
$fila = $pdo->query("SELECT campos, listas FROM {$P}evento_formulario WHERE evento_id = $eventoId")->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('guardar sin cambiar campos ni listas no los congela',
    array_key_exists('campos', $fila) && $fila['campos'] === null && $fila['listas'] === null);

// Una fila tocada a mano en la base no deja el registro sin opciones: lo que no
// tiene forma se ignora y queda la lista de fábrica.
$pdo->prepare("UPDATE {$P}evento_formulario SET listas = ? WHERE evento_id = ?")->execute([json_encode([
    'tipo_documento' => [['valor' => 'CC', 'etiqueta' => 'Cédula', 'activo' => false]],   // ninguna activa
    'rango_edad'     => [['no' => 'es texto']],
    'ubicacion'      => ['Nariño' => []],
], JSON_UNESCAPED_UNICODE), $eventoId]);
$html = (new Cliente($BASE))->get('/registro');
comprobar('una configuración dañada a mano en la base no deja el registro sin opciones',
    str_contains($html, 'Cédula de ciudadanía') && str_contains($html, '26–35') && str_contains($html, 'Putumayo'));
$pdo->exec("UPDATE {$P}evento_formulario SET listas = NULL WHERE evento_id = $eventoId");
comprobar('la imagen quitada se borra del disco',
    glob($RAIZ . '/almacen/logos/banner-' . $eventoId . '-*') === []);

/* =========================================================================
   13 · El correo al expositor con la decisión
   ========================================================================= */
titulo('Correo al expositor');

$ultimoCorreo = static function () use ($RAIZ): string {
    $archivos = glob($RAIZ . '/almacen/registro/*.log.php') ?: [];
    sort($archivos);
    $lineas = array_filter(explode("\n", (string) @file_get_contents((string) end($archivos))),
        static fn(string $l): bool => str_contains($l, 'Correo no enviado'));
    return (string) end($lineas);
};

$deLucia = $pdo->query("SELECT pr.* FROM {$P}propuesta pr JOIN {$P}persona p ON p.id = pr.persona_id
                         WHERE p.correo = 'lvillota@narino.gov.co' ORDER BY pr.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$idLucia = (int) $deLucia['id'];

$jefa->get('/admin/expositores');
$html = $jefa->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idLucia, 'decision' => 'aprobada', 'avisar' => '1',
    'titulo' => 'IA en el aula rural de Nariño', 'categoria' => (string) $deLucia['categoria'],
    'duracion' => '60', 'dia' => '2', 'hora' => '10:30', 'salon' => 'Auditorio Galeras',
    'observacion' => 'Trae tu presentación en una memoria por si falla la red.',
]);
comprobar('al aprobar se le avisa por correo', str_contains($html, 'Se le avisó por correo'), substr(strip_tags($html), 0, 200));
$correo = $ultimoCorreo();
comprobar('el correo dice que fue aprobada, con el título nuevo',
    str_contains($correo, 'Tu propuesta fue aprobada: IA en el aula rural de Nariño'), mb_substr($correo, 0, 300));
comprobar('con los cambios que hizo el comité',
    str_contains($correo, 'Título:') && str_contains($correo, 'Duración: de'));
comprobar('con el horario y el salón', str_contains($correo, '10:30') && str_contains($correo, 'Auditorio Galeras'));
comprobar('y con las observaciones', str_contains($correo, 'Trae tu presentación'));
$ahora = $pdo->query("SELECT titulo, duracion_min FROM {$P}propuesta WHERE id = $idLucia")->fetch(PDO::FETCH_ASSOC);
comprobar('los cambios quedan en la propuesta',
    $ahora['titulo'] === 'IA en el aula rural de Nariño' && (int) $ahora['duracion_min'] === 60);

$jefa->get('/admin/expositores');
$jefa->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idLucia, 'decision' => 'observada', 'avisar' => '1',
    'observacion' => 'Falta la hoja de vida actualizada.',
]);
comprobar('al devolverla también se le avisa, con lo que tiene que corregir',
    str_contains($ultimoCorreo(), 'Tu propuesta tiene observaciones') && str_contains($ultimoCorreo(), 'Falta la hoja de vida'));

$jefa->get('/admin/expositores');
$jefa->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idLucia, 'decision' => 'rechazada', 'avisar' => '1',
    'observacion' => 'El cupo de la categoría se llenó.',
]);
comprobar('y al rechazarla', str_contains($ultimoCorreo(), 'Sobre tu propuesta') && str_contains($ultimoCorreo(), 'no fue seleccionada'));

$antesDelSilencio = $ultimoCorreo();
$jefa->get('/admin/expositores');
$jefa->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idLucia, 'decision' => (string) $deLucia['estado'] === 'aprobada' ? 'aprobada' : 'observada',
    'observacion' => 'Se deja como estaba.',
    'titulo' => (string) $deLucia['titulo'], 'duracion' => (string) $deLucia['duracion_min'],
]);
comprobar('sin la casilla de avisar, no sale ningún correo', $ultimoCorreo() === $antesDelSilencio);
// Se deja la propuesta como estaba para lo que venga después.
$pdo->exec("UPDATE {$P}propuesta SET estado = " . $pdo->quote((string) $deLucia['estado'])
    . ', titulo = ' . $pdo->quote((string) $deLucia['titulo']) . ', duracion_min = ' . (int) $deLucia['duracion_min']
    . " WHERE id = $idLucia");

/* =========================================================================
   14 · Perfiles de asistencia configurables, y la entidad en los datos
   principales
   ========================================================================= */
titulo('Perfiles de asistencia');

$html = (new Cliente($BASE))->get('/registro');
comprobar('el formulario ofrece Rueda de Negocios y Comunicaciones',
    str_contains($html, 'value="rueda_de_negocios"') && str_contains($html, '>Rueda de Negocios<')
    && str_contains($html, 'value="comunicaciones"') && str_contains($html, '>Comunicaciones<'));
$dondeEntidad = strpos($html, 'id="entidad"');
comprobar('la entidad va en los datos principales, antes de la foto y de la caracterización',
    $dondeEntidad !== false && $dondeEntidad < strpos($html, 'Fotografía del carnet')
    && $dondeEntidad < strpos($html, 'id="bloque-opcional"'));

$rueda = new Cliente($BASE);
$rueda->get('/registro');
$html = $rueda->post('/registro', [
    'correo' => 'rueda@narino.gov.co', 'nombre' => 'Empresaria De La Rueda', 'tipo_documento' => 'CC',
    'documento' => '1085666001', 'rol' => 'rueda_de_negocios', 'entidad' => 'Cámara de Comercio de Pasto', 'habeas' => '1',
]);
comprobar('alguien se registra con el perfil Rueda de Negocios', str_contains($html, 'Registro completo'), substr(strip_tags($html), 0, 160));
$html = $rueda->get('/carnet');
comprobar('y su carnet lo dice, con su color', str_contains($html, 'RUEDA DE NEGOCIOS') && str_contains($html, 'data-rol="rueda_de_negocios"'));

// Configurarlos: renombrar uno, apagar otro y agregar dos.
$filasDePerfiles = static function () use ($listasDeFabrica): array {
    $filas = $listasDeFabrica()['perfil'];
    foreach ($filas as $i => $fila) {
        if ($fila['valor'] === 'comunicaciones') {
            $filas[$i]['etiqueta'] = 'Comunicaciones y medios';
        }
        if ($fila['valor'] === 'visitante') {
            unset($filas[$i]['activo']);
        }
    }
    return $filas;
};
$guardarPerfiles = static function (array $filas) use ($jefa, $aplanar, $camposDeFabrica, $listasDeFabrica): string {
    $jefa->get('/admin/configuracion/registro');
    return $jefa->subir('/admin/configuracion/registro', $aplanar([
        'accion' => 'guardar', 'campos' => $camposDeFabrica, 'listas' => ['perfil' => $filas] + $listasDeFabrica(),
    ]), []);
};
$html = $guardarPerfiles($filasDePerfiles() + ['nueva1' => ['etiqueta' => 'Aliados Estratégicos'], 'nueva2' => ['etiqueta' => 'Temporal']]);
comprobar('los perfiles se guardan', str_contains($html, 'Formulario guardado'), substr(strip_tags($html), 0, 200));
$html = (new Cliente($BASE))->get('/registro');
comprobar('el formulario muestra el nombre nuevo y los agregados',
    str_contains($html, '>Comunicaciones y medios<') && str_contains($html, 'value="aliados_estrategicos"')
    && str_contains($html, '>Aliados Estratégicos<') && str_contains($html, 'value="temporal"'));
comprobar('y ya no ofrece el que se apagó', !str_contains($html, 'value="visitante"'));
comprobar('la persona que ya tenía Rueda de Negocios la conserva',
    (string) $pdo->query("SELECT rol FROM {$P}persona WHERE correo = 'rueda@narino.gov.co'")->fetchColumn() === 'rueda_de_negocios');

$aliado = new Cliente($BASE);
$aliado->get('/registro');
$aliado->post('/registro', [
    'correo' => 'aliado@narino.gov.co', 'nombre' => 'Aliado Estratégico Uno', 'tipo_documento' => 'CC',
    'documento' => '1085666002', 'rol' => 'aliados_estrategicos', 'habeas' => '1',
]);
$idAliado = (int) $pdo->query("SELECT id FROM {$P}persona WHERE correo = 'aliado@narino.gov.co'")->fetchColumn();
comprobar('alguien elige el perfil agregado',
    (string) $pdo->query("SELECT rol FROM {$P}persona WHERE id = $idAliado")->fetchColumn() === 'aliados_estrategicos');

// Eliminar: el que tiene alguien no; el que no tiene nadie, sí.
$actuales = $filasDePerfiles();
$actuales[] = ['valor' => 'aliados_estrategicos', 'etiqueta' => 'Aliados Estratégicos', 'activo' => '1'];
$actuales[] = ['valor' => 'temporal', 'etiqueta' => 'Temporal', 'activo' => '1'];
$eliminando = static function (array $filas, string $clave): array {
    foreach ($filas as $i => $fila) {
        if (($fila['valor'] ?? '') === $clave) {
            $filas[$i]['eliminar'] = '1';
        }
    }
    return $filas;
};
$html = $jefa->get('/admin/configuracion/registro');
comprobar('la configuración dice cuántos tienen cada perfil, y no ofrece eliminar el que está en uso',
    str_contains($html, 'En uso') && !str_contains($html, 'Eliminar el perfil «Aliados Estratégicos»')
    && str_contains($html, 'Eliminar el perfil «Temporal»'));
$html = $guardarPerfiles($eliminando($actuales, 'aliados_estrategicos'));
comprobar('uno que alguien tiene no se elimina aunque se mande, y se dice por qué',
    str_contains($html, 'no se puede eliminar') && str_contains($html, '1 persona'), substr(strip_tags($html), 0, 300));
comprobar('y sigue en el formulario', str_contains((new Cliente($BASE))->get('/registro'), 'value="aliados_estrategicos"'));
$html = $guardarPerfiles($eliminando($actuales, 'temporal'));
comprobar('uno que no tiene nadie sí se elimina',
    str_contains($html, 'Formulario guardado') && !str_contains((new Cliente($BASE))->get('/registro'), 'value="temporal"'));

// El equipo ve los nombres del evento: la ficha, el filtro, la exportación.
$html = $jefa->get('/admin/registros/' . $idAliado);
comprobar('la ficha muestra el perfil con su nombre', str_contains($html, 'Aliados Estratégicos'));
comprobar('y deja poner también uno apagado', str_contains($html, 'value="visitante"') && str_contains($html, 'value="staff"'));
$jefa->post('/admin/registros/perfil', ['persona' => (string) $idAliado, 'rol' => 'comunicaciones']);
comprobar('se le puede poner otro perfil del evento',
    (string) $pdo->query("SELECT rol FROM {$P}persona WHERE id = $idAliado")->fetchColumn() === 'comunicaciones');
$html = $jefa->get('/admin/registros?rol=comunicaciones');
comprobar('el filtro por perfil la encuentra, con el nombre nuevo',
    str_contains($html, 'Aliado Estratégico Uno') && str_contains($html, 'Comunicaciones y medios'));
$csv = $jefa->get('/admin/registros/exportar');
comprobar('y la exportación usa el nombre del evento', str_contains($csv, 'Comunicaciones y medios'));
$jefa->post('/admin/registros/perfil', ['persona' => (string) $idAliado, 'rol' => 'inventado_a_mano']);
comprobar('un perfil que no existe en el evento no se pone',
    (string) $pdo->query("SELECT rol FROM {$P}persona WHERE id = $idAliado")->fetchColumn() === 'comunicaciones');
$jefa->post('/admin/registros/perfil', ['persona' => (string) $idAliado, 'rol' => 'aliados_estrategicos']);

// Volver al de fábrica no deja huérfano a quien tiene un perfil propio.
$jefa->get('/admin/configuracion/registro');
$html = $jefa->post('/admin/configuracion/registro', ['accion' => 'restablecer']);
comprobar('al volver al de fábrica, el perfil propio que alguien tiene se conserva y se avisa',
    str_contains($html, '«Aliados Estratégicos»'), substr(strip_tags($html), 0, 300));
$html = (new Cliente($BASE))->get('/registro');
comprobar('con su nombre, y vuelven los de fábrica con los suyos',
    str_contains($html, '>Aliados Estratégicos<') && str_contains($html, '>Comunicaciones<') && str_contains($html, 'value="visitante"'));

// Dejarlo como estaba.
$pdo->exec("DELETE FROM {$P}persona WHERE correo IN ('rueda@narino.gov.co', 'aliado@narino.gov.co')");
$jefa->get('/admin/configuracion/registro');
$jefa->post('/admin/configuracion/registro', ['accion' => 'restablecer']);
comprobar('sin nadie con perfiles propios, el de fábrica queda limpio',
    $pdo->query("SELECT listas FROM {$P}evento_formulario WHERE evento_id = $eventoId")->fetchColumn() === null);

/* =========================================================================
   15 · El formulario privado de expositores
   -------------------------------------------------------------------------
   El mismo formulario de registro, con su propia configuración, al que se
   llega solo por un enlace que la organización envía aparte. No está en
   ningún menú.
   ========================================================================= */
titulo('Registro de expositores');

$html = $jefa->get('/admin/configuracion/registro');
comprobar('Configuración tiene la pestaña Registro de expositores', str_contains($html, '>Registro de expositores<'));
$html = $jefa->get('/admin/configuracion/expositores');
preg_match('#/registro/expositores/([a-f0-9]{32})#', $html, $m);
$tokenExp = $m[1] ?? '';
$rutaExp = '/registro/expositores/' . $tokenExp;
comprobar('la pestaña da el enlace privado, listo para copiar',
    $tokenExp !== '' && str_contains($html, 'data-copiar="') && str_contains($html, 'Generar un enlace nuevo'),
    substr(strip_tags($html), 0, 200));
comprobar('y la de Expositores dice dónde está', str_contains($jefa->get('/admin/expositores'), '/admin/configuracion/expositores'));

$fuera = new Cliente($BASE);
$enAlgunMenu = false;
foreach (['/', '/registro', '/agenda', '/entrar'] as $ruta) {
    $enAlgunMenu = $enAlgunMenu || str_contains($fuera->get($ruta), '/registro/expositores');
}
comprobar('el enlace no aparece en ningún menú ni en ninguna página pública', !$enAlgunMenu);
$fuera->get('/registro/expositores/' . str_repeat('ab', 16), false);
comprobar('un enlace que no es el vigente responde 404', $fuera->codigo === 404, (string) $fuera->codigo);

$html = $fuera->get($rutaExp);
comprobar('el vigente abre el formulario de expositores', $fuera->codigo === 200 && str_contains($html, 'Registro de expositores'));
comprobar('que se envía a su propia dirección',
    preg_match('#<form[^>]+action="[^"]*' . preg_quote($rutaExp, '#') . '"#', $html) === 1);
comprobar('con «Voy a exponer» marcado y sin preguntar el perfil',
    preg_match('#name="expositor"[^>]*\bchecked#', $html) === 1 && !str_contains($html, 'name="rol"'));

// Su configuración es suya: exige el teléfono, deja la identificación
// opcional y trae una categoría propia. El público no cambia.
$camposExp = ['telefono' => 'obligatorio', 'documento' => 'opcional']
    + App\Modelos\Formulario::camposPorDefecto(App\Modelos\Formulario::EXPOSITORES);
$listasExp = $listasDeFabrica();
unset($listasExp['perfil']);   // en esta pestaña no se editan: son del evento
$listasExp['categoria'] .= "\nInvestigación aplicada";
$jefa->get('/admin/configuracion/expositores');
$html = $jefa->subir('/admin/configuracion/expositores', $aplanar([
    'accion' => 'guardar', 'campos' => $camposExp, 'listas' => $listasExp,
    'banner_activo' => '1', 'banner_titulo' => 'Convocatoria de expositores',
]), []);
comprobar('la configuración del de expositores se guarda', str_contains($html, 'enlace de expositores'), substr(strip_tags($html), 0, 200));
$filaExp = $pdo->query("SELECT * FROM {$P}evento_formulario_expositores WHERE evento_id = $eventoId")->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('en su propia tabla, junto a su enlace',
    ($filaExp['token'] ?? '') === $tokenExp && str_contains((string) ($filaExp['campos'] ?? ''), '"telefono":"obligatorio"'));
comprobar('y sin tocar los perfiles, que son del evento', !str_contains((string) ($filaExp['listas'] ?? ''), '"perfil"'));
$html = (new Cliente($BASE))->get('/registro');
comprobar('el formulario público no cambia',
    !str_contains($html, 'Investigación aplicada') && !str_contains($html, 'Convocatoria de expositores'));
$html = (new Cliente($BASE))->get($rutaExp);
comprobar('el de expositores sí, con su banner y su categoría',
    str_contains($html, 'Convocatoria de expositores') && str_contains($html, 'Investigación aplicada'));
comprobar('y su selector de municipios pide su propia lista', str_contains($html, '/municipios/expositores/'));
$municipios = json_decode((new Cliente($BASE))->get('/municipios/expositores/' . rawurlencode('Nariño')), true);
comprobar('que responde', !empty($municipios['municipios']));

// Una expositora invitada se registra por el enlace.
$invitada = new Cliente($BASE);
$invitada->get($rutaExp);
$datosInvitada = [
    'correo' => 'invitada@narino.gov.co', 'nombre' => 'Expositora Invitada', 'tipo_documento' => 'CC',
    'documento' => '', 'entidad' => 'Universidad Mariana', 'habeas' => '1', 'expositor' => '1',
    'tema' => 'Inteligencia artificial para la gestión pública', 'categoria' => 'Investigación aplicada',
    'detalle' => 'Cómo los municipios pueden usar modelos de lenguaje para atender mejor a la ciudadanía.',
    'dia_preferido' => '1', 'duracion' => '40',
];
$html = $invitada->subir($rutaExp, $datosInvitada, $adjuntosDe('invitada'));
$invitadaExiste = static fn(): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM {$P}persona WHERE correo = 'invitada@narino.gov.co'"
)->fetchColumn();
comprobar('sin el teléfono que este formulario exige, no se guarda',
    $invitadaExiste() === 0 && str_contains($html, 'Escribe un teléfono de contacto'), substr(strip_tags($html), 0, 200));
$html = $invitada->subir($rutaExp, $datosInvitada + ['telefono' => '+57 300 555 0199'], $adjuntosDe('invitada'));
comprobar('con todo, se registra', str_contains($html, 'Registro completo'), substr(strip_tags($html), 0, 200));
$inv = $pdo->query("SELECT * FROM {$P}persona WHERE correo = 'invitada@narino.gov.co'")->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('y queda como Expositor', ($inv['rol'] ?? '') === 'expositor', (string) ($inv['rol'] ?? ''));
comprobar('sin identificación, porque este formulario no la pide, y con carnet',
    array_key_exists('documento_huella', $inv) && $inv['documento_huella'] === null
    && (int) $pdo->query("SELECT COUNT(*) FROM {$P}credencial WHERE persona_id = " . (int) ($inv['id'] ?? 0))->fetchColumn() === 1);
$propInvitada = $pdo->query("SELECT * FROM {$P}propuesta WHERE persona_id = " . (int) ($inv['id'] ?? 0))->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('su propuesta llega con la categoría de este formulario y sus documentos',
    ($propInvitada['categoria'] ?? '') === 'Investigación aplicada' && ($propInvitada['hoja_vida'] ?? '') !== '');
$invitada->get('/carnet', false);
comprobar('y entra a su carnet sin que la manden a completar el formulario público', $invitada->codigo === 200,
    $invitada->codigo . ' → ' . $invitada->cabecera('Location'));
$detalleRegistro = (string) $pdo->query("SELECT detalle FROM {$P}bitacora WHERE accion = 'registro' AND entidad_id = "
    . (int) ($inv['id'] ?? 0))->fetchColumn();
comprobar('la bitácora anota por qué formulario llegó', str_contains($detalleRegistro, 'expositores'), $detalleRegistro);

// Quien ya estaba registrada como participante y entra por el enlace queda
// como Expositor.
$paula->get($rutaExp);
$paula->post($rutaExp, [
    'nombre' => 'Paula Montenegro Erazo', 'tipo_documento' => 'CC', 'documento' => '27155331',
    'telefono' => '+57 301 555 0101', 'entidad' => 'Gobernación de Nariño', 'habeas' => '1',
]);
comprobar('quien era participante y entra por el enlace queda como Expositor',
    (string) $pdo->query("SELECT rol FROM {$P}persona WHERE correo = 'pmontenegro@narino.gov.co'")->fetchColumn() === 'expositor');

// Un correo ya registrado: se ofrece entrar con el código y volver a este enlace.
$otro = new Cliente($BASE);
$otro->get($rutaExp);
$html = $otro->subir($rutaExp, $datosInvitada + ['telefono' => '+57 300 555 0199'], $adjuntosDe('otro'));
comprobar('a un correo ya registrado se le ofrece entrar y volver a este mismo enlace',
    str_contains(html_entity_decode($html), 'destino=' . rawurlencode($rutaExp)));

// Un enlace nuevo deja sin efecto el anterior.
$jefa->get('/admin/configuracion/expositores');
$html = $jefa->post('/admin/configuracion/expositores/enlace', []);
preg_match('#/registro/expositores/([a-f0-9]{32})#', $html, $m);
$tokenNuevo = $m[1] ?? '';
comprobar('se genera un enlace nuevo', $tokenNuevo !== '' && $tokenNuevo !== $tokenExp && str_contains($html, 'El anterior ya no'));
$fuera->get($rutaExp, false);
comprobar('y el anterior deja de abrir el formulario', $fuera->codigo === 404, (string) $fuera->codigo);
$fuera->get('/registro/expositores/' . $tokenNuevo);
comprobar('el nuevo sí', $fuera->codigo === 200);

// Volver al de fábrica no toca el enlace ni el formulario público.
$jefa->get('/admin/configuracion/expositores');
$jefa->post('/admin/configuracion/expositores', ['accion' => 'restablecer']);
$filaExp = $pdo->query("SELECT * FROM {$P}evento_formulario_expositores WHERE evento_id = $eventoId")->fetch(PDO::FETCH_ASSOC) ?: [];
comprobar('volver al de fábrica limpia campos y listas, y conserva el enlace',
    array_key_exists('campos', $filaExp) && $filaExp['campos'] === null && $filaExp['listas'] === null
    && $filaExp['token'] === $tokenNuevo);

// Dejarlo como estaba.
$pdo->exec("DELETE FROM {$P}persona WHERE correo = 'invitada@narino.gov.co'");
$pdo->exec("UPDATE {$P}persona SET rol = 'participante' WHERE correo = 'pmontenegro@narino.gov.co'");
$pdo->exec("UPDATE {$P}evento_formulario_expositores SET banner_activo = 0, banner_titulo = '' WHERE evento_id = $eventoId");

ajustarConfig($RAIZ, ['modo_correo' => $configAntesForm['modo_correo'] ?? null]);

/* =========================================================================
   Resultado
   ========================================================================= */
echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}

$errores = glob($RAIZ . '/almacen/registro/*.log.php') ?: [];
if ($errores) {
    $contenido = (string) file_get_contents($errores[0]);
    $conteo = substr_count($contenido, 'ERROR');
    if ($conteo > 0) {
        echo "\nErrores registrados por la aplicación: $conteo\n";
        foreach (array_slice(array_filter(explode("\n", $contenido), static fn($l) => str_contains($l, 'ERROR')), 0, 5) as $linea) {
            echo '  ! ' . mb_substr($linea, 0, 160) . "\n";
        }
    }
}

exit($fallos ? 1 : 0);
