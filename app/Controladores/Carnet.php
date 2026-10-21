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
use App\Nucleo\Paginacion;
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
            // Para quien expone: cómo va su propuesta, con el camino a «Mi
            // exposición». [] es que todavía no la ha enviado.
            'exposicion'   => Persona::esExpositor($persona)
                ? (Persona::suPropuesta((int) $persona['id']) ?? []) : null,
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

    /** Cuántos carnets salen de una vez al imprimir. Ver imprimirTodos(). */
    public const TANDA = 300;

    public function lista(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
        ];

        $conCarnet = Persona::contar($eventoId, ['completos' => true] + $filtros);
        $paginacion = Paginacion::desde($peticion, $conCarnet);
        $orden = Persona::ordenElegido($peticion, $eventoId);
        $personas = Persona::conCredencial($eventoId, $filtros + [
            'limite' => $paginacion->porPagina,
            'desde'  => $paginacion->desplazamiento(),
        ], $orden);

        Bitacora::registrar('carnets_listados', 'evento', $eventoId, [
            'cuantos' => $conCarnet,
        ]);

        $total = Persona::contar($eventoId);
        Respuesta::vista('admin/carnets', [
            'titulo'     => 'Carnets del evento',
            'pantalla'   => 'carnets',
            'personas'   => $personas,
            'filtros'    => $filtros,
            'conCarnet'  => $conCarnet,
            'paginacion' => $paginacion,
            'orden'      => $orden,
            'consulta'   => array_filter(['q' => $filtros['texto'], 'rol' => $filtros['rol']]),
            'total'      => $total,
            // Los del evento entero que no tienen carnet, filtre lo que filtre:
            // el aviso de abajo habla de ellos.
            'sinCarnet'  => $total - Persona::contar($eventoId, ['completos' => true]),
            'tanda'      => self::TANDA,
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
     * El reparto por hoja lo hace el navegador con «break-inside: avoid», que
     * es lo que hace que ninguna quede cortada. Lo que sí se parte es la
     * impresión, en tandas de TANDA carnets. Cada uno lleva su código QR, que
     * se dibuja en el servidor, y con miles de una vez la página tardaría más
     * de lo que el servidor deja correr a PHP. Hasta la 3.12 se cortaba en
     * quinientos sin avisar; ahora se dice qué tanda es y cuántas faltan.
     */
    public function imprimirTodos(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
        ];

        $conCarnet = Persona::contar($eventoId, ['completos' => true] + $filtros);
        $tandas = max(1, (int) ceil($conCarnet / self::TANDA));
        $tanda = min(max(1, $peticion->entero('tanda', 1)), $tandas);
        $orden = Persona::ordenElegido($peticion, $eventoId);

        $personas = Persona::conCredencial($eventoId, $filtros + [
            'limite' => self::TANDA,
            'desde'  => ($tanda - 1) * self::TANDA,
        ], $orden);

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

        Bitacora::registrar('carnets_impresos', 'evento', $eventoId, [
            'cuantos' => count($tarjetas),
            'tanda'   => $tandas > 1 ? $tanda . ' de ' . $tandas : '',
        ]);

        Respuesta::vista('admin/carnets-imprimir', [
            'titulo'       => 'Carnets para imprimir',
            'tarjetas'     => $tarjetas,
            'filtros'      => $filtros,
            'consulta'     => array_filter(['q' => $filtros['texto'], 'rol' => $filtros['rol']]) + $orden->consulta(),
            'tanda'        => $tanda,
            'tandas'       => $tandas,
            'conCarnet'    => $conCarnet,
            'porTanda'     => self::TANDA,
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
