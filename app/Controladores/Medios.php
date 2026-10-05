<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Documento;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Archivos que no son páginas: logos y códigos QR sueltos.
 *
 * Los archivos subidos nunca se sirven directamente desde el disco. Pasan por
 * aquí para que el tipo de contenido lo decida el servidor y no la extensión
 * del archivo, y para que salgan con una política que impide que un SVG con
 * script dentro se ejecute en el origen del sitio.
 */
final class Medios
{
    public function logo(Peticion $peticion, array $parametros): void
    {
        $eventoId = (int) $parametros['evento'];
        $tema = Tema::del($eventoId);

        if ($tema['logo'] === '') {
            Respuesta::error(404, 'Sin logo', 'Este evento no tiene logo cargado.');
        }

        // basename() corta cualquier intento de subir por el árbol de directorios,
        // aunque el nombre lo pone el servidor y no debería llegar nada raro.
        $ruta = RAIZ . '/almacen/logos/' . basename((string) $tema['logo']);
        $tipo = $tema['logo_tipo'] !== '' ? (string) $tema['logo_tipo'] : 'application/octet-stream';

        Respuesta::archivo($ruta, $tipo, true);
    }

    /**
     * El banner del formulario de registro. Público, como el formulario.
     *
     * El nombre del archivo sale de la base y no de la dirección: así no hay
     * forma de pedir por aquí otro archivo de la carpeta.
     */
    public function banner(Peticion $peticion, array $parametros): void
    {
        $eventoId = (int) $parametros['evento'];

        // El del evento activo lo ve cualquiera: va arriba del formulario
        // público. El de otro —uno en borrador puede no estar anunciado—, solo
        // el equipo. Con la misma respuesta que «no hay», para que contar ids
        // no diga qué eventos tienen banner.
        if ($eventoId !== (int) (App::eventoActivo()['id'] ?? 0)) {
            $equipo = Guardia::equipoOperativo();
            if ($equipo === null || !Guardia::tieneRol($equipo, 'consulta')) {
                Respuesta::error(404, 'Sin banner', 'Este evento no tiene banner cargado.');
            }
        }

        $fila = Bd::fila('SELECT banner_imagen, banner_tipo FROM {evento_formulario} WHERE evento_id = ?', [$eventoId]);
        $archivo = (string) ($fila['banner_imagen'] ?? '');
        if ($archivo === '') {
            Respuesta::error(404, 'Sin banner', 'Este evento no tiene banner cargado.');
        }
        Respuesta::archivo(
            RAIZ . '/almacen/logos/' . basename($archivo),
            (string) ($fila['banner_tipo'] ?? '') ?: 'image/jpeg'
        );
    }

    /**
     * La fotografía de una persona.
     *
     * La ve su dueño, el equipo organizador y el Staff. Nadie más: el id es un
     * número correlativo, así que sin esta comprobación bastaría con contar
     * desde uno para descargar la cara de todos los asistentes.
     *
     * El Staff entra aquí porque imprime los carnets, y un carnet sin foto no
     * sirve para lo único que hace falta en la puerta: comparar la cara que
     * está delante con la del plástico.
     */
    public function foto(Peticion $peticion, array $parametros): void
    {
        $id = (int) $parametros['persona'];

        $yo = Guardia::personaActual();
        $equipo = Guardia::equipoOperativo();
        $esDelEquipo = $equipo !== null && Guardia::tieneRol($equipo, 'consulta');

        if (($yo === null || (int) $yo['id'] !== $id) && !$esDelEquipo && !Guardia::puedeAcreditar()) {
            Respuesta::error(404, 'Sin fotografía', 'No hay ninguna imagen en esa dirección.');
        }

        $persona = ($yo !== null && (int) $yo['id'] === $id)
            ? $yo
            : \App\Modelos\Persona::porId($id);

        $archivo = (string) ($persona['foto'] ?? '');
        if ($persona === null || $archivo === '') {
            Respuesta::error(404, 'Sin fotografía', 'Esta persona no tiene foto cargada.');
        }

        $tipo = (string) ($persona['foto_tipo'] ?? '') ?: 'image/jpeg';

        // basename() aunque el nombre lo ponga el servidor: es la barrera que
        // impide que una fila manipulada en la base saque archivos del árbol.
        Respuesta::archivo(RAIZ . '/almacen/fotos/' . basename($archivo), $tipo, true);
    }

