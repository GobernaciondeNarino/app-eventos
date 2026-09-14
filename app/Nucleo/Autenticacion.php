<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Cómo entran los participantes y los expositores.
 *
 * Durante meses hubo un solo camino —un código de seis dígitos al correo— y eso
 * ató la plataforma entera a que el correo saliera. Cuando el cortafuegos del
 * servidor resultó tener cerrada la salida SMTP, nadie podía entrar: no era un
 * problema del correo, era un único punto de fallo.
 *
 * Aquí viven los métodos disponibles y cuáles están encendidos. Se pueden tener
 * varios a la vez: el asistente elige el que pueda usar, y si el correo se cae
 * el evento sigue funcionando.
 *
 *   correo    · código de seis dígitos al buzón. El de siempre.
 *   qr        · un QR personal que identifica a su dueño al escanearlo. Se
 *               imprime en la escarapela; en la puerta no hace falta ni red del
 *               asistente ni que recuerde nada.
 *   clave     · una contraseña que la persona elige al registrarse, o al crear
 *               su acceso desde la pantalla de ingreso.
 *   whatsapp  · el mismo código de seis dígitos, por WhatsApp.
 *   sms       · el mismo código, por mensaje de texto.
 *
 * WhatsApp y SMS salen por HTTPS al puerto 443, así que funcionan aunque el
 * servidor tenga cerrada la salida SMTP. Para un evento presencial en Nariño
 * son además el canal que más gente lee.
 */
final class Autenticacion
{
    /**
     * @var array<string, array{
     *   nombre: string, resumen: string, necesita: string, sinTerceros: bool
     * }>
     */
    public const METODOS = [
        'correo' => [
            'nombre'      => 'Correo electrónico',
            'resumen'     => 'Un código de seis dígitos al buzón, que vence en diez minutos y sirve una vez.',
            'necesita'    => 'Que el envío de correo funcione.',
            'sinTerceros' => true,
        ],
        'qr' => [
            'nombre'      => 'QR de acceso',
            'resumen'     => 'Cada persona recibe un QR personal. Escanearlo la identifica: '
                . 'no hay que recordar nada ni esperar ningún mensaje.',
            'necesita'    => 'Nada más. Se genera en la plataforma y se imprime o se muestra en pantalla.',
            'sinTerceros' => true,
        ],
        'clave' => [
            'nombre'      => 'Contraseña simple',
            'resumen'     => 'La persona elige una contraseña al registrarse y entra con correo y contraseña. '
                . 'También permite crear el acceso en la pantalla de ingreso, sin llenar el formulario.',
            'necesita'    => 'Nada más.',
            'sinTerceros' => true,
        ],
        'whatsapp' => [
            'nombre'      => 'WhatsApp',
            'resumen'     => 'El mismo código de seis dígitos, al WhatsApp del teléfono registrado.',
            'necesita'    => 'Una cuenta de WhatsApp Cloud API (Meta) o de Twilio.',
            'sinTerceros' => false,
        ],
        'sms' => [
            'nombre'      => 'Mensaje de texto (SMS)',
            'resumen'     => 'El mismo código, por SMS. Llega aunque el teléfono no tenga datos.',
            'necesita'    => 'Una cuenta de Twilio, o una pasarela SMS con API HTTP.',
            'sinTerceros' => false,
        ],
    ];

    /**
     * Los métodos encendidos, en el orden en que se ofrecen.
     *
     * Siempre queda al menos uno. Apagarlos todos dejaría el evento sin puerta,
     * y eso no puede depender de una casilla mal marcada un viernes por la
     * tarde.
     *
     * @return array<int, string>
     */
    public static function activos(): array
    {
        // Por omisión, los tres que no dependen de nadie: correo, QR y
        // contraseña. Ninguno necesita un tercero ni cuesta dinero, así que una
        // instalación recién hecha ya tiene tres puertas en vez de una. Que la
        // instalación por omisión dependiera de que el correo funcionara es
        // justo lo que dejó un evento sin acceso.
        //
        // La contraseña está entre ellos porque es la que permite crear el
        // acceso en quince segundos desde la pantalla de ingreso: sin ella, la
        // única puerta para quien no está inscrito es el formulario largo.
        $guardados = Config::obtener('auth_metodos', ['correo', 'qr', 'clave']);
        if (!is_array($guardados)) {
            $guardados = ['correo', 'qr', 'clave'];
        }

        $validos = array_values(array_filter(
            $guardados,
            static fn($m): bool => is_string($m) && isset(self::METODOS[$m])
        ));

        // El último cortafuegos: pase lo que pase, quedan varias puertas. Una
        // sola —y encima la que depende de que el correo salga— es el punto
        // único de fallo que costó un evento entero.
        return $validos !== [] ? $validos : ['correo', 'qr', 'clave'];
    }

    public static function activo(string $metodo): bool
    {
        return in_array($metodo, self::activos(), true);
    }

    /** El que se ofrece primero en la pantalla de acceso. */
    public static function preferido(): string
    {
        $activos = self::activos();
        $preferido = (string) Config::obtener('auth_metodo_preferido', '');
        return in_array($preferido, $activos, true) ? $preferido : $activos[0];
    }

    /** ¿Hay algún método que mande un código a algún sitio? */
    public static function hayCodigos(): bool
    {
        return self::activo('correo') || self::activo('whatsapp') || self::activo('sms');
    }

    /** Longitud mínima de la contraseña simple. */
    public static function claveMinima(): int
    {
        return max(6, min(64, (int) Config::obtener('auth_clave_minima', 8)));
    }

