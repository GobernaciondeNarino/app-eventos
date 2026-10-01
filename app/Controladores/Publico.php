<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Bd;
use App\Nucleo\Correo;
use App\Nucleo\Documento;
use App\Nucleo\Guardia;
use App\Nucleo\Imagen;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Registro;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Url;

/**
 * Pantallas abiertas: portada, registro y agenda.
 */
final class Publico
{
    public function inicio(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $jornadas = Evento::jornadas((int) $evento['id']);

        // ¿Ya llegó el evento? El botón principal cambia de texto según eso:
        // «Preregistrarme» mientras faltan días, «Registrarme» cuando alguna
        // jornada es hoy o ya empezó. No es un detalle de redacción: quien
        // llega a la portada el día del evento, en la puerta, busca la palabra
        // que describe lo que va a hacer ahora.
        $hoy = date('Y-m-d');
        $empezado = false;
        foreach ($jornadas as $j) {
            if ((string) $j['fecha'] <= $hoy) {
                $empezado = true;
                break;
            }
        }

        Respuesta::vista('publico/inicio', [
            'titulo'    => 'Ingreso',
            'pantalla'  => 'ingreso',
            'jornadas'  => $jornadas,
            'empezado'  => $empezado,
            'esHoy'     => Evento::jornadaDeHoy((int) $evento['id']) !== null,
        ]);
    }

    /* =====================================================================
       Registro
       -------------------------------------------------------------------------
       El formulario completo, siempre abierto al público. Se llamaba
       «preregistro» y sigue respondiendo en esa dirección, pero en pantalla es
       «Registro»: la palabra describe lo mismo antes y durante el evento, y
       «preregistro» dejaba de tener sentido justo el día que más gente llega.
       ===================================================================== */

