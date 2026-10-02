<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Asistencia;
use App\Modelos\Credencial;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Bitacora;
use App\Nucleo\Dispositivo;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;

/**
 * El carnet digital de quien tiene la sesión abierta.
 *
 * Solo se muestra el propio: no hay ninguna ruta que permita ver el carnet
 * completo de otra persona. El equipo, al escanear, ve la ficha de
 * acreditación, que es otra cosa y queda registrada en la bitácora.
 *
 * En el carnet hay dos códigos y no uno, y la diferencia importa:
 *
 *   · el de contacto (/c/…) es el que se enseña. Escanearlo no identifica a
 *     nadie: intercambia datos, o acredita si quien mira es del equipo.
 *   · el de acceso (/entrar/qr/…) abre la sesión de su dueño en el teléfono que
 *     lo escanee. Es una llave, y así se rotula.
 */
final class Carnet
{
    public function ver(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $evento = App::eventoActivo();
        $credencial = Credencial::asegurar((int) $persona['id']);

        // El QR de acceso solo se pinta si ese método está encendido: si no,
        // escanearlo llevaría a un 403 y la persona no entendería por qué.
        $conAcceso = Autenticacion::activo('qr');
        $urlAcceso = $conAcceso ? Credencial::urlAcceso((int) $persona['id']) : '';

        Respuesta::vista('publico/carnet', [
            'titulo'     => 'Mi carnet',
            'pantalla'   => 'carnet',
            'credencial' => $credencial,
            'documento'  => Persona::documento($persona),
            'qr'         => Qr::svg(Credencial::urlQr($credencial), [
                // Nivel alto: el carnet se doblará, se rayará y se fotografiará
                // en la puerta con poca luz.
                'nivel'    => 'Q',
                'silencio' => 2,
                'oscuro'   => '#08151F',
                'clase'    => 'qr',
                'titulo'   => 'Código de mi credencial',
            ]),
            'contenidoQr' => Credencial::urlQr($credencial),
            'qrAcceso'    => $conAcceso ? Qr::svg($urlAcceso, [
                'nivel'    => 'Q',
                'silencio' => 2,
                'oscuro'   => '#08151F',
                'clase'    => 'qr',
                'titulo'   => 'Mi código de acceso',
            ]) : '',
            'urlAcceso'    => $urlAcceso,
            'historial'    => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
            'dispositivos' => Dispositivo::de((int) $persona['id']),
        ]);
    }

    /** Versión para imprimir: las dos caras, sin navegación. */
    public function imprimir(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $credencial = Credencial::asegurar((int) $persona['id']);

        Respuesta::vista('publico/carnet-imprimir', [
            'titulo'       => 'Carnet para imprimir',
            'credencial'   => $credencial,
            'documento'    => Persona::documento($persona),
            'qr'           => Qr::svg(Credencial::urlQr($credencial), [
                'nivel' => 'Q', 'silencio' => 2, 'clase' => 'qr',
                'titulo' => 'Código de la credencial',
            ]),
            'sinPlantilla' => true,
        ]);
    }

    /* =====================================================================
       Todos los carnets del evento
       -------------------------------------------------------------------------
       Para el equipo y para el Staff. Hace falta porque la gente llega sin
       teléfono, o con el teléfono sin batería, o prefiere el plástico: hasta
       ahora cada carnet había que imprimirlo desde la sesión de su dueño, lo
       que para doscientos asistentes era imposible.
       ===================================================================== */

    public function lista(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
            'limite' => 500,
        ];

        $personas = Persona::conCredencial((int) $evento['id'], $filtros);

        Bitacora::registrar('carnets_listados', 'evento', (int) $evento['id'], [
            'cuantos' => count($personas),
        ]);

        Respuesta::vista('admin/carnets', [
            'titulo'   => 'Carnets del evento',
            'pantalla' => 'carnets',
            'personas' => $personas,
            'filtros'  => $filtros,
            'total'    => (int) \App\Nucleo\Bd::valor(
                'SELECT COUNT(*) FROM {persona} WHERE evento_id = ?', [(int) $evento['id']]
            ),
        ]);
    }

    /**
     * Todos los carnets en una sola página, listos para imprimir.
     *
     * Una cara por persona, con el QR dentro. Las dos caras del carnet
     * individual tienen sentido para quien imprime el suyo y lo dobla; para una
     * tanda de doscientos, imprimir cuatrocientas caras y aparearlas a mano es
     * lo que hace que nadie use la función. Con el código en la misma cara, se
     * recorta y ya sirve.
     *
     * No se pagina aquí: el navegador reparte las tarjetas por hoja con
     * «break-inside: avoid», que es lo que hace que ninguna quede cortada.
     */
    public function imprimirTodos(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
            'limite' => 500,
        ];

        $personas = Persona::conCredencial((int) $evento['id'], $filtros);

        // Cada carnet necesita su credencial. Se asegura aquí y no en la vista
        // para que una persona sin carnet emitido —registro recién completado—
        // no salga con un hueco donde va el código.
        $tarjetas = [];
        foreach ($personas as $p) {
            $credencial = Credencial::asegurar((int) $p['id']);
            $tarjetas[] = [
                'persona'    => $p,
                'credencial' => $credencial,
                'documento'  => Persona::documento($p),
                'qr'         => Qr::svg(Credencial::urlQr($credencial), [
                    'nivel' => 'Q', 'silencio' => 2, 'clase' => 'qr',
                    'titulo' => 'Código de ' . $p['nombre'],
                ]),
            ];
        }

        Bitacora::registrar('carnets_impresos', 'evento', (int) $evento['id'], [
            'cuantos' => count($tarjetas),
        ]);

        Respuesta::vista('admin/carnets-imprimir', [
            'titulo'       => 'Carnets para imprimir',
            'tarjetas'     => $tarjetas,
            'filtros'      => $filtros,
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Cierra la sesión en todos los teléfonos recordados.
     *
     * Es el botón que hace falta cuando alguien pierde el teléfono: la marca de
     * dispositivo dura seis meses y sin esto no habría forma de retirarla.
     * Además se cambia el token del QR de acceso, porque quien tenga una foto
     * del carnet podría seguir entrando con él.
     */
    public function cerrarDispositivos(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $id = (int) $persona['id'];

        $cuantos = Dispositivo::olvidarTodos($id);
        Persona::regenerarTokenDeAcceso($id);
        \App\Nucleo\Sesion::cerrarTodasDe('asistente', $id);
        \App\Nucleo\Bitacora::registrar('dispositivos_cerrados', 'persona', $id, [
            'cuantos' => $cuantos,
        ]);

        Respuesta::redirigir('/entrar',
            'Se cerró la sesión en ' . $cuantos . ' dispositivo' . ($cuantos === 1 ? '' : 's')
            . ' y tu QR de acceso anterior dejó de servir.');
    }
}
