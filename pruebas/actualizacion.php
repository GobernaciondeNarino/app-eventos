<?php
/**
 * Actualizar una instalación que ya está en producción.
 *
 * La plataforma está en uso: hay personas registradas con su cédula cifrada,
 * administradores con el segundo factor configurado, ingresos sellados. Una
 * actualización tiene que dejar todo eso funcionando sin que nadie lo note.
 *
 * Esta prueba arma una base exactamente como la dejó una versión vieja —con el
 * Esquema.php de ese commit, sacado de git, no reconstruido a mano— la llena
 * con datos como los de producción, y la pone al día por cada uno de los
 * caminos que existen. Después comprueba lo que de verdad importa:
 *
 *   · que estén todas las tablas y columnas de esta versión;
 *   · que no se haya perdido ni un registro;
 *   · que la llave de cifrado sea la misma y las cédulas se sigan leyendo;
 *   · que el segundo factor que ya tenía el administrador siga sirviendo, con
 *     el mismo secreto y la misma aplicación del teléfono;
 *   · que existan las carpetas que esta versión necesita.
 *
 * Uso:
 *   BASE=/cumbreAI php -S 127.0.0.1:8900 -t . pruebas/servidor.php &
 *   php pruebas/actualizacion.php [commit-de-partida]
 *
 * Por omisión parte de la 1.0.0, la primera que se publicó para Plesk: es el
 * salto más largo posible. Deja la base de pruebas al día al terminar.
 */
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require RAIZ . '/app/ayudas.php';
require __DIR__ . '/apoyo/cliente-http.php';

use App\Esquema;
use App\Nucleo\Actualizacion;
use App\Nucleo\Bd;
use App\Nucleo\Config;
use App\Nucleo\Cripto;
use App\Nucleo\Totp;

$BASE = rtrim(getenv('URL') ?: 'http://127.0.0.1:8900/cumbreAI', '/');
$PARTIDA = $argv[1] ?? '2e30142';

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre . ($extra !== '' ? " → $extra" : '');
        echo "  ✗ $nombre" . ($extra !== '' ? "  → $extra" : '') . "\n";
    }
}

function titulo(string $t): void
{
    echo "\n$t\n" . str_repeat('─', 62) . "\n";
}

/* =========================================================================
   La configuración de verdad, con la llave de verdad
   ========================================================================= */
Config::cargar();
if (!Config::instalado()) {
    fwrite(STDERR, "Hace falta una instalación de pruebas: corre antes pruebas/instalacion.php.\n");
    exit(1);
}
$LLAVE = (string) Config::obtener('llave_cifrado');
$CONFIG_ANTES = Config::todo();
Bd::conectar();
$P = Bd::prefijo();

echo "Actualización de una instalación en producción · desde $PARTIDA\n" . str_repeat('=', 62) . "\n";

/* =========================================================================
   La base vieja
   ========================================================================= */

/** El SQL con el que esa versión creaba sus tablas, generado por su propio código. */
function sqlDeLaVersion(string $commit, string $prefijo): array
{
    $tmp = sys_get_temp_dir() . '/esquema-' . $commit . '-' . getmypid() . '.php';
    $fuente = shell_exec('git -C ' . escapeshellarg(RAIZ) . ' show ' . escapeshellarg($commit . ':app/Esquema.php') . ' 2>/dev/null');
    if (!is_string($fuente) || $fuente === '') {
        fwrite(STDERR, "No se pudo sacar app/Esquema.php del commit $commit.\n");
        exit(1);
    }
    file_put_contents($tmp, $fuente);
    // En un proceso aparte: la clase se llama igual que la de ahora.
    $codigo = 'define("EVENTOS_TIC", true); define("RAIZ", ' . var_export(RAIZ, true) . '); '
        . 'require ' . var_export($tmp, true) . '; '
        . 'echo \App\Esquema::VERSION, "\n"; echo \App\Esquema::guion(' . var_export($prefijo, true) . ', "limpio");';
    $salida = (string) shell_exec('php -r ' . escapeshellarg($codigo));
    @unlink($tmp);
    [$version, $sql] = explode("\n", $salida, 2) + ['', ''];
    return [trim($version), $sql];
}