    public function registro(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        $yo = Guardia::personaActual();
        $errores = [];

        // Lo que ya tiene subido. Se mira antes de validar porque los dos
        // adjuntos son obligatorios para quien expone, y a quien ya los tiene
        // no se le pueden volver a pedir cada vez que corrige un teléfono.
        $suPropuesta = $this->documentosDe($yo);

        // Quien ya está identificado ve sus datos y puede corregirlos.
        $valores = $yo ? [
            'correo'         => $yo['correo'],
            'nombre'         => $yo['nombre'],
            'tipo_documento' => $yo['tipo_documento'],
            'documento'      => Persona::documento($yo),
            'telefono'       => $yo['telefono'],
            'entidad'        => $yo['entidad'],
            'departamento'   => $yo['departamento'],
            'municipio'      => $yo['municipio'],
            'rol'            => $yo['rol'],
        ] + Persona::caracterizacion((int) $yo['id']) : [];

        if ($peticion->esPost()) {
            $valores = $this->valoresEnviados($peticion);

            // El correo de quien ya está identificado no se toca. En pantalla el
            // campo va en solo lectura, pero eso lo decide el navegador: un
            // envío hecho a mano con el correo de otra persona hacía que
            // Persona::registrar() encontrara ESE registro y lo sobrescribiera
            // con los datos del atacante. El correo es la identidad aquí.
            if ($yo !== null) {
                $valores['correo'] = (string) $yo['correo'];
            }

            $errores = $this->validarRegistro(
                $valores, $peticion, $yo !== null, $suPropuesta['documentos']
            );

            // Un correo ya registrado no se puede tocar desde aquí.
            //
            // Persona::registrar() busca por correo y actualiza si encuentra;
            // después, sin sesión previa, se abría sesión con ese id. O sea que
            // cualquiera que supiera el correo de un asistente —y en una entidad
            // son públicos— podía reescribir su nombre y su documento y quedarse
            // dentro de su cuenta: ver su carnet, su cédula y sus contactos.
            //
            // Para corregir sus datos hay que demostrar que se controla ese
            // buzón, que es exactamente para lo que está el acceso por código.
            if (!$errores && $yo === null
                && Persona::porCorreo((int) $evento['id'], (string) $valores['correo']) !== null) {
                $errores['correo'] = 'Ese correo ya tiene un registro en este evento. '
                    . 'Entra con tu código de acceso para verlo o corregirlo.';
                $errores['ofrecer_acceso'] = '1';
            }

            if (!$errores) {
                Limite::exigir('preregistro_ip', $peticion->ip());
                // Se anota el intento aunque salga bien: aquí el abuso consiste
                // en registrar muchas veces con éxito, no en fallar.
                Limite::registrar('preregistro_ip', $peticion->ip());
                try {
                    $resultado = Persona::registrar((int) $evento['id'], $valores);
                    $personaId = (int) $resultado['id'];
                    $propuestaId = $this->guardarPropuesta($personaId, $valores, $peticion);

                    // La contraseña solo se toca si se escribió una. Dejar el
                    // campo en blanco al corregir los datos conserva la que ya
                    // había, que es lo que espera cualquiera.
                    $clave = $peticion->campo('clave');
                    if ($clave !== '') {
                        Persona::ponerClave($personaId, $clave);
                    }

                    // La foto y los adjuntos van después de crear la persona y
                    // la propuesta, porque el nombre de cada archivo lleva su
                    // número.
                    $avisos = [];
                    $falloFoto = $this->guardarFoto($peticion, $personaId);
                    if ($falloFoto !== null) {
                        $avisos[] = 'La foto no se guardó: ' . $falloFoto;
                    }
                    if ($propuestaId !== null) {
                        $avisos = array_merge($avisos, $this->guardarDocumentos($propuestaId, $peticion));
                    }

                    // Queda con sesión abierta: acaba de demostrar que controla
                    // ese correo solo si venía identificado; si no, el enlace
                    // del carnet le llega al buzón.
                    if ($yo === null) {
                        Sesion::abrir('asistente', $personaId);
                        Correo::carnetEmitido(
                            (string) $valores['correo'],
                            (string) $valores['nombre'],
                            (string) $evento['nombre'],
                            Url::absoluta('/carnet'),
                            Autenticacion::activo('qr') ? Credencial::urlAcceso($personaId) : ''
                        );
                    }

                    // Ni la foto ni los adjuntos abortan el registro: se vuelven
                    // a subir cuando se quiera, y perder el registro entero
                    // porque el teléfono mandó un HEIC sería desproporcionado.
                    // Lo que no se puede es guardar a medias en silencio.
                    if ($avisos) {
                        Respuesta::redirigir('/carnet',
                            'Guardamos tus datos. ' . implode(' ', $avisos), 'warn');
                    }

                    Respuesta::redirigir('/carnet', $resultado['nueva']
                        ? 'Registro completo. Este es tu carnet.'
                        : 'Actualizamos tus datos.');
                } catch (\DomainException $e) {
                    $errores['documento'] = $e->getMessage();
                } catch (\Throwable $e) {
                    \App\Nucleo\Registro::excepcion($e);
                    $errores['general'] = 'No se pudo guardar el registro. Inténtalo de nuevo en un momento.';
                }
            }
        }

        // Tres situaciones distintas y la pantalla las dice: alguien de fuera,
        // alguien que creó su acceso y todavía no llenó nada, y alguien que ya
        // está registrado y viene a corregir.
        $completo = $yo !== null && Persona::registroCompleto($yo);

        // Si el envío traía archivos y algo falló, se perdieron: el navegador
        // vacía los campos de archivo al repintar el formulario. Decirlo evita
        // que alguien corrija el campo que falló, guarde, y descubra después
        // que sus PDF no llegaron.
        $reelegir = $errores !== [] && (
            $peticion->archivo('hoja_vida') !== null || $peticion->archivo('exposicion') !== null
        );

        Respuesta::vista('publico/registro', [
            'titulo'        => $yo === null
                ? 'Registro'
                : ($completo ? 'Mis datos' : 'Completa tu registro'),
            'pantalla'      => 'registro',
            'valores'       => $valores,
            'errores'       => $errores,
            'departamentos' => Datos::departamentos(),
            'municipios'    => Datos::municipiosDe((string) ($valores['departamento'] ?? '')),
            'categorias'    => Datos::CATEGORIAS,
            'jornadas'      => Evento::jornadas((int) $evento['id']),
            'yaRegistrado'  => $yo !== null,
            'completo'      => $completo,
            'pideClave'     => Autenticacion::activo('clave'),
            'claveMinima'   => Autenticacion::claveMinima(),
            'tieneClave'    => $yo !== null && Persona::tieneClave($yo),
            'foto'          => $yo !== null && Persona::tieneFoto($yo)
                ? u('/medios/foto/' . (int) $yo['id']) : '',
            'documentos'    => $suPropuesta['documentos'],
            'agendada'      => $suPropuesta['aprobada'],
            'reelegir'      => $reelegir,
        ]);
    }

