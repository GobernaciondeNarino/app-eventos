<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Envío de correo.
 *
 * Cuatro modos, según lo que haya en config:
 *   'smtp'     → se habla SMTP con un servidor de verdad. Es lo que hay que
 *                usar cuando el correo del dominio está en Google Workspace o
 *                en Microsoft 365, que es el caso de narino.gov.co.
 *   'api'      → se entrega por HTTPS al 443 a Brevo, SendGrid o Resend. Es la
 *                salida cuando hay una regla de cortafuegos que rechaza el SMTP
 *                saliente del usuario de PHP y no se puede tocar: el 443 es el
 *                mismo puerto por el que el servidor sirve la web.
 *   'php'      → la función mail() del servidor. Entrega al servidor de correo
 *                local; sirve cuando el dominio tiene su buzón en el mismo
 *                Plesk, y no sirve cuando el dominio está en Google: los
 *                mensajes salen sin SPF ni DKIM del dominio y acaban en no
 *                deseado o rechazados.
 *   'registro' → no envía nada; deja el mensaje en almacen/registro. Es lo que
 *                se usa en pruebas y en instalaciones sin correo configurado,
 *                para que la plataforma siga siendo utilizable en vez de
 *                fallar al primer código de acceso.
 *
 * Se configura desde Administración → Correo, que además comprueba la conexión
 * y explica cada código de error del servidor. Ver docs/config-mail.md.
 */
final class Correo
{
    /** El último fallo, para que la pantalla de correo pueda contarlo. */
    private static string $ultimoError = '';
    private static string $ultimaTranscripcion = '';

    public static function ultimoError(): string
    {
        return self::$ultimoError;
    }

    public static function ultimaTranscripcion(): string
    {
        return self::$ultimaTranscripcion;
    }

    public static function enviar(string $destinatario, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
    {
        self::$ultimoError = '';
        self::$ultimaTranscripcion = '';

        $destinatario = trim($destinatario);
        if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Cabeceras inyectadas: un salto de línea en el asunto permitiría
        // agregar destinatarios ocultos y convertir la plataforma en un relé.
        $asunto = str_replace(["\r", "\n"], ' ', $asunto);

        $remitente = (string) Config::obtener('correo_remitente', 'no-responder@narino.gov.co');
        $nombreRemitente = (string) Config::obtener('correo_nombre', 'Eventos TIC Nariño');
        $nombreRemitente = str_replace(['"', "\r", "\n"], '', $nombreRemitente);

        $modo = (string) Config::obtener('modo_correo', 'php');

        if ($cuerpoTexto === '') {
            $cuerpoTexto = self::aTextoPlano($cuerpoHtml);
        }

        $limite = 'lim_' . bin2hex(random_bytes(12));
        $cabeceras = implode("\r\n", [
            'From: ' . self::palabraCodificada($nombreRemitente) . ' <' . $remitente . '>',
            'Reply-To: ' . $remitente,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $limite . '"',
            'X-Mailer: Plataforma de Eventos TIC',
            'Auto-Submitted: auto-generated',
        ]);

        // Base64 y no 8bit. El correo electrónico limita cada línea a 998
        // caracteres (RFC 5321), y la plantilla HTML es una sola línea de varios
        // miles: con 8bit hay servidores que la rechazan y otros que la parten
        // por donde les conviene, y el mensaje llega roto. Como el carnet y el
        // código de acceso son lo único que devuelve a un asistente a su cuenta,
        // que un correo no llegue no es un detalle menor.
        $parte = static fn(string $tipo, string $contenido): string =>
            "Content-Type: $tipo; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($contenido), 76, "\r\n");

        $cuerpo = "--$limite\r\n" . $parte('text/plain', $cuerpoTexto) . "\r\n"
            . "--$limite\r\n" . $parte('text/html', $cuerpoHtml) . "\r\n"
            . "--$limite--\r\n";

        $asuntoCodificado = self::palabraCodificada($asunto, false);

        if ($modo === 'registro') {
            Registro::aviso('Correo no enviado (modo registro)', [
                'para'   => $destinatario,
                'asunto' => $asunto,
                'texto'  => mb_substr($cuerpoTexto, 0, 500),
            ]);
            return true;
        }

        if ($modo === 'api') {
            // El proveedor arma el MIME por su cuenta: se le pasan las piezas.
            $cliente = new CorreoApi(
                (string) Config::obtener('api_proveedor', 'brevo'),
                (string) Config::obtener('api_clave', ''),
                (int) Config::obtener('smtp_espera', 15)
            );
            $enviado = $cliente->enviar(
                ['correo' => $remitente, 'nombre' => $nombreRemitente],
                $destinatario,
                $asunto,
                $cuerpoHtml,
                $cuerpoTexto
            );

            self::$ultimaTranscripcion = $cliente->transcripcion();
            if (!$enviado) {
                self::$ultimoError = $cliente->error();
                Registro::error('API de correo falló', [
                    'para'      => $destinatario,
                    'asunto'    => $asunto,
                    'proveedor' => Config::obtener('api_proveedor', 'brevo'),
                    'codigo'    => $cliente->codigo(),
                    'detalle'   => $cliente->error(),
                ]);
            }
            return $enviado;
        }

        if ($modo === 'smtp') {
            // El destinatario va en la cabecera To y también en el RCPT TO. Con
            // mail() lo ponía PHP; hablando SMTP hay que escribirlo.
            $cabeceras = 'To: ' . $destinatario . "\r\n"
                . 'Subject: ' . $asuntoCodificado . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::dominioDelRemitente($remitente) . ">\r\n"
                . $cabeceras;

            $cliente = self::cliente();
            $enviado = $cliente->enviar($remitente, $destinatario, $cabeceras, $cuerpo);

            self::$ultimaTranscripcion = $cliente->transcripcion();
            if (!$enviado) {
                self::$ultimoError = $cliente->error();
                Registro::error('SMTP falló', [
                    'para'    => $destinatario,
                    'asunto'  => $asunto,
                    'codigo'  => $cliente->codigo(),
                    'detalle' => $cliente->error(),
                ]);
            }
            return $enviado;
        }

        // El quinto parámetro de mail() acaba en la línea de órdenes de
        // sendmail. Solo se pasa si el remitente es un correo válido: cualquier
        // otra cosa ahí es la vía conocida para ejecutar órdenes en el servidor.
        $sobre = filter_var($remitente, FILTER_VALIDATE_EMAIL) ? '-f' . $remitente : '';

        if (!function_exists('mail')) {
            self::$ultimoError = 'La función mail() está desactivada en este servidor '
                . '(disable_functions). Configura el modo SMTP.';
            Registro::error(self::$ultimoError, ['para' => $destinatario]);
            return false;
        }

        $enviado = @mail($destinatario, $asuntoCodificado, $cuerpo, $cabeceras, $sobre);
        if (!$enviado) {
            self::$ultimoError = 'mail() devolvió falso: el servidor de correo local rechazó '
                . 'el mensaje o no está configurado.';
            Registro::error('mail() falló', ['para' => $destinatario, 'asunto' => $asunto]);
        }
        return $enviado;
    }

    /** El cliente SMTP armado con lo que hay en la configuración. */
    private static function cliente(): Smtp
    {
        return new Smtp(
            (string) Config::obtener('smtp_host', 'smtp.gmail.com'),
            (int) Config::obtener('smtp_puerto', 587),
            (string) Config::obtener('smtp_seguridad', 'tls'),
            (string) Config::obtener('smtp_usuario', ''),
            (string) Config::obtener('smtp_clave', ''),
            (int) Config::obtener('smtp_espera', 15),
            (bool) Config::obtener('smtp_verificar_certificado', true),
            (bool) Config::obtener('smtp_solo_ipv4', false),
        );
    }

    /* =====================================================================
       Diagnóstico de red
       -------------------------------------------------------------------------
       Cuando la conexión ni siquiera se abre, la pregunta no es de correo sino
       de red: ¿resuelve el nombre?, ¿por IPv4 o por IPv6?, ¿qué puerto deja
       salir el proveedor? Sin poder responderlas, «Network is unreachable» se
       parece demasiado a «el proveedor lo bloquea todo» y se pierden horas
       pidiendo aperturas de puerto que ya estaban abiertas.
       ===================================================================== */

    /**
     * Prueba la salida a un servidor de correo, dirección por dirección.
     *
     * @return array{
     *   host: string, ipv4: array<int,string>, ipv6: array<int,string>,
     *   intentos: array<int, array{destino: string, familia: string, puerto: int,
     *                              ok: bool, ms: int, error: string}>,
     *   local: array<string, string>, resumen: string
     * }
     */
    public static function diagnosticoDeRed(string $host = '', array $puertos = []): array
    {
        $host = $host !== '' ? $host : (string) Config::obtener('smtp_host', 'smtp.gmail.com');
        $puertos = $puertos !== [] ? $puertos : [587, 465, 25];

        $ipv4 = [];
        $ipv6 = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (str_contains($host, ':')) {
                $ipv6[] = $host;
            } else {
                $ipv4[] = $host;
            }
        } else {
            $ipv4 = @gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $registro) {
                if (!empty($registro['ipv6'])) {
                    $ipv6[] = (string) $registro['ipv6'];
                }
            }
        }