/** Vacía la base y la deja como la dejaba esa versión. */
function reconstruirVieja(string $sql): void
{
    $pdo = Bd::conectar();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
        $pdo->exec("DROP TABLE IF EXISTS `$tabla`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Con el cliente de la línea de órdenes, como lo haría quien restaura un
    // respaldo: sin partir el guion a mano y sin que PDO lo reinterprete.
    $c = Config::todo();
    $archivo = sys_get_temp_dir() . '/vieja-' . getmypid() . '.sql';
    file_put_contents($archivo, $sql);
    $orden = sprintf('mysql -h %s -u %s -p%s %s < %s 2>&1',
        escapeshellarg((string) $c['bd_host']), escapeshellarg((string) $c['bd_usuario']),
        escapeshellarg((string) $c['bd_clave']), escapeshellarg((string) $c['bd_nombre']),
        escapeshellarg($archivo));
    $salida = (string) shell_exec($orden);
    @unlink($archivo);
    if (trim($salida) !== '' && stripos($salida, 'warning') === false) {
        fwrite(STDERR, "El SQL viejo no se pudo aplicar:\n$salida\n");
        exit(1);
    }
    Bd::reiniciar();
    Bd::conectar();
}

/** Inserta solo en las columnas que esa versión tenía. */
function insertarComoEra(string $tabla, array $datos): int
{
    $columnas = array_column(Bd::filas('SHOW COLUMNS FROM {' . $tabla . '}'), 'Field');
    return Bd::insertar($tabla, array_intersect_key($datos, array_flip($columnas)));
}

/**
 * Datos como los de producción. Devuelve lo que hace falta para comprobar
 * después que nada se perdió y que todo se sigue leyendo.
 */
function sembrar(): array
{
    $secreto = Totp::generarSecreto();
    $clave = 'una contraseña de producción larga';

    $eventoId = insertarComoEra('evento', [
        'nombre' => 'Cumbre en producción', 'dependencia' => 'Secretaría TIC', 'sede' => 'Pasto',
        'fecha_inicio' => date('Y-m-d'), 'estado' => 'en_curso', 'activo' => 1,
    ]);
    insertarComoEra('evento_tema', ['evento_id' => $eventoId]);
    $dias = [];
    foreach ([0, 1] as $i) {
        $dias[] = insertarComoEra('evento_dia', [
            'evento_id' => $eventoId, 'numero' => $i + 1,
            'fecha' => date('Y-m-d', strtotime("+$i day")),
            'token' => bin2hex(random_bytes(16)),
        ]);
    }

    $adminId = insertarComoEra('usuario', [
        'nombre' => 'Administración en producción', 'correo' => 'prod.admin@narino.gov.co',
        'clave_hash' => Cripto::hashClave($clave), 'rol' => 'administrador', 'estado' => 'activo',
        'totp_secreto' => Cripto::cifrar($secreto), 'totp_confirmado' => 1,
    ]);

    $documentos = ['1085111222', '27999333', 'AB123456'];
    $personas = [];
    foreach ($documentos as $i => $doc) {
        $personas[] = insertarComoEra('persona', [
            'evento_id' => $eventoId, 'nombre' => 'Asistente de producción ' . ($i + 1),
            'correo' => 'asistente' . ($i + 1) . '@narino.gov.co', 'tipo_documento' => $i === 2 ? 'PP' : 'CC',
            'documento_cifrado' => Cripto::cifrar($doc),
            'documento_huella' => Cripto::huella($doc),
            'rol' => $i === 0 ? 'expositor' : 'participante',
            'autorizo_datos_en' => date('Y-m-d H:i:s'),
        ]);
    }
    insertarComoEra('asistencia', [
        'persona_id' => $personas[0], 'evento_dia_id' => $dias[0], 'via' => 'qr_dia',
    ]);
    insertarComoEra('propuesta', [
        'persona_id' => $personas[0], 'titulo' => 'Charla de producción', 'categoria' => 'Gobierno digital',
        'detalle' => 'Una propuesta que ya estaba enviada antes de actualizar.',
    ]);

    return [
        'evento' => $eventoId, 'admin' => $adminId, 'secreto' => $secreto, 'clave' => $clave,
        'correo_admin' => 'prod.admin@narino.gov.co',
        'documentos' => array_combine($personas, $documentos),
        'conteos' => cuentas(),
    ];
}

function cuentas(): array
{
    $n = [];
    foreach (['evento', 'evento_dia', 'persona', 'asistencia', 'propuesta', 'usuario'] as $t) {
        $n[$t] = (int) Bd::valor('SELECT COUNT(*) FROM {' . $t . '}');
    }
    return $n;
}

/** Lo que se comprueba después de cada camino de actualización. */
function verificar(string $camino, array $s, string $llave): void
{
    Bd::reiniciar();
    Bd::conectar();
    $P = Bd::prefijo();

    // Esquema completo y anotado.
    $faltanTablas = array_diff(Esquema::nombres(), Esquema::existentes());
    comprobar("[$camino] están todas las tablas de esta versión",
        $faltanTablas === [], implode(', ', $faltanTablas));

    // Sin prefijo: existeTabla() y existeColumna() se lo ponen solas. Con él,
    // buscaban «evt_evt_persona», no la encontraban, y se saltaban la tabla
    // entera: la comprobación pasaba sin haber mirado nada.
    $faltanColumnas = [];
    foreach (Esquema::tablas() as $tabla => $def) {
        if (!Bd::existeTabla($tabla)) {
            $faltanColumnas[] = "$tabla (la tabla entera)";
            continue;
        }
        foreach (array_keys($def['columnas']) as $col) {
            if (!Bd::existeColumna($tabla, $col)) {
                $faltanColumnas[] = "$tabla.$col";
            }
        }
    }
    comprobar("[$camino] y todas sus columnas", $faltanColumnas === [], implode(', ', $faltanColumnas));

    Esquema::olvidarRevision();
    [$pendiente, $motivo] = Esquema::revisionPendiente();
    comprobar("[$camino] la base queda anotada en la versión " . Esquema::VERSION, !$pendiente, $motivo);

    $tipoRol = (string) Bd::valor(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'rol'",
        [$P . 'persona']
    );
    comprobar("[$camino] el perfil admite Staff", str_contains($tipoRol, "'staff'"), $tipoRol);

    // Nada se perdió.
    comprobar("[$camino] no se perdió ningún registro", cuentas() === $s['conteos'],
        json_encode(cuentas()) . ' frente a ' . json_encode($s['conteos']));

    // La llave es la misma y lo cifrado se sigue leyendo.
    comprobar("[$camino] la llave de cifrado es la misma",
        (string) Config::obtener('llave_cifrado') === $llave);

    $ilegibles = [];
    foreach ($s['documentos'] as $id => $doc) {
        $fila = Bd::fila('SELECT documento_cifrado FROM {persona} WHERE id = ?', [$id]);
        try {
            if (Cripto::descifrar((string) $fila['documento_cifrado']) !== $doc) {
                $ilegibles[] = $doc;
            }
        } catch (\Throwable) {
            $ilegibles[] = $doc;
        }
    }
    comprobar("[$camino] las cédulas se siguen leyendo", $ilegibles === [], implode(', ', $ilegibles));

    // Y el segundo factor: el mismo secreto, la misma aplicación del teléfono.
    $admin = Bd::fila('SELECT * FROM {usuario} WHERE id = ?', [$s['admin']]);
    $secretoAhora = \App\Modelos\Usuario::secretoTotp($admin);
    comprobar("[$camino] el secreto del segundo factor no cambió", $secretoAhora === $s['secreto']);
    comprobar("[$camino] y sigue confirmado", (int) $admin['totp_confirmado'] === 1);

    // Las carpetas que esta versión necesita.
    foreach (['almacen/fotos', 'almacen/documentos', 'almacen/logos', 'almacen/registro'] as $carpeta) {
        comprobar("[$camino] existe $carpeta", is_dir(RAIZ . '/' . $carpeta));
    }
}

/**
 * El administrador entra con su contraseña y su código del teléfono.
 *
 * Si el código del intervalo actual ya se usó —dos entradas en los mismos
 * treinta segundos—, espera al siguiente, como haría una persona.
 */
function entrarComoAdmin(ClienteHttp $c, array $s): bool
{
    $c->get('/admin/entrar');
    $c->post('/admin/entrar', ['correo' => $s['correo_admin'], 'clave' => $s['clave']]);
    if (!str_contains($c->cuerpo, 'name="codigo"')) {
        return str_contains($c->cuerpo, 'Panel del evento');
    }
    esperarCodigoNuevo($s);
    $c->post('/admin/verificar', ['codigo' => Totp::codigoActual($s['secreto'])]);
    return str_contains($c->cuerpo, 'Panel del evento');
}

/** Espera a que el teléfono muestre un código que no se haya usado todavía. */
function esperarCodigoNuevo(array $s): void
{
    static $usados = [];
    $clave = $s['secreto'];
    while (($usados[$clave] ?? -1) >= intdiv(time(), 30)) {
        usleep(500000);
    }
    $usados[$clave] = intdiv(time(), 30);
}

/** El texto visible de una página, para los mensajes de error de la prueba. */
function visible(string $html, int $largo = 200): string
{
    $html = (string) preg_replace('#<(script|style|head)\b.*?</\1>#si', ' ', $html);
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)))), 0, $largo);
}

