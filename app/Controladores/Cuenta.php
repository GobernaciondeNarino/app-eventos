<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Usuario;
use App\Nucleo\App;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;
use App\Nucleo\Guardia;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Totp;

/**
 * «Configuración»: la cuenta propia de cada persona del equipo.
 *
 * Sobre todo, restablecer la verificación en dos pasos sin depender de nadie:
 * se cambió de teléfono, se borró la aplicación, o se entró con un código por
 * correo porque la aplicación no servía. Hasta la 3.5 la única salida era
 * pedirle a quien tiene acceso al servidor que corriera una orden por consola.
 *
 * Dos cuidados:
 *
 *   · Se pide la contraseña actual. Un equipo con la sesión abierta en el
 *     puesto de acreditación no puede servir para cambiar el segundo factor.
 *
 *   · La aplicación anterior sigue valiendo hasta que la nueva se confirma con
 *     un código. Si el proceso se abandona a la mitad —se cierra la pestaña,
 *     se acaba la batería—, la cuenta queda exactamente como estaba.
 */
final class Cuenta
{
    /** Cuánto dura un código QR nuevo sin confirmar: quince minutos. */
    private const VIDA_ALTA = 900;

    public function ver(Peticion $peticion): void
    {
        $usuario = Guardia::usuarioActual();
        $datos = Sesion::datos('admin');
        $errores = [];
        $avisos = [];

        if ($peticion->esPost()) {
            $accion = $peticion->campo('accion');

            if ($accion === 'cancelar') {
                unset($datos['totp_nuevo'], $datos['totp_resincronizar']);
                Sesion::guardarDatos('admin', $datos);
                Respuesta::redirigir('/admin/cuenta', 'La verificación en dos pasos quedó como estaba.');
            }

            if ($accion === 'nuevo') {
                Limite::exigir('acceso_admin', 'clave:' . $usuario['correo']);
                if (!Usuario::verificarClave($usuario, $peticion->campoCrudo('clave'))) {
                    Limite::registrarFallo('acceso_admin', 'clave:' . $usuario['correo']);
                    $errores['clave'] = 'Esa no es tu contraseña actual.';
                } else {
                    Limite::limpiar('acceso_admin', 'clave:' . $usuario['correo']);
                    // El secreto nuevo espera en la sesión, cifrado, hasta que
                    // se confirme. En la cuenta no se toca nada todavía.
                    $datos['totp_nuevo'] = [
                        'secreto' => base64_encode(Cripto::cifrar(Totp::generarSecreto())),
                        'hasta'   => time() + self::VIDA_ALTA,
                    ];
                    unset($datos['totp_resincronizar']);
                    Sesion::guardarDatos('admin', $datos);
                    Respuesta::redirigir('/admin/cuenta');
                }
            }

            if ($accion === 'confirmar') {
                $secreto = $this->secretoNuevo($datos);
                if ($secreto === null) {
                    unset($datos['totp_nuevo'], $datos['totp_resincronizar']);
                    Sesion::guardarDatos('admin', $datos);
                    Respuesta::redirigir('/admin/cuenta',
                        'El código QR nuevo venció sin confirmarse. Genera otro.', 'warn');
                }

                Limite::exigir('acceso_admin', 'totp:' . $usuario['correo']);
                $resultado = Totp::evaluar(
                    $secreto,
                    $peticion->campo('codigo'),
                    (int) ($usuario['totp_deriva'] ?? 0),
                    0,
                    $this->resincronizacion($datos)
                );

                if ($resultado['estado'] === 'ok') {
                    Limite::limpiar('acceso_admin', 'totp:' . $usuario['correo']);
                    $habia = Usuario::estadoSegundoFactor($usuario);
                    Usuario::activarSecretoTotp((int) $usuario['id'], $secreto,
                        $resultado['intervalo'], $resultado['deriva']);

                    // Las otras sesiones de esta cuenta se cierran: si el cambio
                    // es porque se perdió un teléfono, ese teléfono no debería
                    // seguir dentro. La de aquí se abre otra vez, ya completa.
                    Sesion::cerrarTodasDe('admin', (int) $usuario['id']);
                    Sesion::abrir('admin', (int) $usuario['id'], ['pendiente_2fa' => false]);
                    Bitacora::registrar(
                        $habia === 'ninguno' ? 'segundo_factor_activado' : 'segundo_factor_restablecido',
                        'usuario',
                        (int) $usuario['id']
                    );
                    Respuesta::redirigir('/admin/cuenta', $habia === 'ninguno'
                        ? 'Verificación en dos pasos activada.'
                        : 'Listo: desde ahora vale el código de la aplicación nueva. Borra de la aplicación '
                          . 'la entrada anterior de esta cuenta.');
                }

                Limite::registrarFallo('acceso_admin', 'totp:' . $usuario['correo']);
                if ($resultado['estado'] === 'confirmar') {
                    $datos['totp_resincronizar'] = [
                        'intervalo' => $resultado['intervalo'],
                        'deriva'    => $resultado['deriva'],
                        'hasta'     => time() + Totp::PLAZO_CONFIRMAR,
                        'retroceso' => !empty($resultado['retroceso']),
                    ];
                    Sesion::guardarDatos('admin', $datos);
                    [$cuanto, $hacia] = Totp::describirDeriva($resultado['deriva']);
                    $avisos[] = 'El código es correcto, pero la hora de este servidor no coincide con la de '
                        . 'tu teléfono: va ' . $cuanto . ' ' . $hacia . '. Para confirmarlo, espera el código '
                        . 'siguiente de la aplicación y escríbelo.';
                } else {
                    $errores['codigo'] = 'El código no coincide. Revisa que escaneaste el código de esta '
                        . 'pantalla y escribe el que muestra ahora la aplicación.';
                }
            }
        }

        $nuevo = $this->secretoNuevo($datos);
        $qr = null;
        if ($nuevo !== null) {
            $evento = App::eventoActivo();
            $qr = Qr::svg(
                Totp::uri($nuevo, (string) $usuario['correo'], (string) ($evento['nombre'] ?? 'Eventos TIC Nariño')),
                ['nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                 'titulo' => 'Código para la aplicación de autenticación']
            );
        }

        Respuesta::vista('admin/cuenta', [
            'titulo'       => 'Configuración',
            'pantalla'     => 'configuracion',
            'cuenta'       => $usuario,
            'estado2fa'    => Usuario::estadoSegundoFactor($usuario),
            'obligatorio'  => Usuario::exigeSegundoFactor($usuario)
                              && (bool) \App\Nucleo\Config::obtener('exigir_2fa_admin', true),
            'qr'           => $qr,
            'secreto'      => $nuevo !== null ? Totp::formatear($nuevo) : '',
            'confirmando'  => $avisos !== [] || $this->resincronizacion($datos) !== null,
            'errores'      => $errores,
            'avisos'       => $avisos,
            'horaServidor' => time(),
        ]);
    }

    /** El secreto que se está dando de alta, si hay uno y no venció. */
    private function secretoNuevo(array $datos): ?string
    {
        $nuevo = $datos['totp_nuevo'] ?? null;
        if (!is_array($nuevo) || (int) ($nuevo['hasta'] ?? 0) < time()) {
            return null;
        }
        try {
            $secreto = Cripto::descifrar((string) base64_decode((string) ($nuevo['secreto'] ?? ''), true));
        } catch (\Throwable) {
            return null;
        }
        return $secreto !== '' ? $secreto : null;
    }

    /** El primer código de una resincronización en curso, si no venció. */
    private function resincronizacion(array $datos): ?array
    {
        $pendiente = $datos['totp_resincronizar'] ?? null;
        if (!is_array($pendiente) || (int) ($pendiente['hasta'] ?? 0) < time()) {
            return null;
        }
        return [
            'intervalo' => (int) $pendiente['intervalo'],
            'deriva'    => (int) $pendiente['deriva'],
            'hasta'     => (int) $pendiente['hasta'],
            'retroceso' => !empty($pendiente['retroceso']),
        ];
    }
}