    /**
     * Guarda la foto del carnet, si la mandaron.
     *
     * Va después de crear la persona y a propósito no aborta el preregistro:
     * la foto es un adorno del carnet y perder un registro entero porque el
     * teléfono mandó un HEIC que GD no entiende sería desproporcionado. Si
     * falla, se anota y se devuelve el motivo, y los datos quedan guardados
     * igual: quien llama compone el aviso.
     */
    private function guardarFoto(Peticion $peticion, int $personaId): ?string
    {
        if ($peticion->marcado('quitar_foto')) {
            Persona::quitarFoto($personaId);
            return null;
        }

        $archivo = $peticion->archivo('foto');
        if ($archivo === null) {
            return null;
        }

        // El encuadre que eligió la persona en el editor del navegador. Puede
        // no venir —sin JavaScript no hay editor— y entonces se recorta el
        // centro. Los valores no se validan aquí: lo hace Imagen::encuadre(),
        // que es la única que conoce las medidas reales de la imagen.
        $recorte = null;
        if ($peticion->campo('foto_lado') !== '') {
            $recorte = [
                'x'      => $peticion->campo('foto_x'),
                'y'      => $peticion->campo('foto_y'),
                'lado'   => $peticion->campo('foto_lado'),
                'ancho'  => $peticion->campo('foto_ancho'),
                'alto'   => $peticion->campo('foto_alto'),
            ];
        }

        try {
            [$nombre, $tipo] = Imagen::guardarFoto($archivo, $personaId, $recorte);
            Persona::ponerFoto($personaId, $nombre, $tipo);
            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            Registro::excepcion($e);
            return 'No se pudo procesar la imagen en el servidor.';
        }
    }

    private function valoresEnviados(Peticion $peticion): array
    {
        return [
            'correo'         => mb_strtolower($peticion->campo('correo')),
            'nombre'         => $peticion->campo('nombre'),
            'tipo_documento' => $peticion->campo('tipo_documento', 'CC'),
            'documento'      => $peticion->campo('documento'),
            'telefono'       => $peticion->campo('telefono'),
            'rol'            => $peticion->campo('rol', 'participante'),
            'genero'         => $peticion->campo('genero'),
            'discapacidad'   => $peticion->campo('discapacidad'),
            'etnia'          => $peticion->campo('etnia'),
            'entidad'        => $peticion->campo('entidad'),
            'rango_edad'     => $peticion->campo('rango_edad'),
            'departamento'   => $peticion->campo('departamento'),
            'municipio'      => $peticion->campo('municipio'),
            'expositor'      => $peticion->marcado('expositor'),
            'tema'           => $peticion->campo('tema'),
            'categoria'      => $peticion->campo('categoria'),
            'detalle'        => $peticion->campo('detalle'),
            'dia_preferido'  => $peticion->entero('dia_preferido', 1),
            'duracion'       => $peticion->entero('duracion', 40),
            'requerimientos' => $peticion->campo('requerimientos'),
            // La contraseña no se devuelve nunca a la pantalla: si el formulario
            // se vuelve a pintar por un error en otro campo, este se repinta
            // vacío. Se guarda aparte, fuera de $valores.
        ];
    }

