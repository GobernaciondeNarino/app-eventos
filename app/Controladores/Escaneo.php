<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Asistencia;
use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Url;

/**
 * Lo que pasa cuando alguien apunta la cámara a un código.
 *
 * Estas rutas se abren desde el lector de QR del teléfono, sin ningún contexto:
 * ni pestaña previa, ni menú, ni nadie explicando qué hacer. Están escritas
 * para ese momento —alguien de pie en la puerta del recinto, con fila detrás—,
 * así que cada camino termina o en una acción hecha o en una instrucción clara.
 *
 * Hay dos códigos distintos:
 *
 *   /d/{token}  el pliego pegado en la entrada, uno por jornada.
 *               Registra el ingreso de quien lo escanea.
 *
 *   /c/{token}  el carnet de una persona.
 *               Si lo escanea el equipo, sella la asistencia de esa persona.
 *               Si lo escanea otro asistente, intercambian contacto.
 *               Si no hay sesión, se pregunta quién es y se lleva al acceso
 *               que corresponde, para volver aquí y continuar.
 */
final class Escaneo
{
    /**
     * A quién se le cuentan los tokens equivocados.
     *
     * A la persona identificada, si la hay; y solo a la dirección cuando no se
     * sabe quién es. Contarlo siempre por dirección es un problema serio en la
     * puerta: toda la sede sale a internet por una sola, así que treinta
     * escaneos fallidos de un curioso dejaban a cientos de asistentes sin poder
     * registrar su ingreso durante un cuarto de hora.
     *
     * El riesgo de que alguien adivine un token no cambia por esto: son 128
     * bits al azar.
     */
    private function claveDelLimite(Peticion $peticion): string
    {
        $persona = Guardia::personaActual();
        if ($persona !== null) {
            return 'persona:' . $persona['id'];
        }
        $usuario = Guardia::usuarioActual();
        if ($usuario !== null) {
            return 'usuario:' . $usuario['id'];
        }
        return 'ip:' . $peticion->ip();
    }

    /* =====================================================================
       Código de la jornada
       ===================================================================== */

    public function codigoDelDia(Peticion $peticion, array $parametros): void
    {
        // El guardia 'asistente' ya se encargó: si no había sesión, la persona
        // pasó por el acceso y volvió aquí. Desde este punto siempre hay alguien.
        $persona = Guardia::personaActual();
        $token = (string) $parametros['token'];

        $clave = $this->claveDelLimite($peticion);
        Limite::exigir('token_qr', $clave);

        $jornada = Evento::jornadaPorToken($token);
        if (!$jornada) {
            Limite::registrarFallo('token_qr', $clave);
            Bitacora::registrar('token_dia_invalido', 'seguridad', null);
            Respuesta::vista('publico/escaneo-resultado', [
                'titulo'   => 'Código no reconocido',
                'pantalla' => 'checkin',
                'estado'   => 'error',
                'encabezado' => 'Este código ya no sirve',
                'mensaje'  => 'Puede que lo hayan regenerado por seguridad. Pide al equipo del evento '
                              . 'el pliego actualizado de la entrada.',
            ], 404);
        }

        // El código es de otro evento distinto al que la persona pertenece.
        if ((int) $jornada['evento_id'] !== (int) $persona['evento_id']) {
            Respuesta::vista('publico/escaneo-resultado', [
                'titulo'   => 'Código de otro evento',
                'pantalla' => 'checkin',
                'estado'   => 'error',
                'encabezado' => 'Ese código es de otro evento',
                'mensaje'  => 'Tu registro corresponde a un evento distinto. Verifica que estés en la entrada correcta.',
            ], 403);
        }

        [$abierta, $motivo] = Evento::jornadaAbierta($jornada);
        if (!$abierta) {
            Respuesta::vista('publico/escaneo-resultado', [
                'titulo'   => 'Fuera de horario',
                'pantalla' => 'checkin',
                'estado'   => 'aviso',
                'encabezado' => 'Todavía no se puede registrar',
                'mensaje'  => $motivo,
                'historial' => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
            ]);
        }

        $resultado = Asistencia::sellar((int) $persona['id'], $jornada, 'qr_dia');

        Respuesta::vista('publico/escaneo-resultado', [
            'titulo'     => 'Ingreso registrado',
            'pantalla'   => 'checkin',
            'estado'     => $resultado['repetida'] ? 'aviso' : 'ok',
            'encabezado' => $resultado['repetida'] ? 'Ya tenías el ingreso de hoy' : 'Ingreso registrado',
            'mensaje'    => $resultado['repetida']
                ? 'Quedó registrado a las ' . hora($resultado['cuando']) . '. No hace falta escanear de nuevo.'
                : 'Día ' . $jornada['numero'] . ' · ' . fecha((string) $jornada['fecha']) . ' · ' . hora($resultado['cuando']),
            'persona'    => $persona,
            'historial'  => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
        ]);
    }

