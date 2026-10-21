<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Persona;
use App\Modelos\Recuperacion;
use App\Modelos\Usuario;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Dispositivo;
use App\Nucleo\Guardia;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Url;

/**
 * «Olvidé mi contraseña», para las dos puertas.
 *
 * Hasta la 3.12 no existía. El asistente que la olvidaba podía entrar con un
 * código al correo o con su QR, si la organización los tenía encendidos; con
 * solo la contraseña, se quedaba fuera. Y la cuenta del equipo dependía de
 * que otra persona administradora le pusiera una nueva o de alguien con
 * acceso al servidor.
 *
 * Son dos pasos: pedir el enlace —con el correo— y abrirlo para escribir la
 * contraseña nueva. Lo que hace seguro cada uno está en App\Modelos\Recuperacion;
 * aquí, sobre todo, lo que se le dice a la persona: nunca si un correo está
 * registrado o no, y siempre qué hacer si el enlace ya no sirve.
 */
final class Recuperar
{
    /* =====================================================================
       Asistente
       ===================================================================== */

    public function pedirAsistente(Peticion $peticion): void
    {
        if (Guardia::personaActual() !== null) {
            Respuesta::redirigir('/registro',
                'Ya estás dentro: tu contraseña la cambias aquí, en tus datos.');
        }
        // Sin el acceso por contraseña encendido no hay contraseña que
        // recuperar: se entra con un código al correo o con el QR.
        if (!Autenticacion::activo('clave')) {
            Respuesta::redirigir('/entrar',
                'Para entrar no hace falta contraseña: te enviamos un código a tu correo.', 'warn');
        }

        $correo = mb_strtolower($peticion->campo('correo'));
        $errores = [];

        if ($peticion->esPost()) {
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores['correo'] = 'Escribe un correo válido, por ejemplo nombre@entidad.gov.co';
            } else {
                $this->contarPedido($peticion, 'asistente:' . $correo);

                $evento = App::eventoActivo();
                $persona = $evento ? Persona::porCorreo((int) $evento['id'], $correo) : null;
                if ($persona !== null) {
                    Bitacora::registrar('enlace_clave_pedido', 'persona', (int) $persona['id']);
                    Respuesta::despues(static fn() => Recuperacion::enviar('asistente', $persona));
                }

                // La misma respuesta exista o no la persona.
                Respuesta::redirigir('/entrar/recuperar?enviado=1');
            }
        }