    /* =====================================================================
       Revisión: qué está encendido y qué le falta para funcionar
       ===================================================================== */

    /**
     * @return array<int, array{estado: string, titulo: string, detalle: string, arreglo: string}>
     */
    public static function revision(): array
    {
        $lista = [];
        $anotar = static function (string $estado, string $titulo, string $detalle, string $arreglo = '') use (&$lista): void {
            $lista[] = compact('estado', 'titulo', 'detalle', 'arreglo');
        };

        $activos = self::activos();

        // Un solo método, y encima uno que depende de que el correo salga, es
        // el punto único de fallo que ya costó un evento entero.
        if (count($activos) === 1 && $activos[0] === 'correo') {
            $anotar('warn', 'Solo se puede entrar por correo',
                'Si el envío de correo falla —y en este servidor ya falló—, nadie podrá entrar a '
                . 'la plataforma durante el evento.',
                'Enciende además el QR de acceso: no necesita ni red ni terceros, y en la puerta '
                . 'es lo más rápido.');
        }

        if (self::activo('correo')) {
            if (Correo::hayBloqueantes()) {
                $anotar('fail', 'El acceso por correo está encendido pero el correo no funciona',
                    'La revisión de envío de abajo tiene fallos que impiden mandar nada. Quien '
                    . 'intente entrar por este método se quedará esperando un código que no llega.',
                    'Arregla el envío, o apaga este método y usa otro.');
            } else {
                $anotar('ok', 'Acceso por correo',
                    'Código de seis dígitos al buzón registrado.');
            }
        }

        if (self::activo('qr')) {
            $anotar('ok', 'Acceso por QR',
                'Cada persona tiene un QR personal. No depende de la red ni de ningún tercero: es '
                . 'el método que sigue funcionando cuando todo lo demás falla.');
        }

        if (self::activo('clave')) {
            $anotar('ok', 'Acceso por contraseña',
                'Mínimo ' . self::claveMinima() . ' caracteres. Quien se registró antes de '
                . 'encender esto no tiene contraseña todavía: podrá ponerla con un código, o '
                . 'entrar por otro método.');
        }

        foreach (['whatsapp' => 'wa', 'sms' => 'sms'] as $metodo => $prefijo) {
            if (!self::activo($metodo)) {
                continue;
            }
            $nombre = self::METODOS[$metodo]['nombre'];
            $proveedor = (string) Config::obtener($prefijo . '_proveedor', '');
            $token = (string) Config::obtener($prefijo . '_token', '');

            if ($proveedor === '' || $token === '') {
                $anotar('fail', $nombre . ' está encendido pero sin configurar',
                    'Falta ' . ($proveedor === '' ? 'elegir el proveedor' : 'la credencial') . '. '
                    . 'Quien elija este método no recibirá nada.',
                    'Complétalo abajo, o apaga el método.');
            } else {
                $anotar('ok', 'Acceso por ' . $nombre,
                    'Sale por HTTPS al puerto 443, así que funciona aunque el servidor tenga '
                    . 'cerrada la salida SMTP.');
            }
        }

        return $lista;
    }

    /* =====================================================================
       Envío del código por el canal que corresponda
       ===================================================================== */

    /**
     * Manda el código de seis dígitos por WhatsApp o por SMS.
     *
     * Devuelve [enviado, error]. El texto va a propósito sin enlaces: los
     * mensajes con enlace tienen mucha más probabilidad de que los filtren, y
     * un código de seis dígitos se teclea igual de rápido.
     *
     * @return array{0: bool, 1: string}
     */
    public static function enviarCodigo(string $metodo, string $telefono, string $codigo, string $evento): array
    {
        $texto = $codigo . ' es tu código de acceso para ' . $evento
            . '. Vence en 10 minutos y sirve una sola vez. Si no lo pediste, ignora este mensaje.';

        $mensajeria = new Mensajeria(
            $metodo,
            (string) Config::obtener(($metodo === 'sms' ? 'sms' : 'wa') . '_proveedor', ''),
            (string) Config::obtener(($metodo === 'sms' ? 'sms' : 'wa') . '_token', ''),
            (string) Config::obtener(($metodo === 'sms' ? 'sms' : 'wa') . '_remitente', ''),
            (string) Config::obtener(($metodo === 'sms' ? 'sms' : 'wa') . '_cuenta', ''),
            (int) Config::obtener('smtp_espera', 15)
        );

        $ok = $mensajeria->enviar($telefono, $texto);
        return [$ok, $mensajeria->error()];
    }

    /**
     * El teléfono en formato internacional.
     *
     * Colombia es +57 y los móviles tienen diez dígitos. La gente escribe el
     * suyo de seis maneras distintas —con espacios, con guiones, con 57 delante,
     * con 0057, con paréntesis— y todas tienen que llegar al mismo sitio.
     */
    public static function telefonoInternacional(string $telefono, string $paisPorOmision = '57'): string
    {
        $solo = preg_replace('/\D/', '', $telefono) ?? '';
        if ($solo === '') {
            return '';
        }

        // 0057… o 57… ya traen el indicativo.
        if (str_starts_with($solo, '00')) {
            $solo = substr($solo, 2);
        }
        if (strlen($solo) === 10 && str_starts_with($solo, '3')) {
            $solo = $paisPorOmision . $solo;   // móvil colombiano sin indicativo
        }

        return strlen($solo) >= 10 ? '+' . $solo : '';
    }
}
