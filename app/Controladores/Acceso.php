<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Persona;
use App\Modelos\Usuario;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Cripto;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Totp;
use App\Nucleo\Url;

/**
 * Los dos accesos de la plataforma.
 *
 * Son distintos porque las personas y los riesgos son distintos:
 *
 *  · El asistente entra con su correo y un código de seis dígitos que le llega
 *    al buzón. Sin contraseña. Pedirle a mil personas que inventen y recuerden
 *    una contraseña para un evento de tres días produce contraseñas malas y
 *    una fila en el punto de información. Su sesión dura treinta días para que
 *    no tenga que repetirlo cada mañana en la puerta.
 *
 *  · El equipo organizador entra con contraseña y, si es administrador, además
 *    con segundo factor. Su sesión es corta y caduca por inactividad: maneja
 *    datos personales de todos los asistentes.
 */
final class Acceso
{
    /* =====================================================================
       Asistente · paso 1: pedir el código
       ===================================================================== */

    public function asistente(Peticion $peticion): void
    {
        $destino = Url::destinoSeguro($peticion->query('destino') ?: $peticion->campo('destino'), '/carnet');

        // Ya identificado: no tiene sentido volver a pedir el correo.
        if (\App\Nucleo\Guardia::personaActual() !== null) {
            Respuesta::redirigir($destino);
        }

        $evento = App::eventoActivo();
        $errores = [];
        $correo = $peticion->campo('correo');

        // En un GET el método llega por la URL —los botones de la pantalla son
        // enlaces— y en el POST por el campo oculto del formulario.
        $metodo = $peticion->campo('metodo') ?: $peticion->query('metodo');
        if ($metodo === '') {
            $metodo = Autenticacion::preferido();
        }
        if (!Autenticacion::activo($metodo)) {
            $metodo = Autenticacion::preferido();
        }

        if ($peticion->esPost()) {
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores['correo'] = 'Escribe un correo válido, por ejemplo nombre@entidad.gov.co';
            } elseif ($metodo === 'clave') {
                // Contraseña simple: se resuelve aquí mismo, sin código.
                Limite::exigir('codigo_correo', $correo);
                $clave = $peticion->campoCrudo('clave');
                $persona = $evento ? Persona::porCorreo((int) $evento['id'], $correo) : null;

                if ($persona !== null && $clave !== '' && Persona::claveValida($persona, $clave)) {
                    Limite::limpiar('codigo_correo', $correo);
                    Sesion::limpiar();
                    Sesion::abrir('asistente', (int) $persona['id']);
                    Bitacora::registrar('acceso_asistente', 'persona', (int) $persona['id'], ['via' => 'clave']);
                    Respuesta::redirigir($destino, 'Bienvenido de nuevo, ' . $persona['nombre'] . '.');
                }

                Limite::registrarFallo('codigo_correo', $correo);
                // El mismo mensaje exista o no la persona, y tenga o no clave
                // puesta: si no, este formulario dice quién está inscrito.
                $errores['clave'] = 'El correo o la contraseña no coinciden.';
            } else {
                Limite::exigir('envio_codigo', $correo);
                // Se cuenta siempre, exista o no la persona. Contando solo los
                // correos no registrados pasaban dos cosas malas a la vez: el
                // bloqueo llegaba únicamente a los buzones que NO están
                // inscritos —o sea que el propio límite decía quién lo está— y
                // a los que sí se les podía pedir un código sin ningún tope,
                // que es una forma cómoda de llenarle el buzón a alguien.
                Limite::registrar('envio_codigo', $correo);

                $persona = $evento ? Persona::porCorreo((int) $evento['id'], $correo) : null;

                if ($persona) {
                    // No se revela si el correo existe: quien no esté registrado
                    // ve exactamente la misma pantalla.
                    $this->enviarCodigo($persona, $evento, $destino, $metodo);
                }

                Sesion::limpiar();
                $this->recordarCorreoPendiente($correo, $destino, $metodo);
                Respuesta::redirigirAbsoluto(Url::a('/entrar/codigo'));
            }
        }