    /**
     * La hoja de vida o la exposición de un expositor.
     *
     * Las ve quien las subió y las ve el equipo que revisa las propuestas.
     * Nadie más: el número de una propuesta es correlativo, así que sin esta
     * comprobación bastaría con contar desde uno para bajarse las hojas de vida
     * de todos los expositores, con su teléfono y su dirección dentro.
     *
     * Salen siempre como descarga, nunca incrustadas. Ver App\Nucleo\Documento.
     */
    public function documento(Peticion $peticion, array $parametros): void
    {
        $clase = Documento::porRanura((string) $parametros['ranura']);
        if ($clase === null) {
            Respuesta::error(404, 'Documento no encontrado', 'No hay ningún archivo en esa dirección.');
        }

        $propuesta = Bd::fila(
            "SELECT pr.persona_id, pr.$clase AS archivo, pr.{$clase}_tipo AS tipo, p.nombre
               FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
              WHERE pr.id = ?",
            [(int) $parametros['propuesta']]
        );

        $yo = Guardia::personaActual();
        $equipo = Guardia::equipoOperativo();
        $esDelEquipo = $equipo !== null && Guardia::tieneRol($equipo, 'consulta');
        $esSuyo = $propuesta && $yo !== null && (int) $yo['id'] === (int) $propuesta['persona_id'];

        // El mismo 404 para «no existe» y para «no es tuyo»: distinguirlos
        // convertiría esta dirección en una forma de averiguar qué propuestas
        // hay y quién adjuntó qué.
        if (!$propuesta || (!$esSuyo && !$esDelEquipo) || (string) $propuesta['archivo'] === '') {
            Respuesta::error(404, 'Documento no encontrado', 'No hay ningún archivo en esa dirección.');
        }

        $archivo = (string) $propuesta['archivo'];
        $tipo = (string) $propuesta['tipo'] ?: 'application/octet-stream';

        Respuesta::archivo(
            Documento::ruta($archivo),
            $tipo,
            false,
            Documento::nombreDescarga($clase, (string) $propuesta['nombre'], $archivo)
        );
    }

    /** QR del carnet propio, como archivo SVG suelto. */
    public function qrCarnet(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $credencial = Credencial::asegurar((int) $persona['id']);

        $svg = Qr::svg(Credencial::urlQr($credencial), [
            'nivel' => 'Q', 'silencio' => 4, 'titulo' => 'Código de mi credencial',
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }

    /**
     * El QR personal de acceso, como archivo suelto.
     *
     * Es el que abre la sesión de su dueño, así que sale con «no-store»: no
     * puede quedarse en la caché de un proxy compartido ni en el disco del
     * teléfono como una imagen más.
     */
    public function qrAcceso(Peticion $peticion): void
    {
        if (!\App\Nucleo\Autenticacion::activo('qr')) {
            Respuesta::error(404, 'Sin código de acceso',
                'La organización no tiene encendido el acceso por QR.');
        }

        $persona = Guardia::personaActual();

        $svg = Qr::svg(Credencial::urlAcceso((int) $persona['id']), [
            'nivel' => 'Q', 'silencio' => 4, 'titulo' => 'Mi código de acceso',
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }

    /** QR de una jornada, para incrustar o descargar. */
    public function qrDia(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $jornada = Evento::jornada((int) $evento['id'], (int) $parametros['numero']);
        if (!$jornada) {
            Respuesta::error(404, 'Jornada no encontrada', 'Ese día no existe en este evento.');
        }

        $svg = Qr::svg(Url::absoluta('/d/' . $jornada['token']), [
            'nivel' => 'M', 'silencio' => 4,
            'titulo' => 'Código de acceso del día ' . $jornada['numero'],
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }
}