    /**
     * Validación del lado del servidor.
     *
     * La misma que hace el navegador, otra vez. Lo del navegador es comodidad
     * para quien diligencia; esto es lo que de verdad protege la base de datos,
     * porque un envío puede llegar sin pasar por ninguna pantalla.
     *
     * @param array<string, mixed> $yaSubidos los adjuntos que esta persona ya tiene
     */
    private function validarRegistro(
        array $v,
        Peticion $peticion,
        bool $identificado,
        array $yaSubidos = []
    ): array {
        $errores = [];

        if (!$identificado && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores['correo'] = 'Escribe un correo válido.';
        }
        if (mb_strlen(trim($v['nombre'])) < 5) {
            $errores['nombre'] = 'Escribe el nombre completo.';
        }
        if (mb_strlen($v['nombre']) > 160) {
            $errores['nombre'] = 'El nombre es demasiado largo.';
        }

        $documento = Persona::normalizarDocumento($v['documento']);
        if (strlen($documento) < 5 || strlen($documento) > 16) {
            $errores['documento'] = 'El número de identificación debe tener entre 5 y 16 caracteres.';
        }
        // El pasaporte y la cédula de extranjería llevan letras; la cédula
        // colombiana y la tarjeta de identidad, no.
        if (in_array($v['tipo_documento'] ?? 'CC', ['CC', 'TI'], true)
            && preg_match('/[^0-9]/', $documento)) {
            $errores['documento'] = 'La cédula y la tarjeta de identidad son solo números.';
        }

        if ($v['telefono'] !== '') {
            $digitos = preg_replace('/\D/', '', $v['telefono']) ?? '';
            if (strlen($digitos) < 7 || strlen($digitos) > 15) {
                $errores['telefono'] = 'Revisa el número de teléfono.';
            }
        }

        if (!in_array($v['tipo_documento'], array_keys(Datos::TIPOS_DOCUMENTO), true)) {
            $errores['tipo_documento'] = 'Tipo de documento no válido.';
        }
        if (!in_array($v['rol'], Persona::ROLES, true)) {
            $errores['rol'] = 'Perfil no válido.';
        }
        if ($v['departamento'] !== '' && !in_array($v['departamento'], Datos::departamentos(), true)) {
            $errores['departamento'] = 'Departamento no válido.';
        }
        if ($v['municipio'] !== '' && !Datos::municipioValido($v['departamento'], $v['municipio'])) {
            $errores['municipio'] = 'Ese municipio no corresponde al departamento elegido.';
        }
        if ($v['rango_edad'] !== '' && !in_array($v['rango_edad'], Datos::RANGOS_EDAD, true)) {
            $errores['rango_edad'] = 'Rango de edad no válido.';
        }

        if ($v['expositor']) {
            if (mb_strlen(trim($v['tema'])) < 5) {
                $errores['tema'] = 'Describe el tema de tu exposición.';
            }
            if (!in_array($v['categoria'], Datos::CATEGORIAS, true)) {
                $errores['categoria'] = 'Elige una categoría de la lista.';
            }
            if (mb_strlen(trim($v['detalle'])) < 30) {
                $errores['detalle'] = 'Amplía el detalle: al menos 30 caracteres.';
            }
            if (mb_strlen($v['detalle']) > 600) {
                $errores['detalle'] = 'El detalle no puede pasar de 600 caracteres.';
            }

            // Los dos adjuntos son obligatorios para quien va a exponer. El
            // comité decide con ellos, y una propuesta sin hoja de vida se
            // queda esperando a que alguien la persiga por correo.
            //
            // A quien ya los tiene no se le vuelven a pedir: un expositor que
            // entra a corregir una coma del detalle no debería necesitar el PDF
            // otra vez. Y el archivo se comprueba aquí, no al guardar, porque
            // un campo obligatorio tiene que fallar con los demás campos del
            // formulario y no después, con los datos ya grabados.
            foreach (Documento::CLASES as $clase => $regla) {
                $archivo = $peticion->archivo($clase);

                if ($archivo === null) {
                    if (!isset($yaSubidos[$clase])) {
                        $errores[$clase] = 'Adjunta tu ' . mb_strtolower($regla['etiqueta'])
                            . ' en ' . Documento::formatosLegibles($clase)
                            . '. Si todavía no la tienes a mano, regístrate sin marcar «voy a '
                            . 'exponer» y vuelve a este formulario cuando la tengas.';
                    }
                    continue;
                }

                $fallo = Documento::revisar($archivo, $clase);
                if ($fallo !== null) {
                    $errores[$clase] = $fallo;
                }
            }
        }

        // Contraseña simple. Solo se valida si el método está encendido y la
        // persona escribió algo: en blanco significa «no la cambies», y para
        // quien se registra por primera vez significa que entrará por otro
        // método —que es legítimo mientras haya alguno más.
        if (Autenticacion::activo('clave')) {
            $clave = $peticion->campo('clave');
            $minima = Autenticacion::claveMinima();

            if ($clave !== '') {
                if (mb_strlen($clave) < $minima) {
                    $errores['clave'] = 'La contraseña debe tener al menos ' . $minima . ' caracteres.';
                } elseif (mb_strlen($clave) > 200) {
                    $errores['clave'] = 'La contraseña es demasiado larga.';
                } elseif ($clave !== $peticion->campo('clave2')) {
                    $errores['clave2'] = 'Las dos contraseñas no coinciden.';
                }
            } elseif (!$identificado && count(Autenticacion::activos()) === 1) {
                // La contraseña es la única puerta del evento: sin ella, esta
                // persona no podría volver a entrar nunca.
                $errores['clave'] = 'Elige una contraseña: es la forma de entrar a este evento.';
            }
        }

        if (!$identificado && !$peticion->marcado('habeas')) {
            $errores['habeas'] = 'Debes autorizar el tratamiento de datos para continuar.';
        }

        return $errores;
    }