        $intentos = [];
        foreach ($puertos as $puerto) {
            foreach ([['v4', $ipv4], ['v6', $ipv6]] as [$familia, $direcciones]) {
                // Una por familia basta para saber si hay ruta; probarlas todas
                // multiplica la espera sin añadir información.
                $direccion = $direcciones[0] ?? null;
                if ($direccion === null) {
                    continue;
                }

                $literal = $familia === 'v6' ? '[' . $direccion . ']' : $direccion;
                $comienzo = microtime(true);
                $numero = 0;
                $texto = '';
                // Espera corta: aquí solo interesa si hay ruta, y un puerto
                // filtrado no debe dejar la pantalla colgada medio minuto.
                $socket = @stream_socket_client(
                    'tcp://' . $literal . ':' . $puerto,
                    $numero,
                    $texto,
                    5,
                    STREAM_CLIENT_CONNECT
                );
                $ms = (int) round((microtime(true) - $comienzo) * 1000);

                // El resultado se anota ANTES de cerrar. fclose() invalida el
                // recurso, así que un is_resource() posterior devuelve falso y
                // todos los puertos abiertos salían como cerrados.
                $abrio = is_resource($socket);
                if ($abrio) {
                    @fclose($socket);
                }

                $intentos[] = [
                    'destino' => $direccion,
                    'familia' => $familia,
                    'puerto'  => $puerto,
                    'ok'      => $abrio,
                    'ms'      => $ms,
                    'error'   => $abrio ? '' : ($texto !== '' ? $texto : 'sin detalle'),
                ];
            }
        }

        // El servidor de correo de la propia máquina.
        //
        // Es la pieza que faltaba. Cuando el proveedor bloquea la salida SMTP
        // hacia internet, conectarse a 127.0.0.1 **no es tráfico saliente** y
        // el bloqueo no aplica: el Postfix o qmail que monta Plesk sigue
        // escuchando ahí. Es exactamente por donde salen los mensajes de
        // WordPress en esta misma máquina, y la plataforma puede usarlo igual,
        // con la ventaja de conservar toda la conversación para depurar.
        $relayLocal = [];
        foreach ([25, 587, 465] as $puerto) {
            $comienzo = microtime(true);
            $numero = 0;
            $texto = '';
            $socket = @stream_socket_client(
                'tcp://127.0.0.1:' . $puerto,
                $numero,
                $texto,
                3,
                STREAM_CLIENT_CONNECT
            );
            $ms = (int) round((microtime(true) - $comienzo) * 1000);
            $abrio = is_resource($socket);

            // Si abre, se lee el saludo: confirma que hay un servidor de correo
            // detrás y no otra cosa cualquiera escuchando en ese puerto.
            $saludo = '';
            if ($abrio) {
                stream_set_timeout($socket, 3);
                $saludo = trim((string) @fgets($socket, 512));
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }

            $relayLocal[] = [
                'puerto' => $puerto,
                'ok'     => $abrio,
                'ms'     => $ms,
                'saludo' => mb_substr($saludo, 0, 120),
                'error'  => $abrio ? '' : ($texto !== '' ? $texto : 'sin detalle'),
            ];
        }

        $usuarioPhp = function_exists('posix_geteuid')
            ? ((posix_getpwuid(posix_geteuid())['name'] ?? '?') . ' (uid ' . posix_geteuid() . ')')
            : (string) (get_current_user() ?: 'desconocido');

        $local = [
            // Es el dato que hace falta para levantar un bloqueo por usuario:
            // sin saber con qué cuenta corre PHP no hay a quién darle permiso.
            'usuario de PHP'     => $usuarioPhp,
            'mail()'             => function_exists('mail') ? 'disponible' : 'desactivada',
            'sendmail_path'      => (string) (ini_get('sendmail_path') ?: '(sin definir)'),
            'openssl'            => extension_loaded('openssl') ? 'presente' : 'ausente',
            'allow_url_fopen'    => ini_get('allow_url_fopen') ? 'sí' : 'no',
            'disable_functions'  => (string) (ini_get('disable_functions') ?: '(ninguna)'),
        ];

        $rechazado = array_filter(
            $intentos,
            static fn(array $i): bool => !$i['ok'] && str_contains(mb_strtolower($i['error']), 'refused')
        );

