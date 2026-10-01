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

$html = $dosFactores->post('/admin/activar-2fa', ['codigo' => '000000']);
comprobar('un código equivocado no lo activa', str_contains($html, 'no coincide'));

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
comprobar('el mismo código no sirve dos veces', str_contains($html, 'no coincide'));

$vuelve->get('/admin', false);
comprobar('y el panel ya no rebota a la verificación', $vuelve->codigo === 200,
    $vuelve->codigo . ' → ' . $vuelve->cabecera('Location'));

// Se deja la cuenta como estaba. Los otros guiones —pantallas.js, entre ellos—
// entran con contraseña y se quedarían atascados en la verificación revisando
// ocho veces la misma pantalla.
$pdo->exec("UPDATE {$BD['prefijo']}usuario
               SET totp_secreto = NULL, totp_confirmado = 0, totp_ultimo = 0
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
