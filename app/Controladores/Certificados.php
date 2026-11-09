<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Asistencia;
use App\Modelos\Certificado;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Imagen;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Url;

/**
 * Los certificados (3.21): lo que ve cada persona en «Mi certificado», la
 * página pública que comprueba un código, y lo que configura el administrador.
 *
 * Ver App\Modelos\Certificado para quién tiene derecho a qué.
 */
final class Certificados
{
    /* =====================================================================
       La persona
       ===================================================================== */

    /** «Mi certificado»: los suyos, para descargarlos, o por qué todavía no hay. */
    public function mio(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $evento = Evento::porId((int) $persona['evento_id']) ?? App::eventoExigido();
        $config = Certificado::configuracion((int) $evento['id']);
        $abiertos = Certificado::abiertos($evento);
        $completo = Certificado::tieneNombre($persona);

        $certificados = [];
        if ($abiertos && $completo) {
            foreach (Certificado::dePersona($persona, true, $config) as $derecho) {
                $certificados[] = $derecho + ['texto' => Certificado::redactar($derecho, $persona, $evento, $config)];
            }
        }

        Respuesta::vista('publico/certificado', [
            'titulo'       => 'Mi certificado',
            'pantalla'     => 'certificado',
            'abiertos'     => $abiertos,
            'completo'     => $completo,
            'certificados' => $certificados,
            'asistencia'   => Certificado::hayTablas()
                ? Certificado::asistencia((int) $persona['id'], (int) $evento['id'], $config) : null,
            'eventoPersona' => $evento,
        ]);
    }

    /** El PDF de uno de sus certificados. */
    public function descargar(Peticion $peticion, array $parametros): void
    {
        $persona = Guardia::personaActual();
        $evento = Evento::porId((int) $persona['evento_id']);
        $codigo = Certificado::normalizarCodigo((string) $parametros['codigo']);

        if ($evento === null || !Certificado::abiertos($evento)) {
            Respuesta::redirigir('/certificado', 'Los certificados todavía no están disponibles.', 'warn');
        }
        // El mismo 404 para «no existe» y para «no es tuyo»: el código no
        // tiene por qué decir nada de los certificados de otras personas.
        $derecho = $codigo !== '' ? Certificado::derechoPorCodigo($persona, $codigo) : null;
        if ($derecho === null) {
            Respuesta::error(404, 'Certificado no encontrado',
                'Ese certificado no está entre los tuyos. Vuelve a «Mi certificado» y descárgalo desde ahí.',
                [['texto' => 'Ir a Mi certificado', 'url' => Url::a('/certificado'), 'principal' => true]]);
        }

        $pdf = Certificado::pdf($derecho, $persona, $evento);
        Certificado::anotarDescarga((int) $derecho['emision']['id']);
        Bitacora::registrar('certificado_descargado', 'persona', (int) $persona['id'], ['tipo' => $derecho['tipo']]);
        Respuesta::pdf(self::nombreArchivo($derecho, $persona), $pdf);
    }

    /* =====================================================================
       Verificación pública
       ===================================================================== */

