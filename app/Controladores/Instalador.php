<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Esquema;
use App\Modelos\Evento;
use App\Modelos\Usuario;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Cripto;
use App\Nucleo\Instalacion;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Asistente de instalación.
 *
 * Seis pasos. El estado va en una cookie firmada y no en sesión, porque
 * todavía no hay base de datos donde guardar sesiones. Ninguna contraseña pasa
 * por esa cookie: la de la base de datos se escribe en config/instalacion.php
 * en cuanto la conexión se comprueba, y la del administrador se convierte a
 * hash en el paso 4 y solo viaja así.
 *
 * Dos invariantes que conviene no romper, porque romperlas ya costó caro:
 *
 *  1. Que exista config/config.php significa «instalación terminada». Nunca se
 *     escribe a medias. Un config.php con instalado=false deja el sitio entero
 *     redirigiendo al asistente y, desde fuera, es indistinguible de un sitio
 *     que nunca se instaló. Los datos de conexión del proceso viven mientras
 *     tanto en config/instalacion.php, que el paso 6 borra.
 *  2. El paso final escribe esa marca al final, cuando la cuenta y el evento ya
 *     existen. Al revés, cualquier fallo posterior dejaba la plataforma cerrada
 *     y sin puerta.
 *
 * Al terminar, el asistente se cierra solo: la ruta queda bloqueada mientras
 * exista config/config.php con la marca de instalación completa. No hay que
 * acordarse de borrar ninguna carpeta. La única excepción es que la instalación
 * quede inservible —sin cuenta administradora—; entonces se reabre en modo
 * reparación, porque cerrarla ahí es dejar el sitio sin manera de entrar.
 */
final class Instalador
{
    private const COOKIE = 'evtic_instalacion';
    private const PASOS = ['Servidor', 'Base de datos', 'Tablas', 'Cuenta', 'Evento', 'Fin'];

