<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Certificado;
use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Modelos\Formulario;
use App\Modelos\Persona;
use App\Modelos\Recuperacion;

/**
 * El evento de las rutas que llevan un token (1.17.0).
 *
 * El QR del día, el carnet, el acceso por QR, el enlace de una contraseña
 * nueva y el de los expositores no llevan el prefijo /e/<slug>/ —van impresos
 * y en correos, y tienen que seguir sirviendo aunque el evento cambie de
 * dirección—, pero el token ya dice de qué evento son. El enrutador lo pregunta
 * aquí antes del guardia, porque el guardia también depende del evento: la
 * sesión de una persona solo vale en el suyo, y el rol del equipo es el de ese
 * evento.
 *
 * Si el token no es de nadie no se cambia nada, y el controlador responde lo
 * de siempre: que el enlace no es válido.
 */
final class Contexto
{
    public static function resolver(string $tipo, array $parametros): void
    {
        try {
            $eventoId = self::eventoDe($tipo, $parametros);
        } catch (\Throwable $e) {
            // Una tabla que todavía no existe, por ejemplo: la ruta sigue con
            // el evento de la dirección y el controlador decide.
            Registro::excepcion($e);
            return;
        }
        if ($eventoId === null) {
            return;
        }
        $evento = Evento::porId($eventoId);
        if ($evento !== null) {
            App::usarEvento($evento);
        }
    }

    private static function eventoDe(string $tipo, array $p): ?int
    {
        $token = (string) ($p['token'] ?? '');
        $fila = match ($tipo) {
            // El QR de la jornada, pegado en la puerta.
            'dia'          => Evento::jornadaPorToken($token),
            // El carnet de una persona: el equipo sella, otro asistente intercambia.
            'carnet'       => Credencial::porToken($token),
            // El QR personal de acceso, y el enlace de una contraseña nueva.
            'acceso'       => Persona::porTokenDeAcceso($token),
            'recuperacion' => self::personaDeRecuperacion($token),
            // El código de un certificado, en la página pública que lo verifica.
            'certificado'  => self::personaDeCertificado((string) ($p['codigo'] ?? '')),
            // La foto de una persona y los adjuntos de una propuesta.
            'persona'      => Persona::porId((int) ($p['persona'] ?? 0)),
            'propuesta'    => Bd::fila('SELECT p.evento_id FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
                                         WHERE pr.id = ?', [(int) ($p['propuesta'] ?? 0)]),
            // El enlace privado del formulario de expositores.
            'expositores'  => ($id = Formulario::eventoDelToken($token)) === null ? null : ['evento_id' => $id],
            default        => null,
        };
        return isset($fila['evento_id']) ? (int) $fila['evento_id'] : null;
    }

    private static function personaDeRecuperacion(string $token): ?array
    {
        $enlace = Recuperacion::buscar('asistente', $token);
        return $enlace === null ? null : Persona::porId((int) $enlace['sujeto_id']);
    }

    private static function personaDeCertificado(string $codigo): ?array
    {
        if (!Certificado::hayTablas()) {
            return null;
        }
        $emision = Certificado::porCodigo(Certificado::normalizarCodigo($codigo));
        return $emision === null ? null : Persona::porId((int) $emision['persona_id']);
    }
}