/** Escribe la configuración con cambios, para un camino que los necesita. */
function configurar(array $cambios): void
{
    global $CONFIG_ANTES;
    Config::escribir($cambios + $CONFIG_ANTES);
    esperarAlServidor();
}

/**
 * Da tiempo al servidor a ver un config/config.php cambiado desde fuera.
 *
 * El servidor de pruebas tiene opcache, y opcache vuelve a mirar la fecha de un
 * archivo como mucho cada dos segundos (opcache.revalidate_freq). Cuando la
 * plataforma escribe su propia configuración invalida la caché ella misma; esta
 * prueba la escribe desde otro proceso, que no puede. Sin la espera, la primera
 * petición después de cambiarla todavía veía la anterior.
 */
function esperarAlServidor(): void
{
    sleep(3);
}

/** El paso 2 del asistente, con los datos de conexión de la instalación de pruebas. */
function pasoDos(): array
{
    global $CONFIG_ANTES;
    return [
        'accion' => 'paso2',
        'bd_host' => (string) $CONFIG_ANTES['bd_host'], 'bd_puerto' => (string) ($CONFIG_ANTES['bd_puerto'] ?? 3306),
        'bd_nombre' => (string) $CONFIG_ANTES['bd_nombre'], 'bd_usuario' => (string) $CONFIG_ANTES['bd_usuario'],
        'bd_clave' => (string) $CONFIG_ANTES['bd_clave'], 'bd_prefijo' => (string) $CONFIG_ANTES['bd_prefijo'],
    ];
}