    /**
     * ¿Es auténtico? Lo que abre el QR del certificado, y un formulario para
     * escribir el código a mano. Pública: quien recibe el certificado —una
     * universidad, una empresa— no tiene cuenta en la plataforma.
     *
     * Muestra lo que el certificado ya dice —el nombre, de qué es, del evento y
     * cuándo—, nunca el documento ni el correo. Los códigos que no existen
     * cuentan para el límite de intentos por IP: con doce letras de 31 posibles
     * no se adivinan, pero tampoco se deja probar en cadena.
     */
    public function verificar(Peticion $peticion, array $parametros = []): void
    {
        $escrito = trim((string) ($parametros['codigo'] ?? $peticion->query('codigo')));
        $resultado = null;
        if ($escrito !== '') {
            $ip = $peticion->ip();
            Limite::exigir('certificado_codigo', $ip);
            $codigo = Certificado::normalizarCodigo($escrito);
            $emision = Certificado::porCodigo($codigo);
            $persona = $emision !== null ? Persona::porId((int) $emision['persona_id']) : null;
            $evento = $persona !== null ? Evento::porId((int) $persona['evento_id']) : null;
            $derecho = ($persona !== null && $evento !== null) ? Certificado::derechoPorCodigo($persona, $codigo) : null;

            if ($emision === null || $persona === null || $evento === null) {
                Limite::registrarFallo('certificado_codigo', $ip);
                $resultado = ['valido' => false, 'existe' => false];
            } elseif ($derecho === null) {
                // Existió, pero ya no hay derecho a él: se quitó un ingreso
                // que se había registrado a mano, o se retiró la aprobación de
                // la exposición. Se dice así, sin más detalle.
                $resultado = ['valido' => false, 'existe' => true, 'codigo' => Certificado::codigoLegible($codigo)];
            } else {
                $config = Certificado::configuracion((int) $evento['id']);
                $texto = Certificado::redactar($derecho, $persona, $evento, $config);
                $resultado = [
                    'valido'  => true,
                    'codigo'  => Certificado::codigoLegible($codigo),
                    'nombre'  => $texto['nombre'],
                    'titulo'  => Certificado::TIPOS[$derecho['tipo']],
                    'resumen' => $texto['resumen'],
                    'completa' => $texto['completa'],
                    'cuerpo'  => implode('', array_column($texto['cuerpo'], 'texto')),
                    'evento'  => (string) $evento['nombre'],
                    'emitido' => Certificado::fechaLarga((string) $derecho['emision']['emitido_en']),
                    'horas'   => $derecho['tipo'] === 'asistencia' && $derecho['asistencia']['horas'] > 0
                        ? Certificado::horas((float) $derecho['asistencia']['horas']) : '',
                ];
            }
        }

        Respuesta::vista('publico/certificado-verificar', [
            'titulo'    => 'Verificar un certificado',
            'pantalla'  => 'verificar',
            'escrito'   => $escrito,
            'resultado' => $resultado,
            // Lleva un nombre: que no lo guarde ningún buscador.
            'noIndexar' => true,
        ], $resultado !== null && !$resultado['valido'] && !$resultado['existe'] ? 404 : 200);
    }

    /* =====================================================================
       El administrador
       ===================================================================== */

    /** Configuración → Certificados: textos, horas, firma, imágenes. */
    public function configuracion(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        $errores = [];

        if ($peticion->esPost() && Certificado::hayTablas()) {
            $antes = Certificado::configuracion($eventoId);
            $usuarioId = (int) (Guardia::usuarioActual()['id'] ?? 0) ?: null;

            // Las horas de cada jornada, solo de las de este evento.
            $horas = [];
            $enviadas = $peticion->campoEstructurado('horas');
            foreach (Evento::jornadas($eventoId) as $jornada) {
                $valor = str_replace(',', '.', trim((string) ($enviadas[(int) $jornada['id']] ?? '')));
                if ($valor === '') {
                    continue;
                }
                if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > 24) {
                    $errores['horas'] = 'Las horas de cada jornada van de 0 a 24, con medias horas si hace falta (7,5).';
                    continue;
                }
                $redondeado = round((float) $valor * 2) / 2;
                if ($redondeado > 0) {
                    $horas[(int) $jornada['id']] = $redondeado;
                }
            }

            $color = strtoupper($peticion->campo('color'));
            $columnas = [
                'nombre_evento' => mb_substr($peticion->campo('nombre_evento'), 0, 200),
                'entidad'       => mb_substr($peticion->campo('entidad'), 0, 300),
                'ciudad'        => mb_substr($peticion->campo('ciudad'), 0, 120),
                'horas_json'    => $horas !== [] ? json_encode($horas) : null,
                'expositores'   => $peticion->marcado('expositores') ? 1 : 0,
                'color'         => preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : '',
                'firma_nombre'  => mb_substr($peticion->campo('firma_nombre'), 0, 160),
                'firma_cargo'   => mb_substr($peticion->campo('firma_cargo'), 0, 200),
            ];
            // Si quien certifica quedó igual al de fábrica, no se guarda: así
            // un cambio futuro del texto de fábrica le llega a este evento.
            if ($columnas['entidad'] === Certificado::ENTIDAD_DE_FABRICA) {
                $columnas['entidad'] = '';
            }

            $reemplazadas = [];
            foreach (['fondo', 'texto', 'firma'] as $ranura) {
                $campo = $ranura . '_imagen';
                $anterior = (string) $antes[$campo];
                $archivo = $peticion->archivo($campo);
                if ($archivo !== null) {
                    try {
                        [$columnas[$campo]] = Imagen::guardarParaCertificado($archivo, $eventoId, $ranura);
                    } catch (\DomainException $e) {
                        $errores[$campo] = $e->getMessage();
                        continue;
                    } catch (\Throwable $e) {
                        Registro::excepcion($e);
                        $errores[$campo] = 'No se pudo procesar la imagen en el servidor.';
                        continue;
                    }
                } elseif ($peticion->marcado('quitar_' . $ranura)) {
                    $columnas[$campo] = '';
                } else {
                    continue;
                }
                if ($anterior !== '' && $anterior !== $columnas[$campo]) {
                    $reemplazadas[] = $anterior;
                }
            }

            Certificado::guardarConfiguracion($eventoId, $columnas, $usuarioId);
            // Las imágenes anteriores se borran cuando las nuevas ya quedaron anotadas.
            foreach ($reemplazadas as $anterior) {
                Imagen::borrarParaCertificado($anterior);
            }
            Bitacora::registrar('certificados_configurados', 'evento', $eventoId, [
                'fondo'  => isset($columnas['fondo_imagen']),
                'texto'  => isset($columnas['texto_imagen']),
                'firma'  => isset($columnas['firma_imagen']),
                'horas'  => array_sum($horas),
                'expositores' => (bool) $columnas['expositores'],
            ]);

            if ($errores === []) {
                Respuesta::redirigir('/admin/configuracion/certificados',
                    'Certificados guardados. Descarga un ejemplo para ver cómo quedan.');
            }
        }