    /**
     * Guarda la propuesta del expositor y devuelve su número.
     *
     * Devuelve null solo cuando no hay ninguna: quien no marcó la casilla. Los
     * adjuntos que vinieran en ese envío se descartan, porque no hay dónde
     * colgarlos.
     */
    private function guardarPropuesta(int $personaId, array $v, Peticion $peticion): ?int
    {
        if (empty($v['expositor'])) {
            return null;
        }

        $existente = Bd::fila(
            "SELECT id, estado FROM {propuesta} WHERE persona_id = ? ORDER BY id DESC LIMIT 1",
            [$personaId]
        );

        $campos = [
            'titulo'         => mb_substr(trim($v['tema']), 0, 200),
            'categoria'      => mb_substr($v['categoria'], 0, 80),
            'detalle'        => mb_substr(trim($v['detalle']), 0, 600),
            'dia_preferido'  => max(1, min(30, (int) $v['dia_preferido'])),
            'duracion_min'   => in_array((int) $v['duracion'], [20, 40, 60], true) ? (int) $v['duracion'] : 40,
            'requerimientos' => mb_substr($v['requerimientos'], 0, 255),
        ];

        // Una propuesta ya aprobada no se pisa desde el formulario público: el
        // horario y el salón ya están publicados en la agenda.
        //
        // Los archivos sí se pueden cambiar, y por eso se devuelve el número
        // igual en vez de cortar aquí. Subir la presentación definitiva justo
        // después de que te aprueben es el momento en que la mayoría la tiene
        // lista, y cambiar el archivo no mueve nada de la agenda.
        if ($existente && $existente['estado'] === 'aprobada') {
            return (int) $existente['id'];
        }

        if ($existente) {
            Bd::actualizar('propuesta', $campos + ['estado' => 'pendiente'], 'id = :id', ['id' => $existente['id']]);
            return (int) $existente['id'];
        }
        return (int) Bd::insertar('propuesta', $campos + ['persona_id' => $personaId]);
    }

    /**
     * La hoja de vida y la exposición del expositor.
     *
     * Llega aquí con los archivos ya comprobados por validarRegistro(), así
     * que lo único que puede fallar es el disco. Aun así se atrapa y se avisa
     * en vez de abortar: con los datos ya grabados, tirar el registro entero
     * porque falló un permiso de carpeta sería peor que decirlo.
     *
     * No hay forma de quitar un adjunto, solo de reemplazarlo: son obligatorios
     * para quien expone, y un botón que deja la propuesta sin lo que la hace
     * evaluable no tiene sentido.
     *
     * El anterior se borra del disco solo después de que el nuevo esté escrito
     * y su fila actualizada. Al revés, un fallo a media subida dejaría a la
     * persona sin el documento que ya tenía.
     *
     * @return array<int, string>
     */
    private function guardarDocumentos(int $propuestaId, Peticion $peticion): array
    {
        $avisos = [];

        foreach (Documento::CLASES as $clase => $regla) {
            $anterior = (string) (Bd::valor(
                "SELECT $clase FROM {propuesta} WHERE id = ?",
                [$propuestaId]
            ) ?? '');

            $archivo = $peticion->archivo($clase);
            if ($archivo === null) {
                continue;
            }

            try {
                [$nombre, $tipo] = Documento::guardar($archivo, $clase, $propuestaId);
                Bd::actualizar('propuesta', [$clase => $nombre, $clase . '_tipo' => $tipo],
                    'id = :id', ['id' => $propuestaId]);
                Documento::borrar($anterior);
            } catch (\DomainException $e) {
                $avisos[] = mb_strtoupper(mb_substr($regla['etiqueta'], 0, 1))
                    . mb_substr($regla['etiqueta'], 1) . ' no se guardó: ' . $e->getMessage();
            } catch (\Throwable $e) {
                Registro::excepcion($e);
                $avisos[] = $regla['etiqueta'] . ' no se pudo guardar en el servidor.';
            }
        }

        return $avisos;
    }

    /**
     * Lo que ya tiene subido quien vuelve al formulario.
     *
     * Sin esto, un expositor que entra a corregir una coma del detalle vería
     * los dos campos vacíos y creería que sus archivos se perdieron.
     *
     * @return array{documentos: array<string, array{archivo:string, peso:string, url:string}>, aprobada: bool}
     */
    private function documentosDe(?array $persona): array
    {
        $vacio = ['documentos' => [], 'aprobada' => false];
        if ($persona === null) {
            return $vacio;
        }

        $fila = Bd::fila(
            'SELECT id, estado, hoja_vida, exposicion FROM {propuesta}
              WHERE persona_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $persona['id']]
        );
        if (!$fila) {
            return $vacio;
        }