    /* =====================================================================
       Carnet de otra persona
       ===================================================================== */

    public function carnetAjeno(Peticion $peticion, array $parametros): void
    {
        $token = (string) $parametros['token'];
        $clave = $this->claveDelLimite($peticion);
        Limite::exigir('token_qr', $clave);

        $credencial = Credencial::porToken($token);
        if (!$credencial) {
            Limite::registrarFallo('token_qr', $clave);
            Respuesta::vista('publico/escaneo-resultado', [
                'titulo'     => 'Credencial no reconocida',
                'pantalla'   => '',
                'estado'     => 'error',
                'encabezado' => 'Esta credencial no existe',
                'mensaje'    => 'El carnet pudo haber sido revocado o reemitido. Pide a la persona que muestre el suyo actualizado.',
            ], 404);
        }

        // acreditador() y no usuarioActual(): esta ruta no lleva guardia —la
        // abre la cámara de un teléfono sin contexto— y decide por su cuenta.
        // Con usuarioActual() entraban cuentas suspendidas y cuentas con el
        // segundo factor a medio hacer, y lo que hay al otro lado es la cédula
        // de una persona. Devuelve también al Staff, que acredita en la puerta
        // con su propio acceso de asistente.
        $acreditador = Guardia::acreditador();
        $yo = Guardia::personaActual();

        // --- Lo escanea quien acredita: sellar el ingreso -------------------
        if ($acreditador !== null) {
            $this->pantallaAcreditacion($credencial, $acreditador);
        }

        // --- Otro asistente lo escanea: intercambio de contacto -------------
        if ($yo !== null) {
            if ((int) $yo['id'] === (int) $credencial['persona_id']) {
                Respuesta::redirigir('/carnet', 'Ese es tu propio carnet.');
            }
            // La plataforma es multievento. Sin esta comprobación, el carnet
            // de otro evento —fotografiado, que es justo lo que este módulo da
            // por hecho que pasa— enseñaba los datos de contacto de alguien que
            // no comparte evento con quien mira, y guardarContacto() sí lo
            // rechazaba: se veía lo que después no se podía hacer.
            if ((int) $credencial['evento_id'] !== (int) $yo['evento_id']) {
                Respuesta::vista('publico/escaneo-resultado', [
                    'titulo'     => 'Credencial de otro evento',
                    'pantalla'   => '',
                    'estado'     => 'aviso',
                    'encabezado' => 'Ese carnet es de otro evento',
                    'mensaje'    => 'Solo se pueden intercambiar contactos entre asistentes del mismo evento.',
                ], 403);
            }
            $this->pantallaContacto($credencial, $yo);
        }

        // --- Nadie identificado: se pregunta quién es -----------------------
        // No se muestra de quién es el carnet: eso convertiría cualquier
        // credencial fotografiada en una consulta de datos personales.
        $evento = App::eventoActivo();
        Respuesta::vista('publico/quien-eres', [
            'titulo'   => 'Identifícate para continuar',
            'pantalla' => '',
            'evento'   => $evento,
            'destino'  => '/c/' . $token,
        ]);
    }

    /** Vista de quien acredita: los datos de la persona y el botón de sellar. */
    private function pantallaAcreditacion(array $credencial, array $acreditador): never
    {
        // Ver una ficha es leer datos personales de alguien, aunque no se selle
        // nada: queda registrado igual que el sellado.
        Bitacora::registrar('credencial_consultada', 'persona', (int) $credencial['persona_id'], [
            'miró' => $acreditador['tipo'],
        ]);

        $evento = App::eventoActivo();
        $jornada = $evento ? Evento::jornadaDeHoy((int) $evento['id']) : null;
        $yaTiene = $jornada ? Asistencia::de((int) $credencial['persona_id'], (int) $jornada['id']) : null;

        Respuesta::vista('admin/acreditar', [
            'titulo'      => 'Acreditar asistente',
            'pantalla'    => 'acreditar',
            'credencial'  => $credencial,
            'documento'   => Persona::documento($credencial),
            'jornada'     => $jornada,
            'yaTiene'     => $yaTiene,
            'historial'   => Asistencia::historial((int) $credencial['persona_id'], (int) $credencial['evento_id']),
            'acreditador' => $acreditador,
            'escaneosHoy' => Asistencia::escaneosDeHoy($acreditador['id'], $acreditador['tipo']),
        ]);
    }