/** El paso 4: la cuenta que ya se usa, con la misma contraseña. */
function pasoCuatro(array $s): array
{
    return [
        'accion' => 'paso4', 'ad_nombre' => 'Administración en producción',
        'ad_correo' => $s['correo_admin'], 'ad_clave' => $s['clave'], 'ad_clave2' => $s['clave'],
        'ad_2fa' => '1',
    ];
}

/** config/config.php tal como está en disco ahora, sin la caché de require. */
function leerConfigDeDisco(): array
{
    $datos = @eval('?>' . (string) @file_get_contents(RAIZ . '/config/config.php'));
    return is_array($datos) ? $datos : [];
}

/** Pide varias páginas a la vez, como el público entrando cuando abre el evento. */
function enParalelo(string $base, array $rutas): array
{
    $multi = curl_multi_init();
    $manijas = [];
    foreach ($rutas as $ruta) {
        $h = curl_init($base . $ruta);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        curl_multi_add_handle($multi, $h);
        $manijas[] = $h;
    }
    do {
        $estado = curl_multi_exec($multi, $activas);
        if ($activas) {
            curl_multi_select($multi, 1.0);
        }
    } while ($activas && $estado === CURLM_OK);
    $codigos = [];
    foreach ($manijas as $h) {
        $codigos[] = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $h);
        curl_close($h);
    }
    curl_multi_close($multi);
    return $codigos;
}

/* =========================================================================
   Preparación
   ========================================================================= */
titulo('La versión de partida');

[$versionVieja, $sqlViejo] = sqlDeLaVersion($PARTIDA, $P);
comprobar("el esquema de $PARTIDA sale de su propio código", $versionVieja !== '' && $sqlViejo !== '',
    $versionVieja);
echo "  · parte de la versión $versionVieja; esta es la " . Esquema::VERSION . "\n";

// La compatibilidad del cifrado entre versiones: lo que cifró la vieja tiene
// que leerlo la nueva, o todas las cédulas de producción se perderían.
$criptoViejo = shell_exec('git -C ' . escapeshellarg(RAIZ) . ' show ' . escapeshellarg($PARTIDA . ':app/Nucleo/Cripto.php') . ' 2>/dev/null');
$tmpCripto = sys_get_temp_dir() . '/cripto-viejo-' . getmypid() . '.php';
file_put_contents($tmpCripto, (string) $criptoViejo);
$cifradoViejo = (string) shell_exec('php -r ' . escapeshellarg(
    'define("EVENTOS_TIC", true); define("RAIZ", ' . var_export(RAIZ, true) . ');'
    . 'spl_autoload_register(function($c){ if ($c === "App\\\\Nucleo\\\\Cripto") { require ' . var_export($tmpCripto, true) . '; return; }'
    . ' $f = RAIZ . "/app/" . str_replace("\\\\", "/", substr($c, 4)) . ".php"; if (is_file($f)) require $f; });'
    . '\App\Nucleo\Config::cargar(); echo base64_encode(\App\Nucleo\Cripto::cifrar("1085234567"));'
));
@unlink($tmpCripto);
$leidoAhora = '';
try {
    $leidoAhora = Cripto::descifrar((string) base64_decode(trim($cifradoViejo)));
} catch (\Throwable $e) {
    $leidoAhora = 'error: ' . $e->getMessage();
}
comprobar('lo que cifraba la versión vieja lo lee esta', $leidoAhora === '1085234567', $leidoAhora);