        $subidos = [];
        foreach (Documento::CLASES as $clase => $regla) {
            $archivo = (string) ($fila[$clase] ?? '');
            if ($archivo === '') {
                continue;
            }
            $subidos[$clase] = [
                'archivo' => $archivo,
                'peso'    => Documento::peso($archivo),
                'url'     => u('/medios/documento/' . (int) $fila['id'] . '/' . $regla['ranura']),
            ];
        }
        return ['documentos' => $subidos, 'aprobada' => $fila['estado'] === 'aprobada'];
    }

    /** Municipios de un departamento, para el selector dependiente. */
    public function municipios(Peticion $peticion, array $parametros): void
    {
        $departamento = (string) $parametros['departamento'];
        Respuesta::json([
            'departamento' => $departamento,
            'municipios'   => Datos::municipiosDe($departamento),
        ]);
    }

    /* =====================================================================
       Agenda
       ===================================================================== */

    public function agenda(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        $jornadas = Evento::jornadas((int) $evento['id']);
        $dia = $peticion->entero('dia', (int) ($jornadas[0]['numero'] ?? 1));
        $texto = $peticion->query('q');
        $categoria = $peticion->query('categoria');

        $donde = ['d.evento_id = :evento', 'c.publicada = 1'];
        $parametros = ['evento' => (int) $evento['id']];

        if ($dia > 0) {
            $donde[] = 'd.numero = :dia';
            $parametros['dia'] = $dia;
        }
        if ($texto !== '') {
            // Un marcador por columna: sin emulación de sentencias preparadas,
            // MySQL no admite repetir el mismo nombre y la búsqueda de la
            // agenda respondía 500.
            $donde[] = '(pr.titulo LIKE :texto1 OR p.nombre LIKE :texto2'
                . ' OR p.entidad LIKE :texto3 OR pr.categoria LIKE :texto4)';
            $patron = '%' . str_replace(['%', '_'], ['\%', '\_'], $texto) . '%';
            $parametros += ['texto1' => $patron, 'texto2' => $patron,
                            'texto3' => $patron, 'texto4' => $patron];
        }
        if ($categoria !== '') {
            $donde[] = 'pr.categoria = :categoria';
            $parametros['categoria'] = $categoria;
        }

        $charlas = Bd::filas(
            'SELECT c.id, c.hora_inicio, c.salon, d.numero AS dia, d.fecha,
                    pr.titulo, pr.categoria, pr.detalle, pr.duracion_min,
                    p.nombre AS expositor, p.entidad
               FROM {charla} c
               JOIN {propuesta} pr ON pr.id = c.propuesta_id
               JOIN {persona} p ON p.id = pr.persona_id
               JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY d.numero, c.hora_inicio',
            $parametros
        );

        $categorias = array_column(
            Bd::filas(
                'SELECT DISTINCT pr.categoria
                   FROM {charla} c
                   JOIN {propuesta} pr ON pr.id = c.propuesta_id
                   JOIN {evento_dia} d ON d.id = c.evento_dia_id
                  WHERE d.evento_id = ? AND c.publicada = 1
               ORDER BY pr.categoria',
                [(int) $evento['id']]
            ),
            'categoria'
        );

        Respuesta::vista('publico/agenda', [
            'titulo'     => 'Agenda',
            'pantalla'   => 'agenda',
            'jornadas'   => $jornadas,
            'dia'        => $dia,
            'charlas'    => $charlas,
            'categorias' => $categorias,
            'q'          => $texto,
            'categoria'  => $categoria,
        ]);
    }

    public function charla(Peticion $peticion, array $parametros): void
    {
        $charla = Bd::fila(
            'SELECT c.*, d.numero AS dia, d.fecha, pr.titulo, pr.categoria, pr.detalle,
                    pr.duracion_min, p.nombre AS expositor, p.entidad
               FROM {charla} c
               JOIN {propuesta} pr ON pr.id = c.propuesta_id
               JOIN {persona} p ON p.id = pr.persona_id
               JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE c.id = ? AND c.publicada = 1',
            [(int) $parametros['id']]
        );

        if (!$charla) {
            Respuesta::error(404, 'Exposición no encontrada', 'Puede que se haya retirado de la agenda.');
        }

        Respuesta::vista('publico/charla', [
            'titulo'   => (string) $charla['titulo'],
            'pantalla' => 'agenda',
            'charla'   => $charla,
        ]);
    }
}