    /** Vista del asistente: confirmar el intercambio de contacto. */
    private function pantallaContacto(array $credencial, array $yo): never
    {
        $yaEs = Bd::fila(
            'SELECT * FROM {contacto} WHERE persona_id = ? AND contacto_id = ? AND revocado_en IS NULL',
            [$yo['id'], $credencial['persona_id']]
        );

        Respuesta::vista('publico/intercambiar', [
            'titulo'     => 'Intercambiar contacto',
            'pantalla'   => 'contactos',
            'otra'       => Credencial::datosDeContacto($credencial),
            'token'      => (string) $credencial['token'],
            'yaEs'       => (bool) $yaEs,
        ]);
    }

    /* =====================================================================
       Acciones
       ===================================================================== */

    /** El asistente confirma el intercambio: se guarda en las dos direcciones. */
    public function guardarContacto(Peticion $peticion, array $parametros): void
    {
        $yo = Guardia::personaActual();
        $credencial = Credencial::porToken((string) $parametros['token']);

        if (!$credencial || (int) $credencial['persona_id'] === (int) $yo['id']) {
            Respuesta::redirigir('/contactos', 'No se pudo agregar ese contacto.', 'warn');
        }
        if ((int) $credencial['evento_id'] !== (int) $yo['evento_id']) {
            Respuesta::redirigir('/contactos', 'Esa credencial es de otro evento.', 'warn');
        }

        Limite::exigir('contacto', (string) $yo['id']);
        Limite::registrar('contacto', (string) $yo['id']);

        // El intercambio es recíproco: quien escanea también queda en la lista
        // del escaneado. Es lo que la gente espera al intercambiar tarjetas, y
        // evita que alguien recolecte contactos sin dejar el suyo.
        Bd::transaccion(static function () use ($yo, $credencial): void {
            foreach ([[$yo['id'], $credencial['persona_id']], [$credencial['persona_id'], $yo['id']]] as [$a, $b]) {
                Bd::ejecutar(
                    'INSERT INTO {contacto} (persona_id, contacto_id) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE revocado_en = NULL',
                    [$a, $b]
                );
            }
        });

        Bitacora::registrar('contacto_creado', 'persona', (int) $yo['id'], [
            'con' => (int) $credencial['persona_id'],
        ]);

        Respuesta::redirigir('/contactos', 'Contacto agregado: ' . $credencial['nombre']);
    }

    /** Quien acredita confirma el ingreso. */
    public function sellarAsistencia(Peticion $peticion, array $parametros): void
    {
        // El guardia de la ruta ya dejó pasar solo a quien puede, pero se vuelve
        // a preguntar quién es: de aquí sale la firma del sello, y tomarla de
        // usuarioActual() dejaba los de un Staff sin autor.
        $acreditador = Guardia::acreditador();
        $vuelta = $acreditador !== null && $acreditador['tipo'] === 'staff' ? '/acreditar' : '/admin/escaner';

        $credencial = Credencial::porToken((string) $parametros['token']);
        if (!$credencial) {
            Respuesta::redirigir($vuelta, 'Credencial no reconocida.', 'warn');
        }

        // Aquí no vale redirigir a crear el evento: quien pulsa este botón está
        // de pie en la puerta con alguien esperando. Se vuelve al escáner con el
        // motivo, que es la pantalla desde la que vino.
        $evento = App::eventoActivo();
        if (!$evento) {
            Respuesta::redirigir($vuelta,
                'No hay ningún evento activo, así que no se puede registrar el ingreso.', 'warn');
        }

        // El día lo pone el calendario, no el formulario. Aceptar el número que
        // llegara en el envío permitía sellar el ingreso de una jornada que aún
        // no ha ocurrido, o de una que ya pasó, y esos registros son la base de
        // los reportes de asistencia del evento.
        $jornada = Evento::jornadaDeHoy((int) $evento['id']);

        if (!$jornada) {
            Respuesta::redirigir($vuelta,
                'Hoy no hay ninguna jornada programada para este evento.', 'warn');
        }
        if ((int) $credencial['evento_id'] !== (int) $evento['id']) {
            Respuesta::redirigir($vuelta, 'Esa credencial pertenece a otro evento.', 'warn');
        }

        $resultado = Asistencia::sellar(
            (int) $credencial['persona_id'],
            $jornada,
            'carnet_operador',
            $acreditador['id'] ?? null,
            (string) ($acreditador['tipo'] ?? 'equipo')
        );

        $mensaje = $resultado['repetida']
            ? $credencial['nombre'] . ' ya tenía ingreso del día ' . $jornada['numero']
                . ' (' . hora($resultado['cuando']) . ').'
            : 'Ingreso de ' . $credencial['nombre'] . ' registrado · día ' . $jornada['numero'] . '.';

        Respuesta::redirigir($vuelta, $mensaje, $resultado['repetida'] ? 'warn' : 'ok');
    }