/* =========================================================================
   Los caminos de actualización
   ========================================================================= */
$caminos = [
    // Lo normal desde la 3.6: se suben los archivos y la primera visita, la de
    // quien sea, pone la base al día. Llegan varias a la vez, como cuando se
    // actualiza con el evento abierto, y ninguna puede fallar.
    'automatica' => static function (array $s) use ($BASE): void {
        $bitacoraAntes = (int) Bd::valor("SELECT COUNT(*) FROM {bitacora}");
        $codigos = enParalelo($BASE, ['/', '/admin/entrar', '/', '/registro', '/admin/entrar', '/']);
        $malos = array_filter($codigos, static fn(int $c): bool => $c === 0 || $c >= 500);
        comprobar('[automatica] seis visitas a la vez, ninguna falla', $malos === [], implode(',', $codigos));

        Bd::reiniciar();
        Bd::conectar();
        $registros = Bd::filas("SELECT detalle FROM {bitacora} WHERE accion = 'esquema_actualizado'");
        comprobar('[automatica] se actualizó una sola vez', count($registros) === 1, count($registros) . ' veces');
        $detalle = json_decode((string) ($registros[0]['detalle'] ?? ''), true) ?: [];
        comprobar('[automatica] y quedó en la bitácora como automática', ($detalle['origen'] ?? '') === 'automatica',
            json_encode($detalle));
        comprobar('[automatica] con la versión de la que partió', ($detalle['de'] ?? '') !== '',
            json_encode($detalle));
        unset($bitacoraAntes);
    },

    'consola' => static function (array $s): void {
        $salida = (string) shell_exec('php ' . escapeshellarg(RAIZ . '/herramientas/instalar.php')
            . ' --reparar --esquema 2>&1');
        comprobar('[consola] termina sin error', str_contains($salida, 'Instalación reparada'),
            substr(trim($salida), -160));
    },

    // El botón del panel, con la automática apagada. Lo que se prueba aquí
    // también es que, con la base vieja, el administrador pueda llegar al
    // botón: hasta la 3.5 el segundo factor respondía 500 y no había forma.
    'boton' => static function (array $s) use ($BASE): void {
        configurar(['actualizacion_automatica' => false]);
        try {
            $c = new ClienteHttp($BASE);
            comprobar('[boton] con la base vieja, el administrador llega al panel', entrarComoAdmin($c, $s),
                visible($c->cuerpo));
            comprobar('[boton] y el panel avisa que la base está atrasada',
                str_contains($c->cuerpo, 'actualizar-esquema'), visible($c->cuerpo));
            $c->post('/admin/actualizar-esquema', []);
            comprobar('[boton] el botón la pone al día',
                str_contains($c->cuerpo, 'Base de datos actualizada') && !str_contains($c->cuerpo, 'actualizar-esquema'),
                visible($c->cuerpo));
        } finally {
            configurar([]);
        }
    },

    // El asistente, reabierto con config/permitir-reinstalar, como dice la
    // guía de despliegue. Quien lo usa sobre una base con datos no puede
    // perder nada, ni siquiera eligiendo mal.
    'asistente' => static function (array $s) use ($BASE): void {
        global $CONFIG_ANTES;
        $permiso = RAIZ . '/config/permitir-reinstalar';
        touch($permiso);
        try {
            $c = new ClienteHttp($BASE);
            $c->get('/instalar');
            comprobar('[asistente] se reabre con el permiso', $c->codigo === 200 && str_contains($c->cuerpo, 'paso1'),
                $c->codigo . ' ' . visible($c->cuerpo));
            $c->post('/instalar', ['accion' => 'paso1']);
            $c->post('/instalar', [
                'accion' => 'paso2',
                'bd_host' => (string) $CONFIG_ANTES['bd_host'], 'bd_puerto' => (string) ($CONFIG_ANTES['bd_puerto'] ?? 3306),
                'bd_nombre' => (string) $CONFIG_ANTES['bd_nombre'], 'bd_usuario' => (string) $CONFIG_ANTES['bd_usuario'],
                'bd_clave' => (string) $CONFIG_ANTES['bd_clave'], 'bd_prefijo' => (string) $CONFIG_ANTES['bd_prefijo'],
            ]);
            comprobar('[asistente] el paso 3 sugiere actualizar, no borrar',
                (bool) preg_match('/value="actualizar"[^>]*checked/', $c->cuerpo), visible($c->cuerpo));

            // Elegir «limpio» sin la casilla de confirmación no borra nada.
            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'limpio']);
            comprobar('[asistente] «limpio» sin confirmar no se aplica',
                str_contains($c->cuerpo, 'marca la casilla'), visible($c->cuerpo));
            comprobar('[asistente] y los datos siguen ahí', cuentas() === $s['conteos'], json_encode(cuentas()));

            // Un envío sin modo hace lo que no borra: actualizar.
            $c->post('/instalar', ['accion' => 'paso3']);
            comprobar('[asistente] sin modo, actualiza y pasa a la cuenta',
                str_contains($c->cuerpo, 'Cuenta administradora') && str_contains($c->cuerpo, 'solo se le cambia la contraseña'),
                visible($c->cuerpo));
            comprobar('[asistente] y respeta la exigencia del segundo factor que ya había',
                (bool) preg_match('/name="ad_2fa"[^>]*checked/', $c->cuerpo) === (bool) ($CONFIG_ANTES['exigir_2fa_admin'] ?? true));

            $c->post('/instalar', [
                'accion' => 'paso4', 'ad_nombre' => 'Administración en producción',
                'ad_correo' => $s['correo_admin'], 'ad_clave' => $s['clave'], 'ad_clave2' => $s['clave'],
                'ad_2fa' => '1',
            ]);
            $c->post('/instalar', [
                'accion' => 'paso5', 'ev_nombre' => 'Otro evento que no debe crearse',
                'ev_inicio' => date('Y-m-d'), 'ev_dias' => '2',
            ]);
            comprobar('[asistente] termina', str_contains($c->cuerpo, 'Instalación terminada'), visible($c->cuerpo));
            comprobar('[asistente] dice que el segundo factor se conserva',
                str_contains($c->cuerpo, 'Se conserva'), visible($c->cuerpo, 600));
            comprobar('[asistente] no crea otro evento', str_contains($c->cuerpo, 'Se conservó con sus registros'));
            comprobar('[asistente] borra el permiso de reinstalar', !is_file($permiso));
            $c->get('/instalar');
            comprobar('[asistente] y queda cerrado otra vez', $c->codigo === 403, (string) $c->codigo);
        } finally {
            @unlink($permiso);
            Config::cargar();
        }
    },

    // config/config.php se perdió: se borró la carpeta para subir la versión
    // nueva. Es la causa del «error de reloj» que se veía en producción: el
    // asistente generaba otra llave, y con ella el secreto del segundo factor
    // ya no se podía leer. Ahora pide la anterior y la comprueba.
    'sin-config' => static function (array $s) use ($BASE): ?array {
        global $CONFIG_ANTES;
        $archivo = RAIZ . '/config/config.php';
        $copia = (string) file_get_contents($archivo);
        unlink($archivo);
        esperarAlServidor();
        try {
            $c = new ClienteHttp($BASE);
            $c->get('/');
            comprobar('[sin-config] sin config/config.php, el sitio lleva al asistente',
                str_contains($c->cuerpo, 'paso1'), visible($c->cuerpo));
            $c->post('/instalar', ['accion' => 'paso1']);
            $c->post('/instalar', pasoDos());
            comprobar('[sin-config] el paso 3 pide la llave anterior',
                str_contains($c->cuerpo, 'Llave de cifrado anterior'), visible($c->cuerpo));

            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'actualizar']);
            comprobar('[sin-config] sin llave ni casilla, no sigue', str_contains($c->cuerpo, 'falta la llave'),
                visible($c->cuerpo));
            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'actualizar',
                'llave_previa' => base64_encode(random_bytes(32))]);
            comprobar('[sin-config] una llave equivocada se rechaza', str_contains($c->cuerpo, 'no abre los datos'),
                visible($c->cuerpo));
            // Pegando la línea entera del config.php viejo, como lo haría alguien.
            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'actualizar',
                'llave_previa' => "    'llave_cifrado' => '" . $CONFIG_ANTES['llave_cifrado'] . "',"]);
            comprobar('[sin-config] con la línea del config.php viejo, sigue',
                str_contains($c->cuerpo, 'Cuenta administradora'), visible($c->cuerpo));

            $c->post('/instalar', pasoCuatro($s));
            $c->post('/instalar', ['accion' => 'paso5', 'ev_nombre' => 'No se crea', 'ev_inicio' => date('Y-m-d')]);
            comprobar('[sin-config] termina', str_contains($c->cuerpo, 'Instalación terminada'), visible($c->cuerpo));
            comprobar('[sin-config] y dice que el segundo factor se conserva', str_contains($c->cuerpo, 'Se conserva'));
            $nueva = leerConfigDeDisco();
            comprobar('[sin-config] la configuración nueva lleva la llave de antes',
                ($nueva['llave_cifrado'] ?? '') === $CONFIG_ANTES['llave_cifrado']);
            comprobar('[sin-config] y no queda config/instalacion.php con la llave', !is_file(RAIZ . '/config/instalacion.php'));
        } finally {
            file_put_contents($archivo, $copia);
            Config::cargar();
            esperarAlServidor();
        }
        return null;
    },

    // Lo mismo, pero sin la llave anterior: se sigue a sabiendas. Los datos
    // siguen ahí aunque no se puedan leer, y el administrador entra y vuelve a
    // configurar la aplicación, en vez de quedarse fuera para siempre.
    'llave-nueva' => static function (array $s) use ($BASE): ?array {
        global $CONFIG_ANTES;
        $archivo = RAIZ . '/config/config.php';
        $copia = (string) file_get_contents($archivo);
        unlink($archivo);
        esperarAlServidor();
        try {
            $c = new ClienteHttp($BASE);
            $c->get('/instalar');
            $c->post('/instalar', ['accion' => 'paso1']);
            $c->post('/instalar', pasoDos());
            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'actualizar', 'sin_llave_previa' => '1']);
            comprobar('[llave-nueva] marcando la casilla, sigue', str_contains($c->cuerpo, 'Cuenta administradora'),
                visible($c->cuerpo));
            $c->post('/instalar', pasoCuatro($s));
            $c->post('/instalar', ['accion' => 'paso5', 'ev_nombre' => 'No se crea', 'ev_inicio' => date('Y-m-d')]);
            comprobar('[llave-nueva] el resumen avisa de la llave nueva', str_contains($c->cuerpo, 'llave de cifrado nueva'));
            comprobar('[llave-nueva] y de que hay que configurar otra vez la aplicación',
                str_contains($c->cuerpo, 'Vuelve a configurar tu segundo factor'));
            comprobar('[llave-nueva] la llave es otra', (leerConfigDeDisco()['llave_cifrado'] ?? '') !== $CONFIG_ANTES['llave_cifrado']);
            comprobar('[llave-nueva] no se perdió ningún registro', cuentas() === $s['conteos'], json_encode(cuentas()));

            $a = new ClienteHttp($BASE);
            $a->get('/admin/entrar');
            $a->post('/admin/entrar', ['correo' => $s['correo_admin'], 'clave' => $s['clave']]);
            comprobar('[llave-nueva] al entrar, le muestra un QR nuevo en vez de un error de reloj',
                str_contains($a->cuerpo, 'Activa la verificación en dos pasos'), visible($a->cuerpo));
            preg_match('#letter-spacing:\.14em[^>]*>([A-Z2-7 ]{32,})<#', $a->cuerpo, $m);
            $nuevo = str_replace(' ', '', trim($m[1] ?? ''));
            $a->post('/admin/activar-2fa', ['codigo' => Totp::codigoActual($nuevo)]);
            comprobar('[llave-nueva] y con él entra al panel', str_contains($a->cuerpo, 'Panel del evento'), visible($a->cuerpo));
        } finally {
            file_put_contents($archivo, $copia);
            Config::cargar();
            esperarAlServidor();
        }
        // Lo de siempre no se comprueba aquí: con otra llave, las cédulas no
        // se leen y el secreto cambió, que es justo lo que se aceptó.
        return ['sin_verificar' => true];
    },

    // «Anexar» deja las tablas viejas como estaban, pero anota la versión
    // nueva. Antes eso bastaba para que nada volviera a completarlas: el panel
    // decía «al día» y el segundo factor fallaba por una columna que no
    // existía. Ahora se miran las columnas, y la siguiente visita las agrega.
    'anexar' => static function (array $s) use ($BASE): void {
        global $CONFIG_ANTES;
        $permiso = RAIZ . '/config/permitir-reinstalar';
        touch($permiso);
        try {
            $c = new ClienteHttp($BASE);
            $c->get('/instalar');
            $c->post('/instalar', ['accion' => 'paso1']);
            $c->post('/instalar', [
                'accion' => 'paso2',
                'bd_host' => (string) $CONFIG_ANTES['bd_host'], 'bd_puerto' => (string) ($CONFIG_ANTES['bd_puerto'] ?? 3306),
                'bd_nombre' => (string) $CONFIG_ANTES['bd_nombre'], 'bd_usuario' => (string) $CONFIG_ANTES['bd_usuario'],
                'bd_clave' => (string) $CONFIG_ANTES['bd_clave'], 'bd_prefijo' => (string) $CONFIG_ANTES['bd_prefijo'],
            ]);
            $c->post('/instalar', ['accion' => 'paso3', 'modo' => 'anexar']);
            $c->post('/instalar', [
                'accion' => 'paso4', 'ad_nombre' => 'Administración en producción',
                'ad_correo' => $s['correo_admin'], 'ad_clave' => $s['clave'], 'ad_clave2' => $s['clave'],
                'ad_2fa' => '1',
            ]);
            $c->post('/instalar', ['accion' => 'paso5', 'ev_nombre' => 'No se crea', 'ev_inicio' => date('Y-m-d')]);
            comprobar('[anexar] el asistente termina', str_contains($c->cuerpo, 'Instalación terminada'), visible($c->cuerpo));
        } finally {
            @unlink($permiso);
            Config::cargar();
        }

        Bd::reiniciar();
        Bd::conectar();
        Esquema::olvidarRevision();
        [$pendiente, $motivo] = Esquema::revisionPendiente();
        comprobar('[anexar] con la versión anotada, igual se ve que faltan columnas',
            $pendiente && str_contains($motivo, 'columna'), $motivo);

        (new ClienteHttp($BASE))->get('/');
    },
];