        return [
            'host'       => $host,
            'ipv4'       => $ipv4,
            'ipv6'       => $ipv6,
            'intentos'   => $intentos,
            // Las órdenes para levantar el bloqueo, con el UID ya puesto. Solo
            // cuando hace falta: si la salida funciona, esto sobra.
            'cortafuegos' => $rechazado !== [] ? self::ordenesDeCortafuegos() : [],
            'relayLocal' => $relayLocal,
            'local'      => $local,
            'resumen'    => self::resumirRed($intentos, $ipv4, $ipv6, $relayLocal),
        ];
    }

    /**
     * Las órdenes para levantar un bloqueo de SMTP por usuario.
     *
     * Se generan con el UID de verdad de este proceso, que es el dato que hay
     * que buscar a mano y el que se equivoca. La regla estándar de Plesk cubre
     * solo el puerto 25; si el rechazo aparece en 587 y 465, la regla está
     * ampliada —Imunify360 lo hace— y la excepción tiene que cubrir esos.
     *
     * No se ejecuta nada desde aquí, ni podría: PHP no tiene permiso para tocar
     * el cortafuegos, y menos aún debería tenerlo. Esto es texto para copiar en
     * una consola de root.
     *
     * @return array<int, array{titulo: string, orden: string, nota: string}>
     */
    public static function ordenesDeCortafuegos(): array
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $nombre = ($uid !== null && function_exists('posix_getpwuid'))
            ? (posix_getpwuid($uid)['name'] ?? '')
            : (string) get_current_user();

        $quien = $uid !== null ? (string) $uid : ($nombre !== '' ? $nombre : '<UID>');

        return [
            [
                'titulo' => 'Ver si existe la regla que rechaza',
                'orden'  => 'iptables-save | grep owner' . PHP_EOL
                    . 'nft list ruleset | grep -i skuid',
                'nota'   => 'Busca «--uid-owner» o «skuid» junto a los puertos 25, 465 o 587 y un '
                    . 'REJECT. Es la regla anti-spam de Plesk, ampliada a esos puertos.',
            ],
            [
                'titulo' => 'Guardar una copia antes de tocar nada',
                'orden'  => 'iptables-save > /root/reglas-antes-de-correo.txt',
                'nota'   => 'Editar el cortafuegos en producción puede cortar servicios.',
            ],
            [
                'titulo' => 'Permitir la salida a este usuario, por encima del rechazo',
                'orden'  => 'iptables -I OUTPUT 1 -m owner --uid-owner ' . $quien
                    . ' -p tcp -m multiport --dports 587,465 -j ACCEPT',
                'nota'   => 'El UID ' . $quien
                    . ($nombre !== '' ? ' (' . $nombre . ')' : '')
                    . ' es el de este proceso de PHP, el que la aplicación usa de verdad. '
                    . 'Con nftables: nft insert rule inet filter output meta skuid ' . $quien
                    . ' tcp dport { 587, 465 } counter accept',
            ],
            [
                'titulo' => 'Dejarlo puesto para el próximo reinicio',
                'orden'  => 'service iptables save   # RHEL/AlmaLinux' . PHP_EOL
                    . 'netfilter-persistent save   # Debian/Ubuntu',
                'nota'   => 'Si el cortafuegos lo gestiona ConfigServer Firewall, no se edita a '
                    . 'mano: se añade el usuario a SMTP_ALLOWUSER en /etc/csf/csf.conf y se '
                    . 'recarga con «csf -r».',
            ],
        ];
    }

    /** @param array<int, array{familia: string, puerto: int, ok: bool, error: string}> $intentos */
    private static function resumirRed(array $intentos, array $ipv4, array $ipv6, array $relayLocal = []): string
    {
        $localOk = array_values(array_filter($relayLocal, static fn(array $r): bool => $r['ok']));
        $sugerenciaLocal = '';
        if ($localOk !== []) {
            $puerto = (int) $localOk[0]['puerto'];
            $sugerenciaLocal = ' Hay servidor de correo en esta misma máquina, escuchando en '
                . '127.0.0.1:' . $puerto . '. Conectarse ahí no es tráfico saliente, así que el '
                . 'bloqueo no le aplica: es por donde salen los mensajes de WordPress en este '
                . 'servidor. Pon servidor «localhost», puerto ' . $puerto . ', seguridad «sin '
                . 'cifrar» y deja usuario y contraseña vacíos —el botón «Usar el correo local» de '
                . 'abajo lo hace—. Cuida entonces el dominio del remitente: mira el aviso de '
                . 'arriba.';
        }

        if ($intentos === []) {
            return 'El nombre del servidor no se pudo resolver. Revisa el DNS del servidor '
                . '(/etc/resolv.conf) y que el nombre esté bien escrito.' . $sugerenciaLocal;
        }

        $abiertos = array_values(array_filter($intentos, static fn(array $i): bool => $i['ok']));
        $v4 = array_values(array_filter($intentos, static fn(array $i): bool => $i['familia'] === 'v4'));
        $v6 = array_values(array_filter($intentos, static fn(array $i): bool => $i['familia'] === 'v6'));
        $v4ok = array_values(array_filter($v4, static fn(array $i): bool => $i['ok']));
        $v6ok = array_values(array_filter($v6, static fn(array $i): bool => $i['ok']));

        $puertosDe = static fn(array $lista): string => implode(', ', array_unique(array_map(
            static fn(array $i): string => (string) $i['puerto'],
            $lista
        )));

        // Con salida por IPv4, lo demás es ruido: la plataforma la prueba
        // primero y con eso funciona.
        if ($v4ok !== []) {
            $mensaje = 'Hay salida por IPv4 a los puertos ' . $puertosDe($v4ok) . '. Configura uno '
                . 'de esos: 587 con STARTTLS, o 465 con SSL directo.';
            if ($v6 !== [] && $v6ok === []) {
                $mensaje .= ' Por IPv6 no sale, que es de donde viene «Network is unreachable»: el '
                    . 'DNS devuelve una dirección IPv6 y el servidor no tiene ruta por ahí. La '
                    . 'plataforma ya prueba IPv4 primero, así que no estorba; para no intentarlo '
                    . 'siquiera, activa «Usar solo IPv4».';
            }
            return $mensaje;
        }

        if ($v6ok !== []) {
            return 'Solo hay salida por IPv6, a los puertos ' . $puertosDe($v6ok) . '. Funciona, '
                . 'pero conviene preguntarle al proveedor por qué no sale IPv4.';
        }

        // Nada abrió. El motivo dice a quién hay que pedirle qué.
        $tiene = static function (array $lista, string ...$agujas) use (&$intentos): bool {
            foreach ($lista as $i) {
                foreach ($agujas as $aguja) {
                    if (str_contains(mb_strtolower($i['error']), $aguja)) {
                        return true;
                    }
                }
            }
            return false;
        };

        $comun = $sugerenciaLocal !== ''
            ? $sugerenciaLocal
            : ' Mientras se resuelve, el modo «Función mail() del servidor» entrega por el correo '
                . 'local, que es por donde salen los mensajes de WordPress en esta misma máquina; '
                . 'revisa entonces el aviso sobre el dominio del remitente.';

        if ($tiene($v4, 'timed out', 'timeout')) {
            return 'Ningún puerto abrió y los intentos por IPv4 se quedaron esperando hasta agotar '
                . 'el tiempo. Un puerto filtrado se comporta justo así: los paquetes se descartan '
                . 'en silencio. Pídele al proveedor que abra la salida a los puertos 587 y 465 '
                . 'hacia smtp.gmail.com.' . $comun;
        }

        if ($tiene($v4, 'refused')) {
            return 'La salida SMTP está bloqueada a propósito: los puertos responden «rechazado» al '
                . 'instante, y eso no lo hace una red sin ruta —esa da «unreachable»— ni un puerto '
                . 'filtrado —ese se queda esperando—. Lo hace un cortafuegos con regla de rechazo. '
                . 'Compruébalo desde una consola con «nc -zv smtp.gmail.com 587»: si desde ahí SÍ '
                . 'conecta, el bloqueo es por usuario y no de la máquina, y lo que hay que hacer es '
                . 'permitirle la salida al usuario con el que corre PHP —el que aparece abajo, en '
                . '«Cómo está PHP en este servidor»—.' . $comun;
        }

        if ($tiene($intentos, 'unreachable', 'no route')) {
            return 'Ningún intento encontró ruta hasta el servidor de correo. «Unreachable» no es '
                . 'un puerto cerrado: es que el sistema no sabe por dónde salir. Si solo falla '
                . 'IPv6, actívale «Usar solo IPv4»; si falla también IPv4, el servidor no tiene '
                . 'salida a internet por esos puertos.' . $comun;
        }

        return 'Ningún puerto respondió. Pídele al proveedor que abra la salida a los puertos 587 '
            . 'y 465 hacia smtp.gmail.com.' . $comun;
    }

    private static function dominioDelRemitente(string $remitente): string
    {
        $dominio = self::dominioDe($remitente);
        return $dominio !== '' ? $dominio : 'localhost';
    }

    /** El dominio de una dirección, en minúsculas y sin «www.». Vacío si no lo parece. */
    private static function dominioDe(string $direccion): string
    {
        $partes = explode('@', $direccion);
        $dominio = mb_strtolower(trim((string) end($partes)));
        $dominio = (string) preg_replace('/^www\./', '', $dominio);
        return preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $dominio) && str_contains($dominio, '.')
            ? $dominio
            : '';
    }

    /**
     * Texto listo para ir en una cabecera de correo (RFC 2047).
     *
     * El nombre del evento lleva tildes y ñ. Metido tal cual en From, la
     * cabecera queda con bytes de 8 bits y hay receptores estrictos —Exchange y
     * Office 365, entre otros— que rechazan el mensaje entero. Y un solo
     * encoded-word no puede pasar de 75 caracteres, así que si hace falta se
     * parte en varios, que es lo que dice la norma.
     *
     * Si el texto es ASCII puro no se toca: así los asuntos normales siguen
     * leyéndose tal cual en cualquier cliente.
     */
    private static function palabraCodificada(string $texto, bool $entreComillas = true): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $texto)) {
            return $entreComillas ? '"' . $texto . '"' : $texto;
        }

        // 45 bytes por trozo: en base64 son 60 caracteres, y con el envoltorio
        // «=?UTF-8?B?…?=» el encoded-word queda por debajo de los 75 del RFC.
        $trozos = [];
        foreach (mb_str_split($texto, 15, 'UTF-8') as $parte) {
            $trozos[] = '=?UTF-8?B?' . base64_encode($parte) . '?=';
        }
        return implode("\r\n ", $trozos);
    }

    /**
     * La versión en texto del mensaje.
     *
     * strip_tags() a secas se lleva por delante los enlaces: «Ver mi carnet»
     * sobrevive, la dirección no. Quien lea el correo en texto plano —o cuyo
     * cliente bloquee el HTML— se queda con un botón que no existe y sin ninguna
     * dirección a la que ir. Aquí el enlace se escribe entero antes de quitar
     * las etiquetas.
     */
    private static function aTextoPlano(string $html): string
    {
        $texto = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $destino = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $rotulo = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
                return $rotulo === '' || $rotulo === $destino ? $destino : $rotulo . ': ' . $destino;
            },
            $html
        ) ?? $html;

        // Los saltos de párrafo se conservan para que el texto sea legible.
        $texto = preg_replace('#<\s*/\s*(p|div|tr|h[1-6])\s*>#i', "\n\n", $texto) ?? $texto;
        $texto = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $texto) ?? $texto;
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8');
        $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;

        return trim($texto);
    }

    /** ¿Está el correo listo para usarse? Lo consulta el instalador. */
    public static function disponible(): bool
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        if ($modo === 'registro') {
            return true;
        }
        if ($modo === 'smtp') {
            return (string) Config::obtener('smtp_host', '') !== '';
        }
        if ($modo === 'api') {
            return (string) Config::obtener('api_clave', '') !== '';
        }
        return function_exists('mail');
    }

    /* =====================================================================
       Revisión de la configuración
       -------------------------------------------------------------------------
       Todo esto se mira sin tocar la red, para que la pantalla de correo cargue
       rápido. La prueba con el servidor está en probar(), detrás de un botón.
       ===================================================================== */

    /**
     * Qué está bien y qué no, con el arreglo concreto de cada cosa.
     *
     * @return array<int, array{estado: string, titulo: string, detalle: string, arreglo: string}>
     */
    public static function revision(): array
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        $remitente = trim((string) Config::obtener('correo_remitente', ''));
        $lista = [];

        $anotar = static function (string $estado, string $titulo, string $detalle, string $arreglo = '') use (&$lista): void {
            $lista[] = compact('estado', 'titulo', 'detalle', 'arreglo');
        };

        /* ---- El modo ---- */

        if ($modo === 'registro') {
            $anotar('fail', 'No se está enviando ningún correo',
                'El modo es «solo registrar»: los mensajes se escriben en almacen/registro/ y '
                . 'nadie los recibe. Quien pierda la sesión no podrá volver a entrar, porque el '
                . 'código de acceso es lo único que lo identifica.',
                'Cambia el modo a «Servidor SMTP» y completa los datos de abajo.');
        } elseif ($modo === 'php') {
            if (!function_exists('mail')) {
                $anotar('fail', 'La función mail() está desactivada',
                    'Este PHP tiene mail() en disable_functions, así que el modo actual no puede '
                    . 'enviar absolutamente nada.',
                    'Cambia a «Servidor SMTP».');
            } else {
                $anotar('ok', 'Envío por la función mail() del servidor',
                    'Entrega al servidor de correo local, que es por donde salen los mensajes de '
                    . 'WordPress en esta misma máquina. Funciona sin depender de que el proveedor '
                    . 'deje salir por el puerto 587.');
            }

            // Lo que decide si llega a la bandeja o a no deseado es de quién
            // dice venir el mensaje, no cómo se envía.
            $dominioRemitente = self::dominioDe($remitente);
            $dominioServidor = self::dominioDe('x@' . (string) ($_SERVER['SERVER_NAME'] ?? ''));

            if ($dominioRemitente !== '' && $dominioServidor !== '' && $dominioRemitente !== $dominioServidor) {
                $anotar('warn', 'El remitente es de un dominio que este servidor no gestiona',
                    'Los mensajes dirán venir de «' . $dominioRemitente . '» y saldrán desde '
                    . '«' . $dominioServidor . '». Si el correo de ' . $dominioRemitente . ' está '
                    . 'en Google Workspace, su registro SPF solo autoriza a Google, así que este '
                    . 'servidor no está autorizado a enviar en su nombre: el mensaje sale, pero '
                    . 'llega a no deseado o lo rechazan.',
                    'Dos salidas. La buena: usar «Servidor SMTP» con la cuenta institucional. La '
                    . 'práctica, si el proveedor no deja salir por SMTP: poner como remitente una '
                    . 'dirección del subdominio que sí gestiona este Plesk —por ejemplo '
                    . 'no-responder@' . $dominioServidor . '— y crear ese buzón en Plesk → Correo, '
                    . 'que así el mensaje sale con SPF y DKIM válidos.');
            }
        } elseif ($modo === 'api') {
            $proveedor = (string) Config::obtener('api_proveedor', 'brevo');
            $clave = (string) Config::obtener('api_clave', '');

            if (!CorreoApi::conocido($proveedor)) {
                $anotar('fail', 'Proveedor de API desconocido',
                    'Está configurado «' . $proveedor . '», que no es ninguno de los que la '
                    . 'plataforma sabe hablar.',
                    'Elige Brevo, SendGrid o Resend.');
            } else {
                $anotar('ok', 'Envío por la API de ' . CorreoApi::PROVEEDORES[$proveedor]['nombre'],
                    'Se entrega por HTTPS al puerto 443, el mismo por el que este servidor sirve la '
                    . 'web. Una regla de cortafuegos que cierre el SMTP saliente no le afecta.');
            }
            if ($clave === '') {
                $anotar('fail', 'Falta la clave de API',
                    'Sin ella el proveedor rechaza la petición con 401.',
                    'Genera una en ' . (CorreoApi::PROVEEDORES[$proveedor]['panel'] ?? 'el panel del proveedor')
                    . ' con permiso de envío.');
            }
            if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
                $anotar('fail', 'Este PHP no puede hacer peticiones HTTPS',
                    'No tiene cURL y allow_url_fopen está apagado.',
                    'Activa una de las dos en Plesk → Configuración de PHP.');
            }
            $anotar('warn', 'El dominio del remitente tiene que estar verificado en el proveedor',
                'Estos servicios no dejan enviar en nombre de un dominio ajeno: hay que publicar '
                . 'los registros DKIM y de retorno que dan. Sin eso, el envío se rechaza o llega a '
                . 'no deseado.',
                'Verifica narino.gov.co en '
                . (CorreoApi::PROVEEDORES[$proveedor]['panel'] ?? 'el panel del proveedor') . '.');
        } else {
            $anotar('ok', 'Envío por servidor SMTP',
                'Los mensajes salen autenticados como la cuenta institucional, que es lo que hace '
                . 'que lleguen a la bandeja de entrada y no a no deseado.');
        }

        /* ---- Lo que hace falta en el servidor ---- */

        if (!extension_loaded('openssl')) {
            $anotar('fail', 'Falta la extensión openssl',
                'Sin ella no hay TLS, y ni Google ni Microsoft aceptan conexiones sin cifrar.',
                'Actívala en Plesk → Dominio → Configuración de PHP.');
        }
        if (!function_exists('stream_socket_client')) {
            $anotar('fail', 'stream_socket_client está desactivada',
                'Es la función con la que se abre la conexión al servidor de correo.',
                'Quítala de disable_functions en la configuración de PHP.');
        }

        /* ---- El remitente ---- */

        if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            $anotar('fail', 'El remitente no es una dirección válida',
                'Está vacío o mal escrito: «' . ($remitente !== '' ? $remitente : '(vacío)') . '».',
                'Escribe la dirección institucional desde la que saldrán los mensajes.');
        }

        if ($modo !== 'smtp') {
            return $lista;
        }


        /* ---- Los datos del SMTP ---- */

        $host = trim((string) Config::obtener('smtp_host', ''));
        $puerto = (int) Config::obtener('smtp_puerto', 0);
        $seguridad = (string) Config::obtener('smtp_seguridad', 'tls');
        $usuario = trim((string) Config::obtener('smtp_usuario', ''));
        $clave = (string) Config::obtener('smtp_clave', '');

        if ($host === '') {
            $anotar('fail', 'Falta el servidor SMTP',
                'Sin servidor no hay a dónde conectarse.',
                'Para el correo institucional en Google: smtp.gmail.com');
        }
        if ($puerto <= 0 || $puerto > 65535) {
            $anotar('fail', 'El puerto no es válido', 'Se leyó «' . $puerto . '».',
                '587 con STARTTLS, o 465 con SSL directo.');
        }
        if ($puerto === 587 && $seguridad !== 'tls') {
            $anotar('warn', 'El puerto 587 espera STARTTLS',
                'Está configurado como «' . $seguridad . '». El servidor rechazará la conexión o '
                . 'la dejará sin cifrar.',
                'Cambia la seguridad a STARTTLS, o usa el puerto 465 con SSL directo.');
        }
        if ($puerto === 465 && $seguridad !== 'ssl') {
            $anotar('warn', 'El puerto 465 espera SSL directo',
                'Está configurado como «' . $seguridad . '». En ese puerto el cifrado empieza '
                . 'antes del saludo, no con STARTTLS.',
                'Cambia la seguridad a «SSL directo», o usa el puerto 587 con STARTTLS.');
        }
        if ($puerto === 25) {
            $anotar('warn', 'El puerto 25 suele estar bloqueado',
                'Casi todos los proveedores —OVH incluido— cierran la salida por el 25 para '
                . 'frenar el envío masivo. Además Google no acepta autenticación por ahí.',
                'Usa el 587 con STARTTLS.');
        }
        if ($usuario === '') {
            $anotar('warn', 'Sin usuario: se conectará sin autenticarse',
                'Solo sirve con un relé interno que permita enviar sin credenciales. Google y '
                . 'Microsoft siempre las piden.',
                'Escribe la dirección completa de la cuenta: hosting@narino.gov.co');
        }
        if ($usuario !== '' && $clave === '') {
            $anotar('fail', 'Falta la contraseña',
                'Hay usuario pero no contraseña, así que la autenticación fallará con 535.',
                'En Google no sirve la contraseña normal: hace falta una contraseña de aplicación '
                . 'de 16 caracteres.');
        }

        /* ---- Google, que es el caso de esta instalación ---- */

        $esGoogle = str_contains(strtolower($host), 'gmail')
            || str_contains(strtolower($host), 'google');

        if ($esGoogle) {
            if ($clave !== '' && strlen(preg_replace('/\s+/', '', $clave) ?? '') !== 16) {
                $anotar('warn', 'La contraseña no parece una contraseña de aplicación',
                    'Google genera contraseñas de aplicación de exactamente 16 letras. Esta tiene '
                    . strlen(preg_replace('/\s+/', '', $clave) ?? '') . '. Si es la contraseña normal '
                    . 'de la cuenta, Google la rechazará con 534-5.7.9.',
                    'Cuenta de Google → Seguridad → Verificación en dos pasos → Contraseñas de '
                    . 'aplicación. Copia las 16 letras; los espacios dan igual.');
            }
            if ($usuario !== '' && $remitente !== '' && strcasecmp($usuario, $remitente) !== 0) {
                $anotar('warn', 'El remitente no es la cuenta que se autentica',
                    'Se autentica «' . $usuario . '» pero los mensajes dicen venir de «' . $remitente . '». '
                    . 'Google solo lo permite si esa dirección está dada de alta como alias '
                    . 'verificado; si no, reescribe el remitente o rechaza con 550-5.7.1.',
                    'Pon el mismo correo en los dos campos, o da de alta el alias en Gmail → '
                    . 'Configuración → Cuentas → Enviar como.');
            }
        }

        if (!(bool) Config::obtener('smtp_verificar_certificado', true)) {
            $anotar('warn', 'No se verifica el certificado del servidor',
                'La conexión va cifrada, pero nadie comprueba que el servidor sea quien dice ser: '
                . 'alguien en medio de la red podría leer las credenciales.',
                'Actívalo salvo que el servidor de correo sea interno y use un certificado propio.');
        }

        return $lista;
    }

    /** ¿Hay algún problema que impida enviar? */
    public static function hayBloqueantes(): bool
    {
        foreach (self::revision() as $punto) {
            if ($punto['estado'] === 'fail') {
                return true;
            }
        }
        return false;
    }

    /**
     * Prueba de verdad: se conecta, se autentica y —si se pide— envía.
     *
     * @return array{ok: bool, resumen: string, error: string, codigo: string,
     *               transcripcion: string, pistas: array<int, string>}
     */
    public static function probar(string $destinatario = ''): array
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        $vacio = ['ok' => false, 'resumen' => '', 'error' => '', 'codigo' => '',
                  'transcripcion' => '', 'pistas' => []];

        if ($modo === 'registro') {
            return ['resumen' => 'El modo es «solo registrar»: no se intentó enviar nada.',
                'pistas' => ['Cambia el modo a «Servidor SMTP» y vuelve a probar.']] + $vacio;
        }

        if ($modo === 'php') {
            if ($destinatario === '') {
                return ['ok' => false, 'resumen' => 'Escribe una dirección de prueba.'] + $vacio;
            }
            $ok = self::mensajeDePrueba($destinatario);
            return [
                'ok' => $ok,
                'resumen' => $ok
                    ? 'mail() aceptó el mensaje. Eso no garantiza que llegue: revisa la bandeja '
                        . 'de entrada y también la de no deseado.'
                    : 'mail() devolvió falso.',
                'error' => self::$ultimoError,
                'codigo' => '',
                'transcripcion' => '',
                'pistas' => $ok ? [] : [
                    'Revisa que el dominio tenga buzón en este mismo servidor (Plesk → Correo).',
                    'Si el buzón está en Google o Microsoft, mail() no es el camino: usa SMTP.',
                ],
            ];
        }

        if ($modo === 'api') {
            if ($destinatario === '') {
                return ['resumen' => 'Con la API no hay conexión que probar por separado: escribe '
                    . 'una dirección y se manda un mensaje de verdad.'] + $vacio;
            }
            $ok = self::mensajeDePrueba($destinatario);
            $proveedor = (string) Config::obtener('api_proveedor', 'brevo');
            return [
                'ok' => $ok,
                'resumen' => $ok
                    ? 'El proveedor aceptó el mensaje. Revisa ' . $destinatario
                        . ' —también no deseado— en el próximo minuto.'
                    : 'El proveedor rechazó el mensaje.',
                'error' => self::$ultimoError,
                'codigo' => '',
                'transcripcion' => self::$ultimaTranscripcion,
                'pistas' => $ok ? [] : CorreoApi::explicar('', self::$ultimoError, $proveedor),
            ];
        }

        /* ---- SMTP ---- */

        $cliente = self::cliente();

        if ($destinatario === '') {
            $ok = $cliente->comprobar();
            return [
                'ok' => $ok,
                'resumen' => $ok
                    ? 'Conexión y autenticación correctas. No se envió ningún mensaje.'
                    : 'No se pudo conectar o autenticar.',
                'error' => $cliente->error(),
                'codigo' => $cliente->codigo(),
                'transcripcion' => $cliente->transcripcion(),
                'pistas' => $ok ? [] : self::explicar($cliente->codigo(), $cliente->error()),
            ];
        }

        $ok = self::mensajeDePrueba($destinatario);
        return [
            'ok' => $ok,
            'resumen' => $ok
                ? 'Mensaje entregado al servidor de correo. Revisa ' . $destinatario
                    . ' —también la carpeta de no deseado— en el próximo minuto.'
                : 'El servidor de correo rechazó el mensaje.',
            'error' => self::$ultimoError,
            'codigo' => '',
            'transcripcion' => self::$ultimaTranscripcion,
            'pistas' => $ok ? [] : self::explicar('', self::$ultimoError),
        ];
    }

    private static function mensajeDePrueba(string $destinatario): bool
    {
        $evento = (string) Config::obtener('correo_nombre', 'Plataforma de Eventos TIC');
        $cuando = date('Y-m-d H:i:s');

        return self::enviar(
            $destinatario,
            'Prueba de configuración de correo · ' . $cuando,
            self::plantilla($evento, 'La configuración de correo funciona',
                '<p style="margin:0 0 16px">Si estás leyendo esto, la plataforma puede enviar '
                . 'correo: los códigos de acceso y los carnets van a llegar.</p>'
                . '<p style="margin:0;font-size:14px;color:#556">Enviado el ' . htmlspecialchars($cuando)
                . ' desde Administración → Correo.</p>'),
            "La configuración de correo funciona. Enviado el $cuando."
        );
    }

    /**
     * Qué significa el fallo y qué hacer.
     *
     * La lista está escrita a partir de lo que devuelven de verdad Google
     * Workspace y Microsoft 365, que son los dos casos que se dan aquí. Un
     * «no se pudo enviar» a secas no le sirve a nadie: lo que hace falta es
     * saber si sobra un espacio en la contraseña, si falta un alias o si el
     * proveedor tiene cerrado el puerto.
     *
     * @return array<int, string>
     */
    public static function explicar(string $codigo, string $mensaje): array
    {
        $m = mb_strtolower($mensaje);
        $pistas = [];

        $contiene = static fn(string ...$agujas): bool => (bool) array_filter(
            $agujas,
            static fn(string $a): bool => str_contains($m, $a)
        );

        // --- «Network is unreachable»: no hay ruta, y casi siempre es IPv6 ---
        //
        // Se mira antes que nada porque es el fallo que peor se interpreta. No
        // es un cortafuegos —esos dan «Connection refused» o se quedan
        // colgados— sino que el sistema no sabe por dónde salir. Al aparecer
        // igual en el 587 y en el 465, parece que el proveedor lo bloquea todo,
        // y se piden aperturas de puerto que ya estaban abiertas.
        if ($contiene('network is unreachable', 'unreachable', 'no route to host')) {
            $pistas[] = 'El sistema no encontró ruta hasta el servidor de correo. Esto no es un '
                . 'puerto cerrado: un cortafuegos responde «Connection refused» o deja la conexión '
                . 'colgada hasta agotar la espera.';
            $pistas[] = 'La causa habitual es IPv6: smtp.gmail.com publica dirección IPv4 e IPv6, '
                . 'y si el servidor resuelve la IPv6 pero no tiene ruta de salida por ahí, el '
                . 'intento falla al instante. La plataforma ya prueba IPv4 primero; si aun así '
                . 'falla, activa «Usar solo IPv4» en esta misma pantalla.';
            $pistas[] = 'Usa «Probar la salida de red», aquí abajo: dice si hay ruta por IPv4, por '
                . 'IPv6, y por qué puertos. Con eso se sabe si hay que pedirle algo al proveedor o '
                . 'no.';
            $pistas[] = 'Si de verdad no hay ninguna salida SMTP, el modo «Función mail() del '
                . 'servidor» entrega por el correo local del propio servidor, que es por donde '
                . 'salen los mensajes de WordPress en esta misma máquina.';
            return $pistas;
        }

        // --- Antes de llegar a hablar SMTP ---
        if ($contiene('connection refused', 'conexión', 'no se pudo abrir', 'timed out', 'no respondió')) {
            $pistas[] = 'La conexión no llegó a abrirse. Lo más común: el proveedor tiene cerrada '
                . 'la salida a ese puerto. Prueba el 465 con SSL directo si el 587 no responde, y '
                . 'al revés.';
            $pistas[] = 'En Plesk, comprueba que el cortafuegos permita la salida a smtp.gmail.com '
                . 'por los puertos 587 y 465. Desde SSH: nc -vz smtp.gmail.com 587';
            $pistas[] = 'Si el servidor está detrás de un proxy de salida, PHP no lo usa para SMTP: '
                . 'hay que abrir el puerto de verdad.';
        }
        if ($contiene('tls', 'certificado', 'ssl')) {
            $pistas[] = 'El cifrado no se pudo establecer. Revisa que la extensión openssl esté '
                . 'activa y que el reloj del servidor esté en hora: un reloj desfasado invalida '
                . 'cualquier certificado.';
            $pistas[] = 'Si el servidor de correo es interno y usa un certificado propio, desactiva '
                . 'la verificación del certificado en esta misma pantalla.';
        }

        // --- Códigos de autenticación ---
        if ($codigo === '535' || $contiene('username and password not accepted', '5.7.8')) {
            $pistas[] = 'Usuario o contraseña rechazados (535-5.7.8). En Google no sirve la '
                . 'contraseña normal de la cuenta: hay que generar una contraseña de aplicación '
                . 'de 16 letras en Cuenta de Google → Seguridad → Verificación en dos pasos → '
                . 'Contraseñas de aplicación.';
            $pistas[] = 'El usuario tiene que ser la dirección completa, con dominio: '
                . 'hosting@narino.gov.co, no «hosting».';
            $pistas[] = 'Si la cuenta es de Google Workspace, el administrador del dominio puede '
                . 'tener bloqueadas las contraseñas de aplicación en la consola de administración '
                . '(Seguridad → Menos seguras / Acceso de aplicaciones).';
        }
        if ($codigo === '534' || $contiene('5.7.9', 'application-specific password')) {
            $pistas[] = 'Google pide una contraseña de aplicación (534-5.7.9). La cuenta tiene la '
                . 'verificación en dos pasos activa, que es lo correcto, y por eso la contraseña '
                . 'normal no vale aquí.';
        }
        if ($contiene('5.7.14', 'log in via your web browser', 'websignin')) {
            $pistas[] = 'Google bloqueó el intento por venir de un servidor desconocido '
                . '(534-5.7.14). Entra una vez a https://accounts.google.com/DisplayUnlockCaptcha '
                . 'con esa cuenta y vuelve a probar en los diez minutos siguientes.';
        }
        if ($contiene('too many login attempts', '4.7.0') || $codigo === '454') {
            $pistas[] = 'Demasiados intentos seguidos. Google bloquea temporalmente: espera unos '
                . 'minutos antes de volver a probar.';
        }
        if ($contiene('5.7.30', 'basic authentication is not supported')) {
            $pistas[] = 'Microsoft 365 apagó la autenticación básica para envío de clientes '
                . '(550-5.7.30), de forma permanente desde octubre de 2022. Usuario y contraseña '
                . 'ya no sirven contra smtp.office365.com: hay que usar OAuth2, o el relé SMTP '
                . 'del tenant con la IP del servidor autorizada. Si el correo del dominio está en '
                . 'Microsoft y no en Google, esta es la causa.';
        }
        if ($contiene('5.7.515', 'does not meet the required authentication level')) {
            $pistas[] = 'Microsoft rechaza el mensaje por no venir autenticado (550-5.7.515). '
                . 'Desde mayo de 2025 exige SPF, DKIM y DMARC alineados a quien manda más de '
                . '5.000 mensajes al día a Outlook, Hotmail o Live. Publica los tres registros '
                . 'del dominio.';
        }
        if ($codigo === '530' || $contiene('5.5.1 authentication required')) {
            $pistas[] = 'El servidor exige autenticarse y no se le dieron credenciales, o se '
                . 'intentó autenticar antes de cifrar. Revisa que estén el usuario y la contraseña '
                . 'y que la seguridad sea STARTTLS en el 587.';
        }

        // --- Códigos de entrega ---
        if ($contiene('5.7.1', 'relay', 'not allowed to send as', 'sender address rejected')) {
            $pistas[] = 'El servidor no deja enviar con ese remitente (550-5.7.1). La dirección del '
                . 'campo «Remitente» tiene que ser la misma que la cuenta autenticada, o un alias '
                . 'verificado en Gmail → Configuración → Cuentas → Enviar como.';
        }
        if ($contiene('5.4.5', 'daily user sending', 'quota exceeded', 'sending limit')) {
            $pistas[] = 'Se agotó el cupo diario de la cuenta. Google Workspace permite unos 2.000 '
                . 'mensajes al día; una cuenta gratuita, 500. Para un evento con muchos asistentes '
                . 'hay que repartir los envíos o usar un servicio de envío masivo.';
        }
        if ($contiene('5.1.1', 'user unknown', "recipient address rejected", 'no such user')) {
            $pistas[] = 'La dirección de destino no existe. Revisa que esté bien escrita.';
        }
        if ($contiene('5.1.8', '5.1.2', 'domain of sender address', 'does not exist')) {
            $pistas[] = 'El dominio del remitente no se resuelve. Revisa que el correo del campo '
                . '«Remitente» tenga un dominio real y con registros MX.';
        }
        if ($codigo === '552' || $contiene('message too large', '5.3.4')) {
            $pistas[] = 'El mensaje supera el tamaño permitido.';
        }
        if ($codigo === '421' || str_starts_with($codigo, '4')) {
            $pistas[] = 'Es un fallo temporal (código 4xx): el servidor pide reintentar más tarde. '
                . 'Si se repite siempre, suele ser límite de frecuencia.';
        }

        if ($pistas === []) {
            $pistas[] = 'Copia la conversación completa de abajo: el código y el texto del servidor '
                . 'dicen exactamente qué rechazó.';
            $pistas[] = 'Revisa docs/config-mail.md, que lista los códigos habituales y su arreglo.';
        }

        // Recordatorio que aplica a casi todo lo anterior.
        $pistas[] = 'Para que los mensajes no acaben en no deseado, el dominio narino.gov.co debe '
            . 'tener SPF con include:_spf.google.com y DKIM activo en Google Workspace.';

        return $pistas;
    }

    /* =====================================================================
       Mensajes de la plataforma
       ===================================================================== */

    public static function codigoDeAcceso(string $destinatario, string $codigo, string $evento): bool
    {
        $html = self::plantilla(
            $evento,
            'Tu código de acceso',
            '<p style="margin:0 0 16px">Escribe este código en la plataforma para continuar:</p>'
            . '<p style="margin:0 0 16px;font-size:34px;letter-spacing:.3em;font-weight:700;'
            . 'font-family:monospace;color:#0C2E3C">' . htmlspecialchars($codigo) . '</p>'
            . '<p style="margin:0;font-size:14px;color:#556">Vence en 10 minutos y sirve una sola vez. '
            . 'Si no lo pediste, ignora este mensaje: nadie entró a tu cuenta.</p>'
        );

        return self::enviar(
            $destinatario,
            'Tu código de acceso: ' . $codigo,
            $html,
            "Tu código de acceso es $codigo. Vence en 10 minutos y sirve una sola vez."
        );
    }

    /**
     * El código de respaldo del segundo factor, para el equipo organizador.
     *
     * Se usa cuando la aplicación del teléfono no sirve: se cambió de teléfono,
     * se borró la entrada, o la configuración guardada no se puede leer. Llega
     * después de la contraseña, nunca en su lugar, y por eso el mensaje dice
     * qué hacer si no fue uno quien lo pidió: alguien tiene la contraseña.
     */
    public static function codigoSegundoFactor(string $destinatario, string $nombre, string $codigo, string $evento): bool
    {
        $html = self::plantilla(
            $evento,
            'Código para entrar al panel',
            '<p style="margin:0 0 16px">Hola, ' . htmlspecialchars($nombre) . '. Pediste entrar al panel '
            . 'con un código por correo en lugar de la aplicación de autenticación:</p>'
            . '<p style="margin:0 0 16px;font-size:34px;letter-spacing:.3em;font-weight:700;'
            . 'font-family:monospace;color:#0C2E3C">' . htmlspecialchars($codigo) . '</p>'
            . '<p style="margin:0 0 12px;font-size:14px;color:#556">Vence en 10 minutos y sirve una sola vez. '
            . 'Después de entrar puedes configurar otra vez la aplicación desde «Configuración».</p>'
            . '<p style="margin:0;font-size:14px;color:#8A2B2B"><strong>Si no fuiste tú</strong>, alguien '
            . 'escribió tu contraseña correcta: cámbiala en cuanto puedas y avisa a la administración del evento.</p>'
        );

        return self::enviar(
            $destinatario,
            'Código para entrar al panel: ' . $codigo,
            $html,
            "Tu código para entrar al panel es $codigo. Vence en 10 minutos y sirve una sola vez. "
            . 'Si no fuiste tú, alguien escribió tu contraseña correcta: cámbiala y avisa a la administración.'
        );
    }

    /**
     * El mensaje de bienvenida, con el enlace al carnet.
     *
     * $enlaceAcceso es el del QR personal, y cuando existe se usa como botón
     * principal en vez del enlace normal. La diferencia no es cosmética: el
     * mensaje se abre casi siempre desde el teléfono, y el navegador que usa la
     * aplicación de correo no comparte las cookies con el navegador donde la
     * persona se preregistró. Con el enlace normal llegaba a «identifícate»
     * teniendo el carnet ya emitido; con este entra directo.
     */
    public static function carnetEmitido(
        string $destinatario,
        string $nombre,
        string $evento,
        string $enlace,
        string $enlaceAcceso = ''
    ): bool {
        $principal = $enlaceAcceso !== '' ? $enlaceAcceso : $enlace;

        $html = self::plantilla(
            $evento,
            'Tu carnet está listo',
            '<p style="margin:0 0 16px">Hola ' . htmlspecialchars($nombre) . ',</p>'
            . '<p style="margin:0 0 16px">Tu preregistro quedó completo. Desde este enlace puedes ver '
            . 'tu carnet digital con el código QR:</p>'
            . '<p style="margin:0 0 22px"><a href="' . htmlspecialchars($principal) . '" '
            . 'style="background:#0C2E3C;color:#fff;padding:13px 22px;text-decoration:none;'
            . 'display:inline-block;font-weight:600">Ver mi carnet</a></p>'
            . ($enlaceAcceso !== ''
                ? '<p style="margin:0 0 16px;font-size:14px;color:#556">Ese enlace es personal y abre tu '
                  . 'sesión sin pedirte nada: guárdalo y no lo reenvíes a nadie.</p>'
                : '')
            . '<p style="margin:0;font-size:14px;color:#556">No hace falta imprimirlo: basta con mostrarlo '
            . 'desde el celular. Cada día del evento se registra el ingreso escaneando el código de la entrada.</p>'
        );

        return self::enviar($destinatario, 'Tu carnet para ' . $evento, $html);
    }

    private static function plantilla(string $evento, string $titulo, string $contenido): string
    {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f4f8fb;font-family:-apple-system,BlinkMacSystemFont,'
            . '\'Segoe UI\',sans-serif;color:#0B2534">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:560px;background:#fff;border:1px solid #C9DCE8">'
            . '<tr><td style="background:#0C2E3C;padding:18px 24px;color:#E4F7FD;font-size:14px;'
            . 'letter-spacing:.12em;text-transform:uppercase;font-weight:700">'
            . htmlspecialchars($evento) . '</td></tr>'
            . '<tr><td style="padding:28px 24px">'
            . '<h1 style="margin:0 0 18px;font-size:22px;color:#0B2534">' . htmlspecialchars($titulo) . '</h1>'
            . $contenido
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #E3EDF3;font-size:12px;color:#6E8A9C">'
            . 'Secretaría TIC, Innovación y Gobierno Abierto · Gobernación de Nariño<br>'
            . 'Mensaje automático, no respondas a este correo.'
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