        Respuesta::vista('publico/entrar', [
            'titulo'  => 'Entrar',
            'pantalla' => 'entrar',
            'correo'  => $correo,
            'destino' => $destino,
            'errores' => $errores,
            'metodo'  => $metodo,
            'metodos' => Autenticacion::activos(),
            'catalogo' => Autenticacion::METODOS,
            'puedeCrearCuenta' => Autenticacion::activo('clave'),
        ]);
    }

    /* =====================================================================
       Asistente · crear el acceso con correo y contraseña
       -------------------------------------------------------------------------
       Quien llega a la pantalla de ingreso sin estar inscrito se encontraba con
       que la única salida era un formulario de tres secciones. Ahí se pierde la
       mitad de la gente, y más en la fila de la puerta con el teléfono en una
       mano. Con esto entra en quince segundos y termina sus datos ya dentro,
       que es donde tiene sentido pedirlos.

       El formulario completo sigue abierto al público y sin cambios: esto es
       otra puerta, no un reemplazo.
       ===================================================================== */

    public function crearCuenta(Peticion $peticion): void
    {
        $destino = Url::destinoSeguro($peticion->query('destino') ?: $peticion->campo('destino'), '/registro');

        if (\App\Nucleo\Guardia::personaActual() !== null) {
            Respuesta::redirigir($destino);
        }

        // Sin el método de contraseña encendido, la que se elija aquí no
        // serviría para volver a entrar. Se manda al formulario completo, que
        // es lo que sí funciona en esa configuración.
        if (!Autenticacion::activo('clave')) {
            Respuesta::redirigir('/registro',
                'La organización no tiene encendido el acceso por contraseña. Regístrate aquí.', 'warn');
        }

        $evento = App::eventoExigido();
        $correo = mb_strtolower($peticion->campo('correo'));
        $errores = [];
        $minima = Autenticacion::claveMinima();

        if ($peticion->esPost()) {
            $clave = $peticion->campoCrudo('clave');

            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores['correo'] = 'Escribe un correo válido, por ejemplo nombre@entidad.gov.co';
            }
            if (mb_strlen($clave) < $minima) {
                $errores['clave'] = 'La contraseña debe tener al menos ' . $minima . ' caracteres.';
            } elseif (mb_strlen($clave) > 200) {
                $errores['clave'] = 'La contraseña es demasiado larga.';
            } elseif ($clave !== $peticion->campoCrudo('clave2')) {
                $errores['clave2'] = 'Las dos contraseñas no coinciden.';
            }
            if (!$peticion->marcado('habeas')) {
                $errores['habeas'] = 'Debes autorizar el tratamiento de datos para continuar.';
            }

            if (!$errores) {
                // El mismo límite que el registro completo: aquí el abuso es
                // crear muchas cuentas con éxito, no fallar al intentarlo.
                Limite::exigir('preregistro_ip', $peticion->ip());
                Limite::registrar('preregistro_ip', $peticion->ip());

                try {
                    $resultado = Persona::crearAcceso((int) $evento['id'], $correo, $clave);

                    Sesion::limpiar();
                    Sesion::abrir('asistente', (int) $resultado['id']);

                    Respuesta::redirigir('/registro',
                        'Tu acceso quedó creado. Completa estos datos y te emitimos el carnet.');
                } catch (\DomainException $e) {
                    $errores['correo'] = $e->getMessage();
                    $errores['ofrecer_acceso'] = '1';
                } catch (\Throwable $e) {
                    \App\Nucleo\Registro::excepcion($e);
                    $errores['general'] = 'No se pudo crear el acceso. Inténtalo de nuevo en un momento.';
                }
            }
        }

        Respuesta::vista('publico/crear-acceso', [
            'titulo'      => 'Crear mi acceso',
            'pantalla'    => 'entrar',
            'correo'      => $correo,
            'destino'     => $destino,
            'errores'     => $errores,
            'claveMinima' => $minima,
        ]);
    }

    private function enviarCodigo(array $persona, ?array $evento, string $destino, string $metodo = 'correo'): void
    {
        $codigo = Cripto::codigoNumerico(6);

        // Los códigos anteriores de esa persona dejan de servir: si pidió otro
        // es porque el anterior no le llegó o no lo vio.
        Bd::ejecutar('DELETE FROM {codigo_acceso} WHERE persona_id = ? AND usado_en IS NULL', [$persona['id']]);

        Bd::insertar('codigo_acceso', [
            'persona_id'  => (int) $persona['id'],
            'codigo_hash' => hash('sha256', $codigo),
            'destino'     => mb_substr($destino, 0, 255),
            'expira_en'   => date('Y-m-d H:i:s', time() + 600),
        ]);

        $nombreEvento = (string) ($evento['nombre'] ?? 'Eventos TIC');

        // Por WhatsApp o por SMS el código sale por HTTPS al 443, así que llega
        // aunque el servidor tenga cerrada la salida SMTP. Si el envío falla se
        // anota y se sigue: la pantalla siguiente es la misma en cualquier caso,
        // porque decir «no se pudo enviar» revelaría que ese correo existe.
        if ($metodo === 'whatsapp' || $metodo === 'sms') {
            [$ok, $error] = Autenticacion::enviarCodigo(
                $metodo,
                (string) $persona['telefono'],
                $codigo,
                $nombreEvento
            );
            if (!$ok) {
                \App\Nucleo\Registro::error('No se pudo enviar el código por ' . $metodo, [
                    'persona_id' => (int) $persona['id'],
                    'detalle'    => $error,
                ]);
            }
            return;
        }

        Correo::codigoDeAcceso(
            (string) $persona['correo'],
            $codigo,
            $nombreEvento
        );
    }

    /* =====================================================================
       Asistente · entrar con el QR personal
       -------------------------------------------------------------------------
       El método que sigue funcionando cuando todo lo demás falla: no necesita
       correo, ni WhatsApp, ni que la persona recuerde nada. Se escanea el QR de
       la escarapela y ya está dentro.
       ===================================================================== */

    public function porQr(Peticion $peticion, array $parametros): void
    {
        $token = (string) ($parametros['token'] ?? '');
        $destino = Url::destinoSeguro($peticion->query('destino') ?: '', '/carnet');

        if (!Autenticacion::activo('qr')) {
            Respuesta::error(403, 'El acceso por QR está desactivado',
                'La organización eligió otra forma de entrar. Usa el acceso normal.',
                [['texto' => 'Ir al acceso', 'url' => Url::a('/entrar'), 'principal' => true]]);
        }

        // Adivinar un token de 128 bits no es viable, pero probar muchos desde
        // el mismo sitio sí es una señal que conviene cortar.
        Limite::exigir('token_qr', App::peticion()->ip());

        $persona = Persona::porTokenDeAcceso($token);
        if ($persona === null) {
            Limite::registrarFallo('token_qr', App::peticion()->ip());
            Respuesta::error(404, 'Ese código no corresponde a nadie',
                'Puede que la escarapela sea de otro evento, o que el código se haya regenerado. '
                . 'Pide uno nuevo en el punto de información.',
                [['texto' => 'Entrar de otra forma', 'url' => Url::a('/entrar'), 'principal' => true]]);
        }

        $evento = App::eventoActivo();
        if ($evento !== null && (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::error(404, 'Ese código es de otro evento',
                'La credencial que escaneaste no pertenece al evento en curso.');
        }

        Sesion::limpiar();
        Sesion::abrir('asistente', (int) $persona['id']);
        Bitacora::registrar('acceso_asistente', 'persona', (int) $persona['id'], ['via' => 'qr']);

        Respuesta::redirigir($destino, 'Bienvenido, ' . $persona['nombre'] . '.');
    }

    /* =====================================================================
       Asistente · paso 2: verificar el código
       ===================================================================== */

    public function codigo(Peticion $peticion): void
    {
        $pendiente = $this->correoPendiente();
        if ($pendiente === null) {
            Respuesta::redirigir('/entrar');
        }

        $evento = App::eventoActivo();
        $errores = [];

        if ($peticion->esPost()) {
            $codigo = preg_replace('/\D/', '', $peticion->campo('codigo')) ?? '';
            Limite::exigir('codigo_correo', $pendiente['correo']);

            if (strlen($codigo) !== 6) {
                $errores['codigo'] = 'El código tiene seis dígitos.';
            } else {
                $persona = $evento ? Persona::porCorreo((int) $evento['id'], $pendiente['correo']) : null;
                $fila = $persona ? Bd::fila(
                    'SELECT * FROM {codigo_acceso}
                      WHERE persona_id = ? AND usado_en IS NULL AND expira_en > NOW()
                   ORDER BY id DESC LIMIT 1',
                    [$persona['id']]
                ) : null;

                if ($fila && hash_equals((string) $fila['codigo_hash'], hash('sha256', $codigo))) {
                    Bd::ejecutar('UPDATE {codigo_acceso} SET usado_en = NOW() WHERE id = ?', [$fila['id']]);
                    Limite::limpiar('codigo_correo', $pendiente['correo']);
                    Limite::limpiar('envio_codigo', $pendiente['correo']);

                    Sesion::abrir('asistente', (int) $persona['id']);
                    Bitacora::registrar('acceso_asistente', 'persona', (int) $persona['id']);
                    $this->olvidarCorreoPendiente();

                    $destino = Url::destinoSeguro((string) ($fila['destino'] ?: $pendiente['destino']), '/carnet');
                    Respuesta::redirigir($destino, 'Bienvenido de nuevo, ' . $persona['nombre'] . '.');
                }

                Limite::registrarFallo('codigo_correo', $pendiente['correo']);
                $errores['codigo'] = 'El código no coincide o ya venció. Pide uno nuevo si hace falta.';
            }
        }

        Respuesta::vista('publico/codigo', [
            'titulo'   => 'Código de acceso',
            'pantalla' => 'entrar',
            'metodo'   => $pendiente['metodo'],
            'catalogo' => Autenticacion::METODOS,
            'correo'   => $pendiente['correo'],
            'destino'  => $pendiente['destino'],
            'errores'  => $errores,
            'modoRegistro' => Config::obtener('modo_correo') === 'registro',
        ]);
    }

    public function salirAsistente(Peticion $peticion): void
    {
        Sesion::cerrar('asistente');
        Respuesta::redirigir('/', 'Cerraste sesión.');
    }

    /* ---- Correo pendiente entre los dos pasos -------------------------------
       En una cookie firmada: todavía no hay sesión que lo sostenga.         */

    private function recordarCorreoPendiente(string $correo, string $destino, string $metodo = 'correo'): void
    {
        // El método viaja también: la pantalla del código tiene que decir «te
        // llegó por WhatsApp» y no «revisa tu correo», que es donde la gente
        // mira primero y no encuentra nada.
        $carga = json_encode(['c' => $correo, 'd' => $destino, 'm' => $metodo]) ?: '{}';
        $valor = base64_encode(hash_hmac('sha256', $carga, $this->llave()) . '|' . $carga);
        $peticion = App::peticion();
        setcookie('evtic_pendiente', $valor, [
            'expires'  => time() + 900,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['evtic_pendiente'] = $valor;
    }

    private function correoPendiente(): ?array
    {
        $crudo = $_COOKIE['evtic_pendiente'] ?? '';
        if (!is_string($crudo) || $crudo === '') {
            return null;
        }
        $decodificado = base64_decode($crudo, true);
        if ($decodificado === false || !str_contains($decodificado, '|')) {
            return null;
        }
        [$firma, $carga] = explode('|', $decodificado, 2);
        if (!hash_equals(hash_hmac('sha256', $carga, $this->llave()), $firma)) {
            return null;
        }
        $datos = json_decode($carga, true);
        if (!is_array($datos) || empty($datos['c'])) {
            return null;
        }
        return [
            'correo'  => (string) $datos['c'],
            'destino' => (string) ($datos['d'] ?? '/carnet'),
            'metodo'  => (string) ($datos['m'] ?? 'correo'),
        ];
    }

    private function olvidarCorreoPendiente(): void
    {
        $peticion = App::peticion();
        setcookie('evtic_pendiente', '', [
            'expires'  => time() - 3600,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function llave(): string
    {
        return (string) Config::obtener('llave_cifrado', 'sin-instalar');
    }

    /* =====================================================================
       Equipo organizador
       ===================================================================== */

    public function equipo(Peticion $peticion): void
    {
        $destino = Url::destinoSeguro($peticion->query('destino') ?: $peticion->campo('destino'), '/admin');

        $actual = \App\Nucleo\Guardia::usuarioActual();
        if ($actual !== null && empty(Sesion::datos('admin')['pendiente_2fa'])) {
            Respuesta::redirigir($destino);
        }

        $errores = [];
        $correo = $peticion->campo('correo');

        if ($peticion->esPost()) {
            $clave = $peticion->campoCrudo('clave');
            $ip = $peticion->ip();

            Limite::exigir('acceso_admin_ip', $ip);
            $restantes = Limite::exigir('acceso_admin', $correo !== '' ? $correo : $ip);

            $usuario = $correo !== '' ? Usuario::porCorreo($correo) : null;

            if ($usuario === null) {
                // Se verifica igual contra un hash de descarte. Sin esto, un
                // correo inexistente responde en microsegundos y uno real tarda
                // lo que cuesta Argon2id: esa diferencia basta para averiguar
                // qué cuentas existen aunque el mensaje de error sea el mismo.
                Cripto::verificarClave($clave, Usuario::HASH_DESCARTE);
                $correcto = false;
            } else {
                $correcto = $usuario['estado'] === 'activo'
                    && Usuario::verificarClave($usuario, $clave);
            }

            if (!$correcto) {
                Limite::registrarFallo('acceso_admin', $correo !== '' ? $correo : $ip);
                Limite::registrarFallo('acceso_admin_ip', $ip);
                Bitacora::registrar('acceso_fallido', 'seguridad', null, ['correo_dado' => $correo !== '']);

                // Mensaje único: no se distingue entre correo inexistente,
                // contraseña mala y cuenta suspendida. Cualquier diferencia
                // sirve para averiguar qué cuentas existen.
                $errores['general'] = 'Correo o contraseña incorrectos.'
                    . ($restantes <= 2 && $restantes > 0
                        ? ' Te quedan ' . $restantes . ' intentos antes del bloqueo temporal.'
                        : '');
            } else {
                Limite::limpiar('acceso_admin', $correo);
                Sesion::abrir('admin', (int) $usuario['id'], [
                    'pendiente_2fa' => Usuario::tieneSegundoFactor($usuario),
                    'destino'       => $destino,
                ]);

                if (Usuario::tieneSegundoFactor($usuario)) {
                    Respuesta::redirigir('/admin/verificar');
                }

                // Administrador sin segundo factor configurado: se le obliga a
                // activarlo antes de dejarlo entrar al panel.
                if (Usuario::exigeSegundoFactor($usuario) && Config::obtener('exigir_2fa_admin', true)) {
                    Respuesta::redirigir('/admin/activar-2fa');
                }

                Usuario::registrarAcceso((int) $usuario['id']);
                Bitacora::registrar('acceso_correcto', 'usuario', (int) $usuario['id']);
                Respuesta::redirigir($destino, 'Sesión iniciada.');
            }
        }

        // Sin ninguna cuenta administradora, el formulario rechazaría cualquier
        // intento con «correo o contraseña incorrectos» y nadie entendería por
        // qué. Se dice lo que pasa: no es un secreto que valga la pena guardar
        // —el sitio está visiblemente roto— y sin decirlo no hay salida.
        Respuesta::vista('admin/entrar', [
            'titulo'       => 'Acceso administrativo',
            'correo'       => $correo,
            'destino'      => $destino,
            'errores'      => $errores,
            'sinCuentas'   => !\App\Nucleo\Instalacion::hayAdministrador(),
            'sinPlantilla' => true,
        ]);
    }

    public function verificarSegundoFactor(Peticion $peticion): void
    {
        $sesion = Sesion::actual('admin');
        if (!$sesion) {
            Respuesta::redirigir('/admin/entrar');
        }
        $usuario = Usuario::porId((int) $sesion['sujeto_id']);
        if (!$usuario) {
            Sesion::cerrar('admin');
            Respuesta::redirigir('/admin/entrar');
        }

        $datos = Sesion::datos('admin');
        if (empty($datos['pendiente_2fa'])) {
            Respuesta::redirigir(Url::destinoSeguro((string) ($datos['destino'] ?? '/admin'), '/admin'));
        }

        $errores = [];
        if ($peticion->esPost()) {
            Limite::exigir('acceso_admin', 'totp:' . $usuario['correo']);
            $codigo = $peticion->campo('codigo');

            if (Usuario::consumirTotp($usuario, $codigo)) {
                Limite::limpiar('acceso_admin', 'totp:' . $usuario['correo']);
                // Rotar tras superar el segundo factor: la sesión que existía
                // antes de completar la identificación no debe seguir sirviendo.
                Sesion::rotar('admin');
                Sesion::guardarDatos('admin', ['pendiente_2fa' => false, 'destino' => $datos['destino'] ?? '/admin']);

                Usuario::registrarAcceso((int) $usuario['id']);
                Bitacora::registrar('acceso_correcto', 'usuario', (int) $usuario['id'], ['con_2fa' => true]);
                Respuesta::redirigir(Url::destinoSeguro((string) ($datos['destino'] ?? '/admin'), '/admin'), 'Sesión iniciada.');
            }

            Limite::registrarFallo('acceso_admin', 'totp:' . $usuario['correo']);
            Bitacora::registrar('acceso_fallido', 'seguridad', (int) $usuario['id'], ['paso' => '2fa']);
            $errores['codigo'] = 'El código no coincide. Revisa que el reloj del teléfono esté en hora.';
        }

        Respuesta::vista('admin/verificar', [
            'titulo'       => 'Verificación en dos pasos',
            'errores'      => $errores,
            'sinPlantilla' => true,
        ]);
    }

    /** Alta del segundo factor: se muestra el QR y se confirma con un código. */
    public function activarSegundoFactor(Peticion $peticion): void
    {
        $sesion = Sesion::actual('admin');
        if (!$sesion) {
            Respuesta::redirigir('/admin/entrar');
        }
        $usuario = Usuario::porId((int) $sesion['sujeto_id']);
        if (!$usuario) {
            Sesion::cerrar('admin');
            Respuesta::redirigir('/admin/entrar');
        }
        if (Usuario::tieneSegundoFactor($usuario)) {
            Respuesta::redirigir('/admin');
        }

        $datos = Sesion::datos('admin');
        $errores = [];

        // El secreto se genera una vez y se conserva mientras dura el alta: si
        // se generara en cada carga, el código de la aplicación nunca cuadraría.
        $secreto = Usuario::secretoTotp($usuario);
        if ($secreto === '') {
            $secreto = Totp::generarSecreto();
            Usuario::guardarSecretoTotp((int) $usuario['id'], $secreto);
        }

        if ($peticion->esPost()) {
            if (Totp::verificar($secreto, $peticion->campo('codigo'))) {
                Usuario::confirmarTotp((int) $usuario['id']);
                Sesion::rotar('admin');
                Sesion::guardarDatos('admin', ['pendiente_2fa' => false, 'destino' => $datos['destino'] ?? '/admin']);
                Usuario::registrarAcceso((int) $usuario['id']);
                Bitacora::registrar('segundo_factor_activado', 'usuario', (int) $usuario['id']);
                // Se vuelve a donde iba. Un operador que escanea un carnet en
                // la puerta y se topa con el alta del segundo factor terminaba
                // en el panel, teniendo que volver a escanear con la fila
                // esperando.
                Respuesta::redirigir(
                    Url::destinoSeguro((string) ($datos['destino'] ?? '/admin'), '/admin'),
                    'Segundo factor activado.'
                );
            }
            $errores['codigo'] = 'El código no coincide. Vuelve a intentarlo con el siguiente que muestre la aplicación.';
        }

        $evento = App::eventoActivo();
        $uri = Totp::uri(
            $secreto,
            (string) $usuario['correo'],
            (string) ($evento['nombre'] ?? 'Eventos TIC Nariño')
        );

        Respuesta::vista('admin/activar-2fa', [
            'titulo'       => 'Activar la verificación en dos pasos',
            'secreto'      => Totp::formatear($secreto),
            'qr'           => Qr::svg($uri, ['nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                                             'titulo' => 'Código para la aplicación de autenticación']),
            'errores'      => $errores,
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Cambiar la propia contraseña.
     *
     * Faltaba. Quien crea una cuenta del equipo le pone una contraseña y la
     * marca como «debe cambiarla», pero no existía ninguna pantalla para
     * hacerlo: la persona se quedaba para siempre con la clave que otro le
     * escribió y que probablemente le pasó por chat.
     *
     * Se pide la actual además de la nueva. Sin eso, un equipo dejado con la
     * sesión abierta en el puesto de acreditación es una cuenta regalada.
     */
    public function cambiarClave(Peticion $peticion): void
    {
        $usuario = \App\Nucleo\Guardia::usuarioActual();
        if ($usuario === null) {
            Respuesta::redirigir('/admin/entrar');
        }

        $errores = [];
        if ($peticion->esPost()) {
            $actual = $peticion->campoCrudo('actual');
            $nueva = $peticion->campoCrudo('nueva');
            $repetida = $peticion->campoCrudo('nueva2');

            Limite::exigir('acceso_admin', 'clave:' . $usuario['correo']);

            if (!Usuario::verificarClave($usuario, $actual)) {
                Limite::registrarFallo('acceso_admin', 'clave:' . $usuario['correo']);
                $errores['actual'] = 'Esa no es tu contraseña actual.';
            } elseif (mb_strlen($nueva) < 12) {
                $errores['nueva'] = 'La contraseña nueva debe tener al menos 12 caracteres.';
            } elseif ($nueva !== $repetida) {
                $errores['nueva2'] = 'Las dos contraseñas deben coincidir.';
            } elseif ($nueva === $actual) {
                $errores['nueva'] = 'La contraseña nueva tiene que ser distinta de la anterior.';
            } else {
                // cambiarClave() cierra las demás sesiones de esa cuenta, así
                // que hay que volver a abrir la de aquí para no echar de la
                // plataforma a quien acaba de hacer lo correcto.
                Usuario::cambiarClave((int) $usuario['id'], $nueva);
                Limite::limpiar('acceso_admin', 'clave:' . $usuario['correo']);
                Sesion::abrir('admin', (int) $usuario['id'], ['pendiente_2fa' => false]);
                Bitacora::registrar('clave_cambiada', 'usuario', (int) $usuario['id']);
                Respuesta::redirigir('/admin', 'Contraseña cambiada.');
            }
        }

        Respuesta::vista('admin/clave', [
            'titulo'       => 'Cambiar mi contraseña',
            'pantalla'     => '',
            'debeCambiar'  => (int) $usuario['debe_cambiar'] === 1,
            'errores'      => $errores,
            'sinPlantilla' => true,
        ]);
    }

    public function salirEquipo(Peticion $peticion): void
    {
        $usuario = \App\Nucleo\Guardia::usuarioActual();
        if ($usuario) {
            Bitacora::registrar('acceso_cerrado', 'usuario', (int) $usuario['id']);
        }
        Sesion::cerrar('admin');
        Respuesta::redirigir('/admin/entrar', 'Cerraste sesión.');
    }
}