        Respuesta::vista('admin/config-certificados', [
            'titulo'    => 'Configuración de los certificados',
            'pantalla'  => 'configuracion',
            'pestana'   => 'certificados',
            'hayTablas' => Certificado::hayTablas(),
            'config'    => Certificado::configuracion($eventoId),
            'jornadas'  => Evento::jornadas($eventoId),
            'resumen'   => Certificado::hayTablas() ? Certificado::resumen($eventoId) : null,
            'errores'   => $errores,
            'nombreDeFabrica' => 'el evento ' . trim((string) $evento['nombre']),
        ]);
    }

    /** Abre o cierra la descarga. Aparte del formulario: tiene su propio botón. */
    public function estado(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $eventoId = (int) $evento['id'];
        if (!Certificado::hayTablas()) {
            Respuesta::redirigir('/admin', 'Para los certificados, la base de datos tiene que ponerse al día. Pulsa '
                . '«Actualizar la base de datos» en el aviso de arriba y vuelve a Configuración → Certificados.', 'warn');
        }
        $estado = $peticion->campo('estado');
        if (!isset(Certificado::ESTADOS[$estado])) {
            Respuesta::redirigir('/admin/configuracion/certificados', 'Elige si los certificados quedan cerrados o disponibles.', 'warn');
        }
        $antes = Certificado::configuracion($eventoId)['estado'];
        Certificado::guardarConfiguracion($eventoId, ['estado' => $estado],
            (int) (Guardia::usuarioActual()['id'] ?? 0) ?: null);
        if ($antes !== $estado) {
            Bitacora::registrar('certificados_estado', 'evento', $eventoId, ['estado' => $estado]);
        }
        Respuesta::redirigir('/admin/configuracion/certificados#estado', $estado === 'abierto'
            ? 'Certificados disponibles: cada persona con derecho ya lo encuentra en «Mi certificado».'
            : 'Certificados cerrados: nadie los ve en el menú ni los descarga. Los que ya se descargaron se siguen verificando.');
    }

    /** Un certificado de ejemplo con datos inventados, para ver el diseño. */
    public function ejemplo(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        if (!Certificado::hayTablas()) {
            Respuesta::redirigir('/admin/configuracion/certificados');
        }
        $caso = in_array($peticion->query('caso'), ['completa', 'parcial', 'expositor'], true) ? $peticion->query('caso') : 'completa';
        Respuesta::pdf('Certificado-ejemplo-' . $caso . '.pdf', Certificado::ejemplo($evento, $caso), true);
    }

    /** Las imágenes cargadas, para verlas en la configuración. Solo el administrador: una es una firma. */
    public function imagen(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $config = Certificado::configuracion((int) $evento['id']);
        $archivo = (string) ($config[((string) $parametros['ranura']) . '_imagen'] ?? '');
        if (!in_array((string) $parametros['ranura'], ['fondo', 'texto', 'firma'], true) || $archivo === '') {
            Respuesta::error(404, 'Sin imagen', 'No hay ninguna imagen cargada ahí.');
        }
        Respuesta::archivo(Certificado::rutaImagen($archivo), str_ends_with($archivo, '.png') ? 'image/png' : 'image/jpeg');
    }

    /**
     * El certificado de una persona, desde su ficha: para quien no puede
     * entrar y lo pide por otro lado. Funciona aunque los certificados estén
     * cerrados, para revisarlos antes de abrirlos, y queda en la bitácora.
     */
    public function dePersona(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $persona = Persona::porId((int) $parametros['persona']);
        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id'] || !Certificado::hayTablas()) {
            Respuesta::error(404, 'Registro no encontrado', 'Esa persona no está registrada en el evento activo.');
        }
        if (!Certificado::tieneNombre($persona)) {
            Respuesta::redirigir('/admin/registros/' . (int) $persona['id'],
                'Sin nombre no hay certificado: esa persona todavía no completa su registro.', 'warn');
        }
        $tipo = $peticion->query('tipo');
        $referencia = (int) $peticion->query('ref');
        foreach (Certificado::dePersona($persona) as $derecho) {
            if ($derecho['tipo'] === $tipo && (int) $derecho['referencia'] === $referencia) {
                $pdf = Certificado::pdf($derecho, $persona, $evento);
                Bitacora::registrar('certificado_descargado', 'persona', (int) $persona['id'],
                    ['tipo' => $tipo, 'por' => 'equipo']);
                Respuesta::pdf(self::nombreArchivo($derecho, $persona), $pdf);
            }
        }
        Respuesta::redirigir('/admin/registros/' . (int) $persona['id'],
            'Esa persona no tiene derecho a ese certificado.', 'warn');
    }

    /**
     * Registrar a mano el ingreso de una jornada, o quitar uno que se registró
     * así. Para quien estuvo y no escaneó el código: sin ingreso no hay
     * certificado, y pasado el día el escáner ya no deja sellarlo.
     *
     * Solo se quitan los ingresos registrados a mano: los del código del día y
     * los del escáner son el registro de lo que pasó en la puerta.
     */
    public function asistenciaManual(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $persona = Persona::porId($peticion->entero('persona'));
        if (!$persona || (int) $persona['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir('/admin/registros', 'Esa persona no existe en este evento.', 'warn');
        }
        $id = (int) $persona['id'];
        $volver = '/admin/registros/' . $id;
        $jornada = Bd::fila('SELECT * FROM {evento_dia} WHERE id = ? AND evento_id = ?',
            [$peticion->entero('jornada'), (int) $evento['id']]);
        if (!$jornada) {
            Respuesta::redirigir($volver, 'Ese día ya no existe en el evento.', 'warn');
        }

        if ($peticion->campo('accion') === 'quitar') {
            $quitados = Bd::ejecutar(
                "DELETE FROM {asistencia} WHERE persona_id = ? AND evento_dia_id = ? AND via = 'manual'",
                [$id, (int) $jornada['id']]
            )->rowCount();
            if ($quitados === 0) {
                Respuesta::redirigir($volver, 'Solo se quitan los ingresos registrados a mano.', 'warn');
            }
            Bitacora::registrar('asistencia_manual_quitada', 'persona', $id, ['dia' => (int) $jornada['numero']]);
            Respuesta::redirigir($volver, 'Se quitó el ingreso del día ' . (int) $jornada['numero'] . '.');
        }

        if ((string) $jornada['fecha'] > date('Y-m-d')) {
            Respuesta::redirigir($volver, 'Ese día todavía no ha llegado: su ingreso se registra en la puerta.', 'warn');
        }
        $resultado = Asistencia::sellar($id, $jornada, 'manual', (int) (Guardia::usuarioActual()['id'] ?? 0) ?: null, 'equipo');
        Respuesta::redirigir($volver, $resultado['repetida']
            ? 'Esa persona ya tenía registrado el ingreso del día ' . (int) $jornada['numero'] . '.'
            : 'Ingreso del día ' . (int) $jornada['numero'] . ' registrado a mano. Ya cuenta para su certificado.');
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    /** «Certificado-asistencia-Maria-Fernanda-Zambrano-Ortiz.pdf» */
    private static function nombreArchivo(array $derecho, array $persona): string
    {
        $nombre = trim((string) $persona['nombre']);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
            if (is_string($ascii) && $ascii !== '') {
                $nombre = $ascii;
            }
        }
        $nombre = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $nombre), '-');
        return 'Certificado-' . ($derecho['tipo'] === 'expositor' ? 'participacion' : 'asistencia')
            . ($nombre !== '' ? '-' . mb_substr($nombre, 0, 60) : '') . '.pdf';
    }
}