// CAMINO=consola corre uno solo; SIN_LIMPIAR=1 deja la base como quedó, para
// poder mirarla a mano cuando algo falla.
$soloUno = (string) (getenv('CAMINO') ?: '');
foreach ($caminos as $nombre => $actualizar) {
    if ($soloUno !== '' && $soloUno !== $nombre) {
        continue;
    }
    titulo('Camino: ' . $nombre);
    reconstruirVieja($sqlViejo);
    $semilla = sembrar();
    // Como recién subidos los archivos: sin marca de «al día» de una corrida
    // anterior, que diría que esta base vieja ya se revisó.
    Actualizacion::olvidar();
    echo "  · base en $versionVieja con " . json_encode($semilla['conteos']) . "\n";

    $resultado = $actualizar($semilla);
    if (!empty($resultado['sin_verificar'])) {
        continue;
    }
    verificar($nombre, $semilla, $LLAVE);

    // Y después de actualizar, el administrador entra igual que antes.
    $c = new ClienteHttp($BASE);
    comprobar("[$nombre] después, el administrador entra con el mismo código del teléfono",
        entrarComoAdmin($c, $semilla), visible($c->cuerpo));
}

/* =========================================================================
   Dejar la base de pruebas como estaba
   ========================================================================= */
titulo('Limpieza');
if (getenv('SIN_LIMPIAR')) {
    echo "  · SIN_LIMPIAR: la base queda como la dejó el último camino\n";
    echo "\n" . $ok . ' comprobaciones correctas · ' . count($fallos) . " fallidas\n";
    exit($fallos === [] ? 0 : 1);
}
esperarAlServidor();
// Las últimas líneas y no la última: el resumen de esa prueba termina con una
// línea en blanco, y mirando solo la última esta comprobación fallaba siempre.
$salida = (string) shell_exec('php ' . escapeshellarg(RAIZ . '/pruebas/instalacion.php') . ' 2>&1 | tail -4');
comprobar('la instalación de pruebas queda otra vez al día', str_contains($salida, ' 0 fallidas'),
    trim($salida));

echo "\n" . str_repeat('─', 62) . "\n";
echo $ok . ' comprobaciones correctas · ' . count($fallos) . " fallidas\n";
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos === [] ? 0 : 1);