    /* =====================================================================
       Pantalla de la puerta
       -------------------------------------------------------------------------
       La abren el equipo desde /admin/escaner y el Staff desde /acreditar. Es
       la misma, y por eso vive aquí y no en Admin: dejó de ser del backoffice
       el día que una persona con perfil Staff pudo usarla.
       ===================================================================== */

    public function pantallaAcreditar(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $acreditador = Guardia::acreditador();

        Respuesta::vista('admin/escaner', [
            'titulo'      => 'Acreditar asistente',
            'pantalla'    => 'acreditar',
            'jornadas'    => Evento::jornadas((int) $evento['id']),
            'jornadaHoy'  => Evento::jornadaDeHoy((int) $evento['id']),
            'acreditador' => $acreditador,
            'escaneosHoy' => $acreditador
                ? Asistencia::escaneosDeHoy($acreditador['id'], $acreditador['tipo'])
                : 0,
        ]);
    }

    /**
     * Búsqueda manual, para cuando el carnet no se puede leer.
     *
     * Es la salida cuando alguien perdió el teléfono, el código está rayado o
     * simplemente llegó sin nada: se busca por nombre, correo o **número de
     * identificación**. Devuelve pocos resultados y queda registrada: es una
     * consulta de datos personales hecha a mano.
     */
    public function buscar(Peticion $peticion): void
    {
        $evento = App::eventoExigido();
        $acreditador = Guardia::acreditador();
        $vuelta = $acreditador !== null && $acreditador['tipo'] === 'staff' ? '/acreditar' : '/admin/escaner';
        $texto = $peticion->campo('q');

        if (mb_strlen($texto) < 3) {
            Respuesta::redirigir($vuelta, 'Escribe al menos tres caracteres para buscar.', 'warn');
        }

        $encontradas = Persona::buscar((int) $evento['id'], ['texto' => $texto, 'limite' => 10]);
        Bitacora::registrar('busqueda_manual', 'persona', null, [
            'resultados' => count($encontradas),
            'buscó'      => $acreditador['tipo'] ?? 'equipo',
        ]);

        // Digitar el documento completo lleva directo a la ficha de
        // acreditación, que es donde deja el QR. Para eso está: quien llega sin
        // carnet y sin teléfono tiene que poder acreditarse en los mismos pasos
        // que quien lo trae, y no en uno más por el camino.
        //
        // Solo cuando el documento coincide exacto y es una sola persona: un
        // nombre con varias coincidencias sigue mostrando la lista, porque ahí
        // la elección es de quien está en la puerta.
        $porDocumento = Persona::porDocumento((int) $evento['id'], $texto);
        if ($porDocumento !== null && count($encontradas) === 1
            && (int) $encontradas[0]['id'] === (int) $porDocumento['id']) {
            $credencial = Credencial::asegurar((int) $porDocumento['id']);
            Respuesta::redirigir('/c/' . $credencial['token']);
        }

        Respuesta::vista('admin/escaner', [
            'titulo'      => 'Acreditar asistente',
            'pantalla'    => 'acreditar',
            'jornadas'    => Evento::jornadas((int) $evento['id']),
            'jornadaHoy'  => Evento::jornadaDeHoy((int) $evento['id']),
            'acreditador' => $acreditador,
            'escaneosHoy' => $acreditador
                ? Asistencia::escaneosDeHoy($acreditador['id'], $acreditador['tipo'])
                : 0,
            'busqueda'    => $texto,
            'encontradas' => $encontradas,
        ]);
    }

    /* =====================================================================
       Pantalla de check-in del asistente
       ===================================================================== */

    public function pantallaCheckin(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $evento = App::eventoActivo();
        $jornadaHoy = $evento ? Evento::jornadaDeHoy((int) $evento['id']) : null;

        Respuesta::vista('publico/checkin', [
            'titulo'     => 'Registrar ingreso',
            'pantalla'   => 'checkin',
            'historial'  => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
            'jornadaHoy' => $jornadaHoy,
            'yaIngreso'  => $jornadaHoy
                ? Asistencia::de((int) $persona['id'], (int) $jornadaHoy['id'])
                : null,
        ]);
    }
}
