<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Formulario;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Imagen;
use App\Nucleo\Peticion;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Url;

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

    /** Dónde se configura cada formulario. */
    private const RUTAS = [
        Formulario::PUBLICO     => '/admin/configuracion/registro',
        Formulario::EXPOSITORES => '/admin/configuracion/expositores',
    ];

    /** El formulario de registro público: campos, listas y banner. */
    public function registro(Peticion $peticion): void
    {
        $this->editar($peticion, Formulario::PUBLICO);
    }

    /**
     * El formulario privado de expositores: lo mismo, con su propia
     * configuración, y además el enlace que se les envía.
     */
    public function expositores(Peticion $peticion): void
    {
        $this->editar($peticion, Formulario::EXPOSITORES);
    }

    /** Un enlace nuevo para el de expositores. El anterior deja de servir en el acto. */
    public function nuevoEnlace(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        Formulario::nuevoTokenExpositores((int) $evento['id']);
        Bitacora::registrar('enlace_expositores_regenerado', 'evento', (int) $evento['id']);
        Respuesta::redirigir(self::RUTAS[Formulario::EXPOSITORES],
            'Enlace nuevo listo. El anterior ya no abre el formulario: envía este a quienes falten por registrarse.', 'warn');
    }

    /**
     * Campos, listas y banner de uno de los dos formularios.
     *
     * Se guarda todo junto. Si una lista trae un error —una sigla de documento
     * con espacios, un municipio sin departamento—, esa lista se queda como
     * estaba y lo demás se guarda igual: perder el resto de los cambios por una
     * línea mal escrita sería castigar de más.
     */
    private function editar(Peticion $peticion, string $tipo): void
    {
        $ruta = self::RUTAS[$tipo];
        $deExpositores = $tipo === Formulario::EXPOSITORES;
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $errores = [];
        $escrito = [];

        // Cuántas personas tienen cada perfil: uno en uso no se elimina, se
        // apaga. La pantalla lo dice al lado de cada uno.
        $perfilesEnUso = [];
        foreach (Bd::filas('SELECT rol, COUNT(*) AS n FROM {persona} WHERE evento_id = ? GROUP BY rol', [$eventoId]) as $fila) {
            $perfilesEnUso[(string) $fila['rol']] = (int) $fila['n'];
        }

        if ($peticion->esPost()) {
            $usuarioId = (int) (Guardia::usuarioActual()['id'] ?? 0) ?: null;

            if ($peticion->campo('accion') === 'restablecer') {
                $conservados = Formulario::restablecer($eventoId, $usuarioId, $tipo);
                Bitacora::registrar('formulario_restablecido', 'evento', $eventoId,
                    ($deExpositores ? ['formulario' => $tipo] : [])
                    + ($conservados === [] ? [] : ['perfiles_conservados' => implode(', ', $conservados)]));
                Respuesta::redirigir($ruta,
                    'El formulario volvió a ser el de fábrica. '
                    . ($deExpositores ? 'El banner y el enlace se conservaron como estaban.' : 'El banner se conservó como estaba.')
                    . ($conservados === [] ? '' : ' También los perfiles ' . implode(', ', array_map(
                        static fn(string $n): string => '«' . $n . '»', $conservados
                    )) . ', porque hay personas que los tienen.'));
            }

            $antes = Formulario::delEvento($eventoId, $tipo)->paraEditar();

            $campos = [];
            foreach ($peticion->campoEstructurado('campos') as $clave => $estado) {
                if (is_string($clave) && is_string($estado)) {
                    $campos[$clave] = $estado;
                }
            }
            $escrito = $peticion->campoEstructurado('listas');
            [$listas, $errores] = Formulario::leerListas($escrito, $antes['listas'], $perfilesEnUso);
            Formulario::guardar($eventoId, $campos, $listas, $usuarioId, $tipo);

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
            Formulario::guardarBanner($eventoId, $banner, $usuarioId, $tipo);
            // La imagen anterior se borra solo cuando la nueva ya quedó anotada.
            if (array_key_exists('imagen', $banner) && $anterior !== '' && $anterior !== $banner['imagen']) {
                Imagen::borrarBanner($anterior);
            }

            $guardado = Formulario::delEvento($eventoId, $tipo);
            Bitacora::registrar('formulario_guardado', 'evento', $eventoId, ($deExpositores ? ['formulario' => $tipo] : []) + [
                'ocultos'      => implode(', ', array_keys(array_filter(
                    Formulario::CAMPOS, static fn(array $d, string $c): bool => !$guardado->visible($c), ARRAY_FILTER_USE_BOTH
                ))),
                'obligatorios' => implode(', ', array_keys(array_filter(
                    Formulario::CAMPOS, static fn(array $d, string $c): bool => $guardado->obligatorio($c), ARRAY_FILTER_USE_BOTH
                ))),
                'banner'       => $guardado->banner() !== null,
            ]);

            if ($errores === []) {
                Respuesta::redirigir($ruta, $deExpositores
                    ? 'Formulario guardado. Así lo ve desde ahora quien entra por el enlace de expositores.'
                    : 'Formulario guardado. Así lo ve desde ahora quien se registra.');
            }
        }

        $formulario = Formulario::delEvento($eventoId, $tipo);
        Respuesta::vista('admin/config-registro', [
            'titulo'     => $deExpositores ? 'Configuración del registro de expositores' : 'Configuración del registro',
            'pantalla'   => 'configuracion',
            'pestana'    => $deExpositores ? 'expositores' : 'registro',
            'tipo'       => $tipo,
            'accionForm' => $ruta,
            // El enlace privado: se crea la primera vez que se abre esta pestaña.
            'enlace'     => $deExpositores
                ? Url::absoluta('/registro/expositores/' . Formulario::tokenExpositores($eventoId)) : '',
            'formulario' => $formulario,
            'edicion'    => $formulario->paraEditar(),
            'perfilesEnUso' => $perfilesEnUso,
            'errores'    => $errores,
            // Lo que se escribió en una lista que no se pudo guardar, para
            // corregirlo sin tener que volver a escribirlo entero.
            'escrito'    => $escrito,
        ]);
    }
}