    public function asistente(Peticion $peticion): void
    {
        $estado = $this->leerEstado();
        $paso = max(1, min(6, (int) ($peticion->query('paso') ?: $estado['paso'] ?? 1)));
        $errores = [];

        // Reparación: la plataforma se dio por instalada pero no se puede entrar
        // a ella. El guardia dejó pasar por eso. Se avisa en pantalla y se
        // esconde la opción que borra tablas, que aquí nunca es la respuesta.
        $reparacion = Instalacion::incompleta() && !is_file(RAIZ . '/config/permitir-reinstalar');
        $diagnostico = $reparacion ? Instalacion::diagnostico() : null;

        if ($peticion->esPost()) {
            $accion = $peticion->campo('accion');

            // Acciones que responden sin cambiar de paso.
            if ($accion === 'probar_conexion') {
                $this->probarConexion($peticion);
            }

            [$paso, $errores, $estado] = $this->procesar($peticion, $accion, $estado, $reparacion);
            // El paso nuevo va delante: con la unión al revés, el 'paso' que ya
            // traía la cookie ganaba siempre y el asistente se quedaba clavado
            // en el mismo sitio en cuanto alguien navegaba sin el ?paso= de la
            // redirección.
            $this->guardarEstado(['paso' => $paso] + $estado);

            if (!$errores) {
                // Terminada la instalación, esta misma ruta queda cerrada por el
                // guardia. El resumen y los siguientes pasos viven en una ruta
                // aparte, que solo muestra información y no ejecuta nada.
                Respuesta::redirigirAbsoluto(Url::a($paso === 6 ? '/instalar/listo' : '/instalar', $paso === 6 ? [] : ['paso' => $paso]));
            }
        }

        Respuesta::vista('instalar/asistente', [
            'paso'        => $paso,
            'pasos'       => self::PASOS,
            'estado'      => $estado,
            'errores'     => $errores,
            'requisitos'  => $this->requisitos(),
            'permisos'    => $this->permisos(),
            'bloqueantes' => $this->bloqueantes(),
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'reparacion'  => $reparacion,
            'diagnostico' => $diagnostico,
            'esquema'     => $paso === 3 ? $this->estadoDelEsquema() : null,
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Qué hay en la base antes de tocarla.
     *
     * Lo mira el controlador y no la vista. La vista lo intentaba por su
     * cuenta, pero en esa petición todavía no hay conexión abierta —los datos
     * están en config/instalacion.php y la aplicación no se conecta mientras no
     * esté instalada—, así que la consulta fallaba, el error se tragaba, y el
     * paso 3 anunciaba «no hay ninguna tabla» y preseleccionaba la instalación
     * limpia. Sobre una base con datos, eso es ofrecerse a borrarlos sin que
     * nada avise.
     *
     * @return array{conectado: bool, existentes: array<int,string>, version: ?string}
     */
    private function estadoDelEsquema(): array
    {
        if (!$this->reconectar()) {
            return ['conectado' => false, 'existentes' => [], 'version' => null, 'llave' => null];
        }
        try {
            return [
                'conectado'  => true,
                'existentes' => Esquema::existentes(),
                'version'    => Esquema::versionInstalada(),
                'llave'      => $this->estadoDeLaLlave(),
            ];
        } catch (\Throwable) {
            return ['conectado' => false, 'existentes' => [], 'version' => null, 'llave' => null];
        }
    }

    /**
     * ¿Hay datos cifrados en la base sin una llave con que leerlos?
     *
     * Es lo que pasa cuando config/config.php se pierde —se borró la carpeta
     * para subir la versión nueva, por ejemplo— y se vuelve a pasar por el
     * asistente. Hasta la 3.5 el asistente generaba una llave nueva sin decir
     * nada, y con ella:
     *
     *   · las cédulas guardadas dejaban de poder leerse;
     *   · el segundo factor de cada cuenta también, y el código correcto del
     *     teléfono se rechazaba siempre con un mensaje sobre el reloj.
     *
     * Ahora, si la configuración no trae llave y la base tiene datos cifrados,
     * el paso 3 pide la llave anterior —está en la copia de config.php— y la
     * prueba contra un dato de verdad antes de aceptarla. Seguir sin ella se
     * puede, pero solo diciéndolo de forma explícita.
     *
     * @return array{falta: bool, personas: int, cuentas: int, muestras: array<int,string>}
     */
    private function estadoDeLaLlave(): array
    {
        $vacio = ['falta' => false, 'personas' => 0, 'cuentas' => 0, 'muestras' => []];
        if (Cripto::hayLlave()) {
            // Con llave en la configuración no se pregunta nada: es la que se
            // está usando, y cambiarla sería justamente el problema.
            return $vacio;
        }
        $cifrados = Instalacion::datosCifrados();
        if ($cifrados['muestras'] === []) {
            return $vacio;
        }

        // Ya se dio en este mismo proceso y se comprobó.
        $enCurso = (string) ($this->leerConexion()['llave_cifrado'] ?? '');
        if ($enCurso !== '' && Instalacion::llaveAbreLosDatos($enCurso, $cifrados['muestras'])) {
            return $vacio;
        }

        return ['falta' => true] + $cifrados;
    }

    /**
     * La llave tal como la pegue la persona.
     *
     * Lo natural es copiar la línea entera del config.php viejo
     * —'llave_cifrado' => '…',— y no solo el valor. Se acepta igual.
     */
    private function llavePegada(string $texto): string
    {
        $texto = trim($texto);
        if (preg_match("/llave_cifrado'?\s*=>\s*'([A-Za-z0-9+\/=]+)'/", $texto, $m)) {
            return $m[1];
        }
        return trim($texto, " \t\n\r\0\x0B'\",;");
    }

    /**
     * Resumen final.
     *
     * Ruta aparte porque, una vez escrita la configuración, /instalar queda
     * cerrada por el guardia y con ella se perdería la pantalla que explica qué
     * se hizo y qué falta por hacer. Aquí no se ejecuta nada: solo se muestra lo
     * que quedó anotado en la cookie firmada del proceso.
     */
    public function terminado(Peticion $peticion): void
    {
        $estado = $this->leerEstado();
        $resultado = $estado['resultado'] ?? null;

        if (!is_array($resultado)) {
            Respuesta::redirigir('/admin/entrar');
        }

        Respuesta::vista('instalar/asistente', [
            'paso'        => 6,
            'pasos'       => self::PASOS,
            'estado'      => ['resultado' => $resultado],
            'errores'     => [],
            'requisitos'  => [],
            'permisos'    => [],
            'bloqueantes' => [],
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'reparacion'  => false,
            'diagnostico' => null,
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Diagnóstico.
     *
     * La pantalla que faltaba. Cuando la plataforma se queda redirigiendo al
     * asistente, desde fuera no se distingue «nunca se instaló» de «se instaló
     * y algo no se pudo leer», y sin esa distinción no hay nada que hacer salvo
     * adivinar. Aquí se dice el estado real: qué archivos hay, qué contesta la
     * base de datos, y qué fue lo último que falló.
     *
     * No muestra ninguna credencial. Y solo es pública mientras el asistente lo
     * es: en cuanto la plataforma funciona, exige sesión de administrador.
     */
    public function diagnostico(Peticion $peticion): void
    {
        $publico = !Config::instalado() || !Instalacion::completa();
        if (!$publico) {
            \App\Nucleo\Guardia::exigir('admin:administrador', $peticion);
        }

        Respuesta::vista('instalar/diagnostico', [
            'titulo'       => 'Diagnóstico de la instalación',
            'estado'       => Instalacion::diagnostico(),
            'servidor'     => $this->datosDelServidor(),
            'archivos'     => $this->datosDeArchivos(),
            'errores'      => Instalacion::erroresRecientes(12, $publico),
            'publico'      => $publico,
            'sinPlantilla' => true,
        ]);
    }

    /** @return array<string, string> */
    private function datosDelServidor(): array
    {
        $peticion = \App\Nucleo\App::peticion();

        $opcache = 'no disponible';
        if (function_exists('opcache_get_status')) {
            $estado = @opcache_get_status(false);
            if (is_array($estado) && !empty($estado['opcache_enabled'])) {
                $valida = ini_get('opcache.validate_timestamps');
                $opcache = 'activo · validate_timestamps='
                    . (($valida === false || $valida === '') ? '?' : $valida)
                    . ' · revalidate_freq=' . (ini_get('opcache.revalidate_freq') ?: '?');
            } else {
                $opcache = 'inactivo';
            }
        }

        return [
            'PHP'                => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Versión de la app'  => APP_VERSION,
            'Esquema esperado'   => Esquema::VERSION,
            'OPcache'            => $opcache,
            'Ruta base detectada' => $peticion->base() === '' ? '(raíz del dominio)' : $peticion->base(),
            'SCRIPT_NAME'        => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            'REQUEST_URI'        => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'HTTPS detectado'    => $peticion->esSegura() ? 'sí' : 'no',
            'Dirección del visitante' => $peticion->ip()
                . ($peticion->detrasDeProxySinConfigurar()
                    ? '  ← es la del proxy, no la del visitante'
                    : ''),
            'Proxies declarados' => Config::obtener('proxies_confiables', [])
                ? implode(', ', (array) Config::obtener('proxies_confiables'))
                : 'ninguno',
            'Servidor web'       => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'desconocido'),
            'Zona horaria'       => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s'),
            'Usuario del proceso' => function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                : get_current_user(),
        ];
    }

    /** @return array<int, array{nombre: string, estado: string, valor: string, detalle: string}> */
    private function datosDeArchivos(): array
    {
        $lista = [];

        foreach ([
            'config/config.php'      => 'La configuración. Que exista significa instalación terminada.',
            'config/instalacion.php' => 'Datos de conexión mientras dura el asistente. Sobra si ya terminó.',
        ] as $relativa => $para) {
            $ruta = RAIZ . '/' . $relativa;
            $hay = is_file($ruta);
            $lista[] = [
                'nombre'  => $relativa,
                'detalle' => $para,
                'valor'   => $hay ? number_format(filesize($ruta)) . ' B · ' . date('Y-m-d H:i', filemtime($ruta)) : 'no existe',
                'estado'  => $relativa === 'config/config.php' ? ($hay ? 'ok' : 'warn') : ($hay ? 'warn' : 'ok'),
            ];
        }

        if (Config::problema() !== '') {
            $lista[] = [
                'nombre'  => 'Lectura de la configuración',
                'detalle' => Config::problema(),
                'valor'   => 'ilegible',
                'estado'  => 'fail',
            ];
        }

        if (Config::existe()) {
            $marca = (bool) Config::obtener('instalado', false);
            $lista[] = [
                'nombre'  => 'Marca «instalado»',
                'detalle' => 'Lo que dice config/config.php',
                'valor'   => $marca ? 'true' : 'false',
                'estado'  => $marca ? 'ok' : 'fail',
            ];
            $lista[] = [
                'nombre'  => 'Llave de cifrado',
                'detalle' => 'Sin ella los documentos guardados quedan ilegibles',
                'valor'   => \App\Nucleo\Cripto::hayLlave() ? 'presente y válida' : 'ausente o inválida',
                'estado'  => \App\Nucleo\Cripto::hayLlave() ? 'ok' : 'fail',
            ];
            $lista[] = [
                'nombre'  => 'url_base configurada',
                'detalle' => 'La que se usa en los correos y dentro de los códigos QR',
                'valor'   => (string) (Config::obtener('url_base') ?: '(sin definir)'),
                'estado'  => Config::obtener('url_base') ? 'ok' : 'warn',
            ];
        }

        foreach (['config', 'almacen/registro'] as $relativa) {
            $ruta = RAIZ . '/' . $relativa;
            $escribible = is_dir($ruta) && is_writable($ruta);
            $lista[] = [
                'nombre'  => $relativa . '/',
                'detalle' => 'Permiso de escritura para el usuario del dominio',
                'valor'   => is_dir($ruta) ? ($escribible ? 'escritura' : 'solo lectura') : 'no existe',
                'estado'  => $escribible ? 'ok' : 'fail',
            ];
        }

        return $lista;
    }

    /* =====================================================================
       Paso 1 · Comprobación del servidor
       ===================================================================== */

    private function requisitos(): array
    {
        $lista = [];

        $lista[] = [
            'nombre'  => 'Versión de PHP',
            'detalle' => 'Se requiere 8.1 o superior',
            'valor'   => PHP_VERSION,
            'estado'  => PHP_VERSION_ID >= 80100 ? 'ok' : 'fail',
        ];

        foreach ([
            'pdo_mysql' => ['Acceso a la base de datos', true],
            'mbstring'  => ['Manejo correcto de tildes y ñ', true],
            'openssl'   => ['Cifrado del documento y de los tokens', true],
            'json'      => ['Serialización interna', true],
            'fileinfo'  => ['Verificar el tipo real de los archivos subidos', true],
            'gd'        => ['Reducir el logo y las fotos del carnet', false],
            'zip'       => ['Comprobar que un PPTX es de verdad una presentación', false],
            'sodium'    => ['Cifrado moderno; sin él se usa AES-GCM', false],
        ] as $extension => [$para, $obligatoria]) {
            $presente = extension_loaded($extension);
            $lista[] = [
                'nombre'  => 'Extensión ' . $extension,
                'detalle' => $para,
                'valor'   => $presente ? 'presente' : 'ausente',
                'estado'  => $presente ? 'ok' : ($obligatoria ? 'fail' : 'warn'),
            ];
        }

        // El tope de subida de PHP. Se comprueba porque es el que decide de
        // verdad qué se puede adjuntar, y en Plesk viene en 2 MB de fábrica: con
        // eso no entra ni la foto del carnet, y el fallo es de los peores de
        // diagnosticar. Pasado post_max_size, PHP descarta el envío completo
        // —$_POST y $_FILES llegan vacíos, el testigo incluido— y la persona ve
        // «la sesión expiró» en lugar de «el archivo pesa demasiado».
        $necesario = 0;
        foreach (\App\Nucleo\Documento::CLASES as $regla) {
            $necesario = max($necesario, (int) $regla['peso']);
        }
        $subida = self::aBytes((string) ini_get('upload_max_filesize'));
        $envio = self::aBytes((string) ini_get('post_max_size'));
        $tope = min($subida, $envio);

        $lista[] = [
            'nombre'  => 'Tamaño máximo de subida',
            'detalle' => 'La exposición de un expositor puede pesar hasta '
                . (int) round($necesario / 1048576) . ' MB',
            'valor'   => 'upload_max_filesize ' . ini_get('upload_max_filesize')
                . ', post_max_size ' . ini_get('post_max_size'),
            'estado'  => $tope >= $necesario ? 'ok' : 'warn',
        ];

        $argon = defined('PASSWORD_ARGON2ID');
        $lista[] = [
            'nombre'  => 'Argon2id para contraseñas',
            'detalle' => $argon ? 'Disponible' : 'Se usará bcrypt, que también es aceptable',
            'valor'   => $argon ? 'disponible' : 'bcrypt',
            'estado'  => $argon ? 'ok' : 'warn',
        ];

        $seguro = \App\Nucleo\App::peticion()->esSegura();
        $lista[] = [
            'nombre'  => 'HTTPS activo',
            'detalle' => 'Sin TLS las credenciales y los tokens viajan en claro',
            'valor'   => $seguro ? 'sí' : 'no detectado',
            'estado'  => $seguro ? 'ok' : 'warn',
        ];

        $lista[] = [
            'nombre'  => 'Envío de correo',
            'detalle' => 'Para enviar el carnet y los códigos de acceso',
            'valor'   => function_exists('mail') ? 'función mail() disponible' : 'sin mail()',
            'estado'  => function_exists('mail') ? 'ok' : 'warn',
        ];

        $reescritura = $this->hayReescritura();
        $lista[] = [
            'nombre'  => 'Reescritura de URL',
            'detalle' => 'mod_rewrite en Apache; sin él las direcciones no funcionan',
            'valor'   => $reescritura === null ? 'no se pudo comprobar' : ($reescritura ? 'activa' : 'inactiva'),
            'estado'  => $reescritura === false ? 'fail' : ($reescritura === null ? 'warn' : 'ok'),
        ];

        return $lista;
    }

    /**
     * «8M», «2048K», «1G» a bytes.
     *
     * Así se escriben los topes en php.ini. Un 0 o un -1 significan «sin
     * límite», y aquí eso se traduce a PHP_INT_MAX para que la comparación
     * funcione igual que con un número.
     */
    private static function aBytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '' || (int) $valor <= 0) {
            return PHP_INT_MAX;
        }

        $numero = (int) $valor;
        return match (strtolower(substr($valor, -1))) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * ¿Está mod_rewrite? Si se llegó aquí por una URL limpia, la respuesta es sí.
     * Con nginx por delante —lo habitual en Plesk— no se puede saber desde PHP,
     * y entonces se avisa en vez de afirmar.
     */
    private function hayReescritura(): ?bool
    {
        if (function_exists('apache_get_modules')) {
            return in_array('mod_rewrite', apache_get_modules(), true);
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/instalar') && !str_contains($uri, 'index.php')) {
            return true;
        }
        return null;
    }

    private function permisos(): array
    {
        $carpetas = [
            'config'            => 'Aquí se escribe config.php',
            'almacen/logos'     => 'Logos de cada evento',
            'almacen/fotos'     => 'Fotografías de los carnets',
            'almacen/documentos' => 'Hojas de vida y exposiciones de los expositores',
            'almacen/respaldos' => 'Copias antes de cada migración',
            'almacen/registro'  => 'Registro de errores',
        ];

        $lista = [];
        foreach ($carpetas as $relativa => $para) {
            $ruta = RAIZ . '/' . $relativa;
            if (!is_dir($ruta)) {
                @mkdir($ruta, 0750, true);
            }
            $escribible = is_dir($ruta) && is_writable($ruta);
            $lista[] = [
                'nombre'  => $relativa . '/',
                'detalle' => $para,
                'valor'   => $escribible ? 'escritura' : (is_dir($ruta) ? 'solo lectura' : 'no existe'),
                'estado'  => $escribible ? 'ok' : 'fail',
            ];
        }
        return $lista;
    }

    /** Requisitos con estado 'fail': impiden continuar. */
    private function bloqueantes(): array
    {
        $todos = array_merge($this->requisitos(), $this->permisos());
        return array_values(array_filter($todos, static fn(array $r): bool => $r['estado'] === 'fail'));
    }

    /* =====================================================================
       Paso 2 · Conexión
       ===================================================================== */

    private function probarConexion(Peticion $peticion): never
    {
        $parametros = $this->parametrosBd($peticion);
        $errores = $this->validarParametrosBd($parametros);

        if ($errores) {
            Respuesta::json(['ok' => false, 'mensaje' => reset($errores)]);
        }

        [$ok, $mensaje] = Bd::probar($parametros);
        Respuesta::json(['ok' => $ok, 'mensaje' => $mensaje]);
    }

    private function parametrosBd(Peticion $peticion): array
    {
        return [
            'host'    => $peticion->campo('bd_host', 'localhost'),
            'puerto'  => (int) ($peticion->campo('bd_puerto', '3306') ?: 3306),
            'nombre'  => $peticion->campo('bd_nombre'),
            'usuario' => $peticion->campo('bd_usuario'),
            'clave'   => $peticion->campoCrudo('bd_clave'),
            'prefijo' => $peticion->campo('bd_prefijo', 'evt_'),
        ];
    }

    private function validarParametrosBd(array $p): array
    {
        $errores = [];
        if ($p['nombre'] === '') {
            $errores['bd_nombre'] = 'Escribe el nombre de la base de datos.';
        }
        if ($p['usuario'] === '') {
            $errores['bd_usuario'] = 'Escribe el usuario de la base de datos.';
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $p['prefijo'])) {
            $errores['bd_prefijo'] = 'El prefijo debe empezar por letra minúscula y usar solo letras, números y guion bajo.';
        }
        if ($p['host'] === '' || !preg_match('/^[A-Za-z0-9._\-]{1,120}$/', $p['host'])) {
            $errores['bd_host'] = 'El servidor no parece válido.';
        }
        return $errores;
    }

    /* =====================================================================
       Procesamiento de cada paso
       ===================================================================== */

    private function procesar(Peticion $peticion, string $accion, array $estado, bool $reparacion = false): array
    {
        return match ($accion) {
            'paso1' => $this->paso1($estado),
            'paso2' => $this->paso2($peticion, $estado),
            'paso3' => $this->paso3($peticion, $estado, $reparacion),
            'paso4' => $this->paso4($peticion, $estado),
            'paso5' => $this->paso5($peticion, $estado),
            'atras' => [max(1, (int) $peticion->campo('a', '1')), [], $estado],
            default => [(int) ($estado['paso'] ?? 1), [], $estado],
        };
    }

    private function paso1(array $estado): array
    {
        if ($this->bloqueantes()) {
            return [1, ['general' => 'Hay requisitos sin cumplir. Corrígelos antes de continuar.'], $estado];
        }
        return [2, [], $estado];
    }

    private function paso2(Peticion $peticion, array $estado): array
    {
        $parametros = $this->parametrosBd($peticion);
        $errores = $this->validarParametrosBd($parametros);
        if ($errores) {
            return [2, $errores, $estado + ['bd' => $this->sinClave($parametros)]];
        }

        [$ok, $mensaje] = Bd::probar($parametros);
        if (!$ok) {
            return [2, ['general' => $mensaje], $estado + ['bd' => $this->sinClave($parametros)]];
        }

        // Si veníamos conectados a otra base —una reparación, o alguien que
        // volvió atrás y cambió los datos—, esa conexión ya no sirve.
        Bd::reiniciar();
        Instalacion::olvidar();

        // La contraseña de la base se guarda en config/instalacion.php, un
        // archivo aparte que solo vive mientras dura el asistente y que el
        // paso 6 borra. Antes viajaba en la cookie del proceso, que es
        // exactamente lo que no debe pasar con una credencial.
        //
        // Va en un archivo suyo y no en config/config.php a propósito: que
        // exista config.php tiene que seguir significando «instalación
        // terminada». Escribirlo a medias, con instalado=false, deja el sitio
        // entero redirigiendo al asistente si el proceso se interrumpe después,
        // y desde fuera no hay forma de distinguir eso de una instalación que
        // nunca empezó.
        if (!$this->guardarConexion($parametros)) {
            return [2, ['general' => 'La conexión con la base de datos funciona, pero no se pudo escribir '
                . 'en la carpeta config/. Dale permiso de escritura al usuario del dominio y vuelve a '
                . 'intentarlo: es donde se guarda la configuración.'],
                $estado + ['bd' => $this->sinClave($parametros)]];
        }

        $this->conectarCon($parametros);

        if (Instalacion::incompleta()) {
            Bitacora::registrar('instalacion_reparacion', 'sistema', null, [
                'motivo' => Instalacion::diagnostico()['motivo'],
            ]);
        }

        $estado['bd'] = $this->sinClave($parametros);
        return [3, [], $estado];
    }

    /* ---------------------------------------------------------------------
       Datos de conexión mientras dura el asistente
       --------------------------------------------------------------------- */

    private function rutaConexion(): string
    {
        return Instalacion::rutaConexion();
    }

    private function guardarConexion(array $parametros): bool
    {
        $directorio = dirname($this->rutaConexion());
        if (!is_dir($directorio) && !@mkdir($directorio, 0750, true)) {
            return false;
        }

        $contenido = "<?php\n"
            . "/**\n"
            . " * Datos de conexión mientras dura la instalación.\n"
            . " *\n"
            . " * Lo escribe el paso 2 del asistente y lo borra el paso 6. Existe para que la\n"
            . " * contraseña de la base de datos no viaje en la cookie del proceso.\n"
            . " *\n"
            . " * Si lo encuentras en un servidor que ya funciona, sobra: es de una\n"
            . " * instalación que quedó a medias y se puede borrar sin miedo.\n"
            . " */\n\nreturn " . var_export($parametros, true) . ";\n";

        $temporal = $directorio . '/.conexion-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporal, $contenido, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporal, 0640);

        if (!@rename($temporal, $this->rutaConexion())) {
            @unlink($temporal);
            return false;
        }
        // Sin esto, otro proceso de PHP-FPM puede seguir sirviendo la versión
        // anterior del archivo durante minutos.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->rutaConexion(), true);
        }
        return true;
    }

    private function leerConexion(): ?array
    {
        return Instalacion::credencialesEnCurso();
    }

    private function olvidarConexion(): void
    {
        @unlink($this->rutaConexion());
        clearstatcache(true, $this->rutaConexion());

        // config/.llave-asistente se queda donde está a propósito. Firma el
        // estado del proceso, y la pantalla de resumen —que es la petición
        // siguiente a esta— tiene que poder verificarlo. Borrarla aquí mandaba
        // al recién instalado a la pantalla de acceso sin haberle enseñado
        // nunca el resumen con su correo, su evento y lo que faltaba por hacer.
        // No es una credencial de nada más y el asistente ya está cerrado.
    }

    /** Deja la conexión lista para el resto de la petición y de las siguientes. */
    private function conectarCon(array $parametros): void
    {
        Config::establecerEnMemoria([
            'bd_host'    => $parametros['host'],
            'bd_puerto'  => (int) $parametros['puerto'],
            'bd_nombre'  => $parametros['nombre'],
            'bd_usuario' => $parametros['usuario'],
            'bd_clave'   => $parametros['clave'],
            'bd_prefijo' => $parametros['prefijo'],
        ]);
        Bd::reiniciar();
        Bd::conectar();
        Bd::establecerPrefijo((string) $parametros['prefijo']);
    }

    private function paso3(Peticion $peticion, array $estado, bool $reparacion = false): array
    {
        // Si el formulario llega sin modo, o con uno inventado, se hace lo que
        // no borra nada. Antes caía en «limpio»: con la plataforma en producción,
        // un envío incompleto bastaba para vaciar la base.
        $modo = $peticion->campo('modo', 'actualizar');
        if (!in_array($modo, ['limpio', 'actualizar', 'anexar'], true)) {
            $modo = 'actualizar';
        }
        // En una reparación no se borra nada, aunque el formulario venga
        // manipulado: quien llega aquí lo hace porque el sitio está roto, no
        // para vaciarlo.
        if ($reparacion && $modo === 'limpio') {
            $modo = 'actualizar';
        }

        if (!$this->reconectar()) {
            return [2, ['general' => 'Se perdió la conexión con la base de datos. Vuelve a escribir los datos.'], $estado];
        }

        try {
            $existentes = Esquema::existentes();
        } catch (\Throwable $e) {
            return [3, ['general' => 'No se pudo revisar la base de datos: ' . $e->getMessage()], $estado];
        }

        // Datos cifrados sin llave con que leerlos: se pide la anterior antes de
        // tocar nada. Ver estadoDeLaLlave().
        $llave = $this->estadoDeLaLlave();
        if ($llave['falta']) {
            $pegada = $this->llavePegada($peticion->campoCrudo('llave_previa'));
            if ($pegada !== '') {
                if (!Instalacion::llaveAbreLosDatos($pegada, $llave['muestras'])) {
                    return [3, ['llave_previa' => 'Esa llave no abre los datos de esta base. Cópiala del '
                        . 'config/config.php que estaba en el servidor antes de actualizar: es la línea '
                        . '\'llave_cifrado\'.'], $estado];
                }
                // Va al mismo archivo que la contraseña de la base, que vive solo
                // mientras dura el asistente. Nunca a la cookie del proceso.
                $conexion = (array) $this->leerConexion();
                if (!$this->guardarConexion(['llave_cifrado' => $pegada] + $conexion)) {
                    return [3, ['general' => 'No se pudo escribir en la carpeta config/.'], $estado];
                }
                unset($estado['llave_nueva']);
            } elseif ($peticion->campo('sin_llave_previa') === '1') {
                $estado['llave_nueva'] = true;
            } else {
                return [3, ['llave_previa' => 'La base tiene datos cifrados y falta la llave con que se '
                    . 'cifraron. Pégala aquí, o marca la casilla para seguir sin ella sabiendo lo que se '
                    . 'pierde.'], $estado];
            }
        }

        // Borrar tablas con datos pide una confirmación aparte, no solo elegir
        // la opción. Un clic de más en el radio equivocado no puede costar el
        // registro de un evento.
        if ($modo === 'limpio' && $existentes !== [] && $peticion->campo('confirmar_limpio') !== '1') {
            return [3, ['general' => 'La instalación limpia borra las ' . count($existentes)
                . ' tablas que ya hay y todo lo que guardan. Si de verdad es lo que quieres, marca la '
                . 'casilla que lo confirma. Para conservar los datos elige «Actualizar lo existente».'], $estado];
        }

        try {
            $hechas = Esquema::aplicar($modo, $existentes);
        } catch (\Throwable $e) {
            return [3, ['general' => 'No se pudo aplicar el esquema: ' . $e->getMessage()], $estado];
        }
        \App\Nucleo\Actualizacion::crearCarpetas();

        $estado['modo'] = $modo;
        $estado['tablas'] = $hechas;
        return [4, [], $estado];
    }

    private function paso4(Peticion $peticion, array $estado): array
    {
        $nombre = $peticion->campo('ad_nombre');
        $correo = mb_strtolower($peticion->campo('ad_correo'));
        $clave = $peticion->campoCrudo('ad_clave');
        $clave2 = $peticion->campoCrudo('ad_clave2');

        $errores = [];
        if (mb_strlen($nombre) < 5) {
            $errores['ad_nombre'] = 'Escribe el nombre completo.';
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores['ad_correo'] = 'Escribe un correo válido.';
        }
        if (mb_strlen($clave) < 12) {
            $errores['ad_clave'] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        if ($clave !== $clave2) {
            $errores['ad_clave2'] = 'Las dos contraseñas deben coincidir.';
        }
        if ($errores) {
            return [4, $errores, $estado + ['admin' => ['nombre' => $nombre, 'correo' => $correo]]];
        }

        // Se guarda el hash, no la contraseña. El estado del asistente viaja en
        // una cookie firmada pero legible: si alguien la captura, con el hash no
        // obtiene la contraseña del administrador del evento.
        $estado['admin'] = [
            'nombre'     => $nombre,
            'correo'     => $correo,
            'clave_hash' => Cripto::hashClave($clave),
            'exigir_2fa' => $peticion->marcado('ad_2fa'),
        ];
        return [5, [], $estado];
    }

    /** Paso 5: se crea todo y se escribe la configuración. */
    private function paso5(Peticion $peticion, array $estado): array
    {
        $conexion = $this->leerConexion();
        if ($conexion === null || empty($estado['admin'])) {
            return [2, ['general' => 'Faltan datos de pasos anteriores. Empieza de nuevo.'], $estado];
        }
        if (!$this->reconectar()) {
            return [2, ['general' => 'Se perdió la conexión con la base de datos.'], $estado];
        }

        $nombreEvento = $peticion->campo('ev_nombre', 'Evento sin nombre');
        $fecha = $peticion->campo('ev_inicio');
        $jornadas = max(1, min(30, (int) $peticion->campo('ev_dias', '3')));

        $errores = [];
        if (mb_strlen($nombreEvento) < 3) {
            $errores['ev_nombre'] = 'Escribe el nombre del evento.';
        }
        if (!Evento::fechaValida($fecha)) {
            $errores['ev_inicio'] = 'Elige la fecha de inicio.';
        }
        if ($errores) {
            return [5, $errores, $estado];
        }

        $preset = $peticion->campo('preset', 'tic-nocturno');
        $tipografia = $peticion->campo('tipografia', 'tecnologica');

        try {
            // La llave de cifrado se genera aquí y solo viaja al archivo de
            // configuración; nunca a la cookie del asistente. Y se genera una
            // sola vez: en una reparación sobre una instalación que ya tiene
            // datos, cambiarla dejaría ilegibles todos los documentos guardados.
            //
            // Si en el paso 3 se dio la llave anterior —config/config.php se
            // había perdido—, esa es la buena: se comprobó contra los datos.
            $llave = (string) ($conexion['llave_cifrado'] ?? '')
                ?: (string) Config::obtener('llave_cifrado', '')
                ?: Cripto::generarLlave();

            // Lo que este asistente decide, lo que ya hubiera en el archivo, y
            // los valores por defecto: en ese orden, porque con la unión gana el
            // de la izquierda. Reparar una instalación no puede llevarse por
            // delante lo que alguien ajustó a mano —los proxies de confianza, el
            // remitente del correo— solo porque el formulario no lo pregunta.
            $previa = Config::todo();

            $configuracion = [
                'instalado'       => true,
                'version'         => APP_VERSION,
                'bd_host'         => $conexion['host'],
                'bd_puerto'       => (int) $conexion['puerto'],
                'bd_nombre'       => $conexion['nombre'],
                'bd_usuario'      => $conexion['usuario'],
                'bd_clave'        => $conexion['clave'],
                'bd_prefijo'      => $conexion['prefijo'],
                'llave_cifrado'   => $llave,
                'url_base'        => rtrim(\App\Nucleo\App::peticion()->origen()
                                        . \App\Nucleo\App::peticion()->base(), '/'),
                'exigir_2fa_admin' => (bool) ($estado['admin']['exigir_2fa'] ?? true),
                'instalado_en'    => $previa['instalado_en'] ?? date('c'),
            ] + $previa + [
                'zona_horaria'    => 'America/Bogota',
                'correo_remitente' => 'no-responder@' . $this->dominioDelSitio(),
                'correo_nombre'   => $nombreEvento,
                'modo_correo'     => function_exists('mail') ? 'php' : 'registro',
                'proxies_confiables' => $this->proxiesDetectados(),
                'depurar'         => false,
            ];

            // ORDEN IMPORTANTE. La configuración se escribe al final, y solo si
            // todo lo demás salió bien.
            //
            // Antes se escribía primero, y una excepción en cualquiera de los
            // pasos siguientes dejaba la plataforma marcada como instalada pero
            // sin cuenta administradora: el asistente respondía «ya está
            // instalada» y el acceso del equipo «correo o contraseña
            // incorrectos». Sin manera de entrar y sin manera de reintentar.
            //
            // Nada de lo que viene a continuación necesita la llave de cifrado
            // ni el archivo en disco: basta con tener la configuración en
            // memoria para resolver el prefijo de las tablas.
            Config::establecerEnMemoria($configuracion);
            Cripto::olvidarLlave();
            Bd::establecerPrefijo((string) $configuracion['bd_prefijo']);

            // Al actualizar, lo normal es escribir el correo de la cuenta que ya
            // se usa. Se le cambia la contraseña y nada más: conserva su
            // segundo factor, y el código del teléfono sigue sirviendo.
            $previo = Usuario::porCorreo((string) $estado['admin']['correo']);
            $usuarioId = Usuario::asegurarAdministrador(
                (string) $estado['admin']['correo'],
                (string) $estado['admin']['nombre'],
                (string) $estado['admin']['clave_hash']
            );

            // Con una llave nueva, el segundo factor de esta cuenta ya no se
            // puede leer. Quien está aquí probó tener las credenciales de la
            // base, que es más que el segundo factor: se le quita, y al entrar
            // configura la aplicación otra vez. Las demás cuentas lo resuelven
            // al entrar, con un código enviado a su correo.
            $rehacer2fa = false;
            if ($previo !== null && Usuario::estadoSegundoFactor($previo) === 'ilegible') {
                Usuario::quitarSegundoFactor($usuarioId);
                Bitacora::registrar('segundo_factor_retirado', 'usuario', $usuarioId, ['motivo' => 'llave nueva']);
                $rehacer2fa = true;
            }

            // Si ya hay un evento activo, este paso no crea otro. Reparando una
            // instalación que ya tiene registros, crear uno nuevo lo dejaba
            // como activo y el que tenía a toda la gente inscrita pasaba a
            // segundo plano: los asistentes veían un evento vacío.
            $eventoId = (int) ($estado['evento_id'] ?? 0);
            if ($eventoId === 0) {
                // El evento en memoria puede ser de otra base: el paso 2 pudo
                // cambiar los datos de conexión.
                \App\Nucleo\App::olvidarEvento();
                $activo = \App\Nucleo\App::eventoActivo();
                if ($activo !== null) {
                    $eventoId = (int) $activo['id'];
                    $estado['evento_id'] = $eventoId;
                }
            }
            $eventoExistia = $eventoId !== 0;
            if ($eventoId === 0) {
                $eventoId = Evento::crear([
                    'nombre'       => $nombreEvento,
                    'dependencia'  => $peticion->campo('ev_dependencia'),
                    'sede'         => $peticion->campo('ev_sede'),
                    'fecha_inicio' => $fecha,
                    'jornadas'     => $jornadas,
                    'estado'       => 'abierto',
                    'activo'       => true,
                    'preset'       => $preset,
                    'tipografia'   => $tipografia,
                ]);
                // Si la escritura del archivo falla y hay que reintentar, no se
                // crea un segundo evento repetido.
                $estado['evento_id'] = $eventoId;
            }

            if (!Config::escribir($configuracion)) {
                return [5, ['general' => 'La cuenta y el evento quedaron creados, pero no se pudo escribir '
                    . 'config/config.php. Dale permiso de escritura a la carpeta config/ y vuelve a pulsar '
                    . 'Terminar: no se duplicará nada.'], $estado];
            }

            // Terminado: los datos de conexión ya viven en config/config.php y
            // el archivo del proceso sobra. Dejarlo ahí es una copia más de la
            // contraseña de la base sin ninguna razón.
            $this->olvidarConexion();

            // Lo mismo con el permiso para reabrir el asistente: sirvió para
            // esta vez. Olvidarse de borrarlo deja la instalación abierta a
            // cualquiera que tenga las credenciales de la base.
            $permiso = RAIZ . '/config/permitir-reinstalar';
            if (is_file($permiso)) {
                @unlink($permiso);
            }

            Instalacion::olvidar();
            // La marca de «base al día» era de antes del asistente: que la
            // próxima petición vuelva a mirar en vez de fiarse de ella.
            \App\Nucleo\Actualizacion::olvidar();
            Bitacora::registrar('instalacion', 'sistema', $eventoId, [
                'modo'     => $estado['modo'] ?? 'limpio',
                'jornadas' => $jornadas,
            ]);

            $estado['resultado'] = [
                'usuario_id' => $usuarioId,
                'evento_id'  => $eventoId,
                'correo'     => $estado['admin']['correo'],
                'evento'     => $nombreEvento,
                'jornadas'   => $jornadas,
                'prefijo'    => (string) $configuracion['bd_prefijo'],
                'tabla_usuario' => $configuracion['bd_prefijo'] . 'usuario',
                'exigir_2fa' => (bool) ($estado['admin']['exigir_2fa'] ?? true),
                'https'      => \App\Nucleo\App::peticion()->esSegura(),
                'correo_ok'  => Correo::disponible(),
                'cuenta_existia'  => $previo !== null,
                'conserva_2fa'    => $previo !== null && !$rehacer2fa && (int) ($previo['totp_confirmado'] ?? 0) === 1,
                'rehacer_2fa'     => $rehacer2fa,
                'llave_nueva'     => !empty($estado['llave_nueva']),
                'evento_existia'  => $eventoExistia,
                'permiso_abierto' => is_file($permiso),
            ];
            // Ya no hace falta arrastrar nada de esto en la cookie.
            unset($estado['bd'], $estado['admin'], $estado['evento_id']);
            $this->guardarEstado(['paso' => 6, 'resultado' => $estado['resultado']]);

            return [6, [], $estado];
        } catch (\Throwable $e) {
            \App\Nucleo\Registro::excepcion($e);
            return [5, ['general' => 'La instalación falló: ' . $e->getMessage()], $estado];
        }
    }

    /**
     * El proxy que tenemos delante, si es que hay uno.
     *
     * En Plesk casi siempre hay nginx por delante de Apache. Sin declararlo, la
     * aplicación ve su dirección en lugar de la del visitante y el límite de
     * intentos pasa a ser uno solo para todo el mundo: veinte accesos fallidos
     * de cualquiera dejan fuera al equipo entero.
     *
     * Solo se confía en la dirección desde la que llega la petición, y solo si
     * es interna —loopback o rango privado— y además viene una cabecera de
     * reenvío. Nadie llega desde internet con una dirección así, de modo que
     * esto no se puede provocar desde fuera. Cualquier otro caso se deja vacío,
     * porque confiar en X-Forwarded-For sin más permitiría a cualquiera falsear
     * su origen y saltarse los bloqueos.
     *
     * @return array<int, string>
     */
    private function proxiesDetectados(): array
    {
        if (!\App\Nucleo\App::peticion()->detrasDeProxySinConfigurar()) {
            return [];
        }
        $remota = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remota !== '' ? [$remota] : [];
    }

    private function dominioDelSitio(): string
    {
        $host = parse_url(\App\Nucleo\App::peticion()->origen(), PHP_URL_HOST) ?: 'localhost';
        return preg_replace('/^www\./', '', (string) $host) ?: 'localhost';
    }

    /**
     * Vuelve a abrir la conexión entre un paso y el siguiente.
     *
     * La contraseña sale de config/instalacion.php, que el paso 2 dejó escrito.
     * La cookie del asistente solo lleva lo que no es secreto: servidor, base,
     * usuario y prefijo, para poder repintar el formulario.
     */
    private function reconectar(): bool
    {
        $conexion = $this->leerConexion();
        if ($conexion === null) {
            return false;
        }
        try {
            $this->conectarCon($conexion);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function sinClave(array $parametros): array
    {
        unset($parametros['clave']);
        return $parametros;
    }

    /* =====================================================================
       Estado entre pasos
       -------------------------------------------------------------------------
       Va en una cookie firmada con una llave derivada del servidor. Todavía no
       hay base de datos donde guardarlo. La firma evita que alguien manipule
       los datos de conexión a mitad del proceso.
       ===================================================================== */

    private function llaveEstado(): string
    {
        // Un secreto de verdad, guardado aparte y generado una sola vez.
        //
        // Antes la llave era hash(RAIZ . PHP_VERSION), que no es secreta: la
        // ruta de instalación en Plesk es previsible y la versión de PHP la
        // publica el propio servidor. Con las dos, cualquiera podía firmar una
        // cookie de estado válida durante la ventana en que el asistente está
        // abierto y colar su propio correo como cuenta administradora.
        //
        // Y además cambiaba sola: bastaba una actualización menor de PHP a
        // mitad de instalación para invalidar el estado y devolver al paso 1
        // sin explicar por qué.
        static $llave = null;
        if ($llave !== null) {
            return $llave;
        }

        $ruta = RAIZ . '/config/.llave-asistente';
        if (is_readable($ruta)) {
            $guardada = (string) @file_get_contents($ruta);
            if (strlen($guardada) >= 32) {
                return $llave = $guardada;
            }
        }

        $nueva = bin2hex(random_bytes(32));
        if (@file_put_contents($ruta, $nueva, LOCK_EX) !== false) {
            @chmod($ruta, 0600);
            return $llave = $nueva;
        }

        // config/ sin permiso de escritura. El paso 1 ya lo señala como
        // bloqueante; mientras tanto vale una llave derivada, que es lo que
        // había antes, para no dejar el asistente inservible del todo.
        return $llave = hash('sha256', RAIZ . '|instalador');
    }

    private function leerEstado(): array
    {
        $crudo = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($crudo) || $crudo === '') {
            return [];
        }
        $decodificado = base64_decode($crudo, true);
        if ($decodificado === false || !str_contains($decodificado, '|')) {
            return [];
        }
        [$firma, $carga] = explode('|', $decodificado, 2);
        if (!hash_equals(hash_hmac('sha256', $carga, $this->llaveEstado()), $firma)) {
            return [];
        }
        $datos = json_decode($carga, true);
        return is_array($datos) ? $datos : [];
    }

    private function guardarEstado(array $estado): void
    {
        $carga = json_encode($estado, JSON_UNESCAPED_UNICODE) ?: '{}';
        $valor = base64_encode(hash_hmac('sha256', $carga, $this->llaveEstado()) . '|' . $carga);

        $peticion = \App\Nucleo\App::peticion();
        setcookie(self::COOKIE, $valor, [
            // Doce horas y no una. Instalar rara vez es un acto seguido: se
            // empieza, hay que pedir los datos de la base al área de sistemas y
            // se vuelve por la tarde. Con una hora, el asistente descartaba el
            // estado en silencio y devolvía al paso 1 sin decir nada.
            'expires'  => time() + 43200,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $valor;
    }

    private function olvidarEstado(): void
    {
        $peticion = \App\Nucleo\App::peticion();
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