        Respuesta::vista('publico/recuperar', [
            'titulo'   => 'Recuperar mi contraseña',
            'pantalla' => 'entrar',
            'correo'   => $correo,
            'errores'  => $errores,
            'enviado'  => $peticion->query('enviado') === '1' && !$peticion->esPost(),
            'minutos'  => intdiv(Recuperacion::SEGUNDOS_PROPIO, 60),
            'codigoActivo' => Autenticacion::activo('correo'),
        ]);
    }

    public function nuevaAsistente(Peticion $peticion, array $parametros): void
    {
        $token = (string) ($parametros['token'] ?? '');
        $enlace = $this->enlace($peticion, 'asistente', $token);
        $persona = $enlace !== null ? Persona::porId((int) $enlace['sujeto_id']) : null;
        $evento = App::eventoActivo();

        $problema = match (true) {
            $enlace === null || $persona === null => 'desconocido',
            $enlace['estado'] !== 'vigente'       => (string) $enlace['estado'],
            !Recuperacion::esDelCorreo($enlace, (string) $persona['correo']) => 'otro_correo',
            $evento === null || (int) $persona['evento_id'] !== (int) $evento['id'] => 'otro_evento',
            !Autenticacion::activo('clave')       => 'sin_clave',
            default                               => '',
        };
        if ($problema !== '') {
            $this->inservible('asistente', $problema, $peticion);
        }

        $minima = Autenticacion::claveMinima();
        $errores = [];

        if ($peticion->esPost()) {
            $clave = $peticion->campoCrudo('clave');

            if (mb_strlen($clave) < $minima) {
                $errores['clave'] = 'La contraseña debe tener al menos ' . $minima . ' caracteres.';
            } elseif (mb_strlen($clave) > 200) {
                $errores['clave'] = 'La contraseña es demasiado larga.';
            } elseif ($clave !== $peticion->campoCrudo('clave2')) {
                $errores['clave2'] = 'Las dos contraseñas no coinciden.';
            } else {
                // Gastar el enlace y cambiar la contraseña van juntos: si dos
                // envíos llegan a la vez solo el primero sigue, y si algo falla
                // a medias el enlace no queda gastado sin contraseña nueva.
                $id = (int) $persona['id'];
                $hecho = Bd::transaccion(static function () use ($enlace, $id, $clave): bool {
                    if (!Recuperacion::gastar((int) $enlace['id'])) {
                        return false;
                    }
                    Persona::ponerClave($id, $clave);
                    Recuperacion::anularDe('asistente', $id);
                    return true;
                });
                if (!$hecho) {
                    $this->inservible('asistente', 'usado', $peticion);
                }

                // Quien recupera la contraseña puede estar haciéndolo porque
                // alguien más entró: se cierra todo lo que estaba abierto.
                Sesion::cerrarTodasDe('asistente', $id);
                Dispositivo::olvidarTodos($id);
                Limite::limpiar('codigo_correo', (string) $persona['correo']);

                // Abrir el enlace demostró que controla el buzón, que es lo
                // mismo que demuestra el código por correo: queda dentro.
                Sesion::limpiar();
                Sesion::abrir('asistente', $id);
                Bitacora::registrar('clave_recuperada', 'persona', $id, [
                    'origen' => $enlace['enviado_por'] !== null ? 'panel' : 'propio',
                ]);

                Respuesta::redirigir('/carnet', 'Listo: guardamos tu contraseña nueva y ya estás dentro.');
            }
        }

        $this->sinRastro();
        Respuesta::vista('publico/nueva-clave', [
            'titulo'       => 'Contraseña nueva',
            'pantalla'     => 'entrar',
            'accion'       => '/entrar/recuperar/' . $token,
            'correoOculto' => correoOculto((string) $persona['correo']),
            'errores'      => $errores,
            'claveMinima'  => $minima,
        ]);
    }

    /* =====================================================================
       Equipo organizador
       ===================================================================== */

    public function pedirEquipo(Peticion $peticion): void
    {
        // Con la sesión completa la contraseña se cambia en su pantalla, que
        // pide la actual. A medias —falta el segundo factor— sí se puede pedir.
        if (Guardia::usuarioActual() !== null && empty(Sesion::datos('admin')['pendiente_2fa'])) {
            Respuesta::redirigir('/admin/clave');
        }

        $correo = mb_strtolower($peticion->campo('correo'));
        $errores = [];

        if ($peticion->esPost()) {
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores['correo'] = 'Escribe un correo válido, por ejemplo nombre@narino.gov.co';
            } else {
                $this->contarPedido($peticion, 'admin:' . $correo);

                // Una cuenta suspendida no recibe nada, y la pantalla no lo dice:
                // sería contarle a cualquiera qué correos son del equipo.
                $usuario = Usuario::porCorreo($correo);
                if ($usuario !== null && $usuario['estado'] === 'activo') {
                    Bitacora::registrar('enlace_clave_pedido', 'usuario', (int) $usuario['id']);
                    Respuesta::despues(static fn() => Recuperacion::enviar('admin', $usuario));
                }

                Respuesta::redirigir('/admin/recuperar?enviado=1');
            }
        }

        Respuesta::vista('admin/recuperar', [
            'titulo'       => 'Recuperar mi contraseña',
            'correo'       => $correo,
            'errores'      => $errores,
            'enviado'      => $peticion->query('enviado') === '1' && !$peticion->esPost(),
            'minutos'      => intdiv(Recuperacion::SEGUNDOS_PROPIO, 60),
            'sinPlantilla' => true,
        ]);
    }

    public function nuevaEquipo(Peticion $peticion, array $parametros): void
    {
        $token = (string) ($parametros['token'] ?? '');
        $enlace = $this->enlace($peticion, 'admin', $token);
        $usuario = $enlace !== null ? Usuario::porId((int) $enlace['sujeto_id']) : null;

        $problema = match (true) {
            $enlace === null || $usuario === null => 'desconocido',
            $enlace['estado'] !== 'vigente'       => (string) $enlace['estado'],
            !Recuperacion::esDelCorreo($enlace, (string) $usuario['correo']) => 'otro_correo',
            $usuario['estado'] !== 'activo'       => 'suspendida',
            default                               => '',
        };
        if ($problema !== '') {
            $this->inservible('admin', $problema, $peticion);
        }

        $errores = [];

        if ($peticion->esPost()) {
            $nueva = $peticion->campoCrudo('nueva');

            // Los mismos doce caracteres que al cambiarla desde el panel.
            if (mb_strlen($nueva) < 12) {
                $errores['nueva'] = 'La contraseña nueva debe tener al menos 12 caracteres.';
            } elseif (mb_strlen($nueva) > 200) {
                $errores['nueva'] = 'La contraseña es demasiado larga.';
            } elseif ($nueva !== $peticion->campoCrudo('nueva2')) {
                $errores['nueva2'] = 'Las dos contraseñas deben coincidir.';
            } else {
                $id = (int) $usuario['id'];
                $hecho = Bd::transaccion(static function () use ($enlace, $id, $nueva): bool {
                    if (!Recuperacion::gastar((int) $enlace['id'])) {
                        return false;
                    }
                    // Cierra todas sus sesiones y quita la marca de «debe
                    // cambiarla»: esta la eligió la propia persona.
                    Usuario::cambiarClave($id, $nueva);
                    Recuperacion::anularDe('admin', $id);
                    return true;
                });
                if (!$hecho) {
                    $this->inservible('admin', 'usado', $peticion);
                }

                // Si la cuenta quedó bloqueada por intentos fallidos, ese
                // bloqueo ya no protege nada: la contraseña es otra.
                Limite::limpiar('acceso_admin', (string) $usuario['correo']);
                Bitacora::registrar('clave_recuperada', 'usuario', $id, [
                    'origen' => $enlace['enviado_por'] !== null ? 'panel' : 'propio',
                ]);

                // No se abre sesión. El enlace demuestra que se controla el
                // buzón, no el teléfono: hay que entrar con la contraseña nueva
                // y pasar el segundo factor como siempre.
                Respuesta::redirigir('/admin/entrar', 'Listo: tu contraseña quedó cambiada. Entra con ella'
                    . (Usuario::tieneSegundoFactor($usuario)
                        ? '; después te pediremos el código de tu aplicación.'
                        : '.'));
            }
        }

        $this->sinRastro();
        Respuesta::vista('admin/nueva-clave', [
            'titulo'       => 'Contraseña nueva',
            'accion'       => '/admin/recuperar/' . $token,
            'correoOculto' => correoOculto((string) $usuario['correo']),
            'errores'      => $errores,
            'sinPlantilla' => true,
        ]);
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    /**
     * Los pedidos se cuentan todos, exista o no la cuenta. Primero el tope por
     * IP, que es el que frena a quien prueba correos a ciegas.
     */
    private function contarPedido(Peticion $peticion, string $clave): void
    {
        Limite::exigir('recuperar_clave_ip', $peticion->ip());
        Limite::exigir('recuperar_clave', $clave);
        Limite::registrar('recuperar_clave_ip', $peticion->ip());
        Limite::registrar('recuperar_clave', $clave);
    }

    /** El enlace del token, con el tope contra quien prueba enlaces inventados. */
    private function enlace(Peticion $peticion, string $tipo, string $token): ?array
    {
        Limite::exigir('enlace_clave', $peticion->ip());
        try {
            return Recuperacion::buscar($tipo, $token);
        } catch (\Throwable $e) {
            // La tabla todavía no existe: la base no se ha puesto al día.
            \App\Nucleo\Registro::excepcion($e);
            return null;
        }
    }

    /**
     * La página tiene el enlace en la dirección: que no salga en la cabecera
     * Referer hacia ningún sitio, ni quede guardada en ninguna caché.
     */
    private function sinRastro(): void
    {
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }

    /** Explica por qué el enlace no sirve y ofrece el camino que sí. */
    private function inservible(string $tipo, string $motivo, Peticion $peticion): never
    {
        if ($motivo === 'desconocido') {
            Limite::registrarFallo('enlace_clave', $peticion->ip());
        }

        $equipo = $tipo === 'admin';
        $pedirOtro = ['texto' => 'Pedir un enlace nuevo',
                      'url' => Url::a($equipo ? '/admin/recuperar' : '/entrar/recuperar'), 'principal' => true];
        $alAcceso = ['texto' => 'Ir al acceso', 'url' => Url::a($equipo ? '/admin/entrar' : '/entrar')];

        $this->sinRastro();
        [$codigo, $titulo, $mensaje, $acciones] = match ($motivo) {
            'usado' => [410, 'Este enlace ya se usó',
                'Con él ya se puso una contraseña nueva. Si fuiste tú, entra con ella. Si no fuiste tú, '
                . 'pide otro enlace ahora mismo y avisa a la organización del evento.',
                [array_merge($alAcceso, ['principal' => true]), array_diff_key($pedirOtro, ['principal' => 1])]],
            'vencido' => [410, 'Este enlace venció',
                'Pasó su plazo o se pidió otro después. Duran una hora —un día si te lo envió la '
                . 'administración del evento— y cada enlace nuevo deja sin efecto los anteriores. '
                . 'Pide otro y usa el más reciente.',
                [$pedirOtro, $alAcceso]],
            'otro_correo' => [410, 'Este enlace ya no sirve',
                'La cuenta cambió de correo después de que se envió. Pide uno nuevo: llegará al correo actual.',
                [$pedirOtro, $alAcceso]],
            'otro_evento' => [410, 'Este enlace es de otro evento',
                'Se pidió para un evento que ya no es el que está en curso. Si te registraste en este, '
                . 'pide un enlace nuevo con el mismo correo.',
                [$pedirOtro, $alAcceso]],
            'sin_clave' => [409, 'Ya no hace falta contraseña',
                'La organización cambió la forma de entrar: ahora te enviamos un código a tu correo, '
                . 'sin contraseña.',
                [['texto' => 'Entrar con mi correo', 'url' => Url::a('/entrar'), 'principal' => true]]],
            'suspendida' => [403, 'Esta cuenta está suspendida',
                'No se le puede cambiar la contraseña mientras esté suspendida. Habla con quien '
                . 'administra el evento.',
                [array_merge($alAcceso, ['principal' => true])]],
            default => [404, 'Este enlace no sirve',
                'No corresponde a ningún pedido. Revisa que lo copiaste completo o pide uno nuevo.',
                [$pedirOtro, $alAcceso]],
        };

        Respuesta::error($codigo, $titulo, $mensaje, $acciones);
    }
}
