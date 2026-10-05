<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Formulario;
use App\Nucleo\App;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Imagen;
use App\Nucleo\Peticion;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;

/**
 * El módulo Configuración: todo lo que se ajusta una vez y vale para el evento.
 *
 * Reúne en pestañas lo que antes eran entradas sueltas del menú —Identidad,
 * Autenticación, la cuenta propia— y lo nuevo de la 3.7: cómo es el formulario
 * de registro. Cada pestaña sigue siendo su propia pantalla con su propio
 * botón de guardar; las direcciones de antes siguen funcionando.
 */
final class Configuracion
{
    /** La primera pestaña que le toca a cada quien. */
    public function inicio(Peticion $peticion): void
    {
        Respuesta::redirigir(Guardia::puede('administrador') ? '/admin/configuracion/registro' : '/admin/cuenta');
    }

    /**
     * El formulario de registro: campos, listas y banner.
     *
     * Se guarda todo junto. Si una lista trae un error —una sigla de documento
     * con espacios, un municipio sin departamento—, esa lista se queda como
     * estaba y lo demás se guarda igual: perder el resto de los cambios por una
     * línea mal escrita sería castigar de más.
     */
    public function registro(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $errores = [];
        $escrito = [];

        if ($peticion->esPost()) {
            $usuarioId = (int) (Guardia::usuarioActual()['id'] ?? 0) ?: null;

            if ($peticion->campo('accion') === 'restablecer') {
                Formulario::restablecer($eventoId, $usuarioId);
                Bitacora::registrar('formulario_restablecido', 'evento', $eventoId);
                Respuesta::redirigir('/admin/configuracion/registro',
                    'El formulario volvió a ser el de fábrica. El banner se conservó como estaba.');
            }

            $antes = Formulario::delEvento($eventoId)->paraEditar();

            $campos = [];
            foreach ($peticion->campoEstructurado('campos') as $clave => $estado) {
                if (is_string($clave) && is_string($estado)) {
                    $campos[$clave] = $estado;
                }
            }
            $escrito = $peticion->campoEstructurado('listas');
            [$listas, $errores] = Formulario::leerListas($escrito, $antes['listas']);
            Formulario::guardar($eventoId, $campos, $listas, $usuarioId);

            $banner = [
                'activo' => $peticion->marcado('banner_activo'),
                'titulo' => $peticion->campo('banner_titulo'),
                'texto'  => $peticion->campo('banner_texto'),
                'alt'    => $peticion->campo('banner_alt'),
            ];
            $anterior = (string) $antes['banner']['imagen'];
            $archivo = $peticion->archivo('banner_imagen');
            if ($archivo !== null) {
                try {
                    [$banner['imagen'], $banner['tipo']] = Imagen::guardarBanner($archivo, $eventoId);
                } catch (\DomainException $e) {
                    $errores['banner_imagen'] = $e->getMessage();
                } catch (\Throwable $e) {
                    Registro::excepcion($e);
                    $errores['banner_imagen'] = 'No se pudo procesar la imagen en el servidor.';
                }
            } elseif ($peticion->marcado('quitar_banner')) {
                $banner['imagen'] = '';
                $banner['tipo'] = '';
            }
            Formulario::guardarBanner($eventoId, $banner, $usuarioId);
            // La imagen anterior se borra solo cuando la nueva ya quedó anotada.
            if (array_key_exists('imagen', $banner) && $anterior !== '' && $anterior !== $banner['imagen']) {
                Imagen::borrarBanner($anterior);
            }

            $guardado = Formulario::delEvento($eventoId);
            Bitacora::registrar('formulario_guardado', 'evento', $eventoId, [
                'ocultos'      => implode(', ', array_keys(array_filter(
                    Formulario::CAMPOS, static fn(array $d, string $c): bool => !$guardado->visible($c), ARRAY_FILTER_USE_BOTH
                ))),
                'obligatorios' => implode(', ', array_keys(array_filter(
                    Formulario::CAMPOS, static fn(array $d, string $c): bool => $guardado->obligatorio($c), ARRAY_FILTER_USE_BOTH
                ))),
                'banner'       => $guardado->banner() !== null,
            ]);

            if ($errores === []) {
                Respuesta::redirigir('/admin/configuracion/registro',
                    'Formulario guardado. Así lo ve desde ahora quien se registra.');
            }
        }

        $formulario = Formulario::delEvento($eventoId);
        Respuesta::vista('admin/config-registro', [
            'titulo'     => 'Configuración del registro',
            'pantalla'   => 'configuracion',
            'formulario' => $formulario,
            'edicion'    => $formulario->paraEditar(),
            'errores'    => $errores,
            // Lo que se escribió en una lista que no se pudo guardar, para
            // corregirlo sin tener que volver a escribirlo entero.
            'escrito'    => $escrito,
        ]);
    }
}
