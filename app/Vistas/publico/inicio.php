<?php
/**
 * Portada.
 * @var array $jornadas @var array|null $evento @var array|null $persona
 * @var bool $empezado @var bool $esHoy
 * @var string $registro         abierto, oculto o cerrado (Configuración → Registro)
 * @var string $mensajeRegistro  lo que la organización dice mientras no está abierto
 * @var array  $faltantes        lo que le falta al perfil de quien tiene sesión
 */
defined('EVENTOS_TIC') || exit;

$total = count($jornadas);
$abierto = $registro === 'abierto';
$cuandoEntra = $total > 1 ? 'los ' . e((string) $total) . ' días' : 'el día del evento';

// El texto del botón principal depende de si el evento ya llegó. Mientras
// faltan días la acción es preregistrarse; el día de la jornada, registrarse.
$rotuloAlta = $empezado ? 'REGISTRARME' : 'Preregistrarme';

// Con el registro abierto, la portada lo ofrece. Oculto o cerrado, ya no: le
// habla a quien se registró, para que complete su perfil y lleve el carnet.
$kicker = match (true) {
    $registro === 'cerrado' && $persona === null => 'Registro cerrado',
    $empezado               => 'Evento en curso',
    $abierto                => 'Fase 01 · Registro',
    default                 => 'Ingreso al evento',
};
$entrada = 'lleva tu carnet ' . ($total > 1 ? 'cada día del evento' : 'el día del evento')
    . ' —impreso o en el celular—: con él ingresas';
$lead = match (true) {
    $abierto && $empezado => 'Si aún no te has registrado, hazlo aquí mismo y en un minuto tienes tu carnet '
        . 'con el código QR. Si ya lo hiciste, entra y tenlo a mano: con él ingresas.',
    $abierto => 'Diligencia tu perfil completo antes del evento, con tu foto, y ' . $entrada
        . ', sin filas ni digitación manual.',
    // Quien ya entró no necesita que le digan que entre.
    $persona !== null => 'Completa tu perfil —tus datos y tu foto— y ' . $entrada . '.',
    default => ($registro === 'cerrado' ? 'El registro ya no recibe inscripciones nuevas. ' : '')
        . 'Si te registraste, entra con tu correo, completa tu perfil —tus datos y tu foto— y ' . $entrada . '.',
};
$pasos = $abierto ? [
    ['01', 'Regístrate y completa tu perfil', 'Tus datos y tu foto: así sale tu carnet. La caracterización es opcional.'],
    ['02', 'Recibe tu carnet', 'Llega por correo con un QR personal. Puedes imprimirlo o llevarlo en el celular.'],
    ['03', 'Llévalo al evento, cada día', 'Es tu entrada: con su código se registra tu ingreso con fecha y hora.'],
    ['04', 'Intercambia contactos', 'El QR de tu carnet comparte nombre, entidad, correo y teléfono con quien lo escanee.'],
] : [
    ['01', 'Entra con tu correo', 'El mismo con el que te registraste: ahí está tu carnet.'],
    ['02', 'Completa tu perfil', 'Revisa tus datos y sube tu foto: así sale tu carnet y así te reconocen en la entrada.'],
    ['03', 'Lleva tu carnet, cada día', 'Impreso o en el celular. Es tu entrada: con su código se registra tu ingreso.'],
    ['04', 'Intercambia contactos', 'El QR de tu carnet comparte nombre, entidad, correo y teléfono con quien lo escanee.'],
];
?>
<div class="view view--medium stack stack--6">

  <div class="split--balanced" style="align-items:center">
    <div class="stack stack--5" data-registro="<?= e($registro) ?>">
      <span class="kicker"><?= e($kicker) ?></span>
      <?php if ($abierto): ?>
        <h1 class="hero-title">Regístrate una vez.<br><em>Entra <?= $cuandoEntra ?>.</em></h1>
      <?php else: ?>
        <h1 class="hero-title">Lleva tu carnet.<br><em>Con él entras <?= $total > 1 ? $cuandoEntra : 'al evento' ?>.</em></h1>
      <?php endif; ?>
      <p class="lead"><?= e($lead) ?></p>

      <?php if ($mensajeRegistro !== ''): ?>
        <!-- Lo que escribió la organización en Configuración → Registro. -->
        <div class="notice notice--warn" data-mensaje-registro>
          <span class="notice__icon" aria-hidden="true">▲</span>
          <span><?= nl2br(e($mensajeRegistro)) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($persona !== null && !\App\Modelos\Persona::registroCompleto($persona)): ?>
        <?php /* Creó su acceso y todavía no llenó nada: no tiene carnet que
                 enseñar, así que la tarjeta dice lo único que falta. */ ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>Te falta un paso</span></div>
          <div class="card__body stack stack--3">
            <p class="help" style="margin:0">
              Tu acceso ya está creado. Completa tu perfil y te emitimos el carnet con el código
              QR: lo necesitas para ingresar al evento.
            </p>
            <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/registro')) ?>">
              Completar mi perfil
            </a>
          </div>
        </div>
      <?php elseif ($persona !== null): ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>Ya estás registrado</span></div>
          <div class="card__body stack stack--3">
            <p class="help" style="margin:0">
              Tu carnet está listo, <?= e($persona['nombre']) ?>. Llévalo el día del evento
              —impreso o en el celular—: con él ingresas.
            </p>
            <?php if ($faltantes): ?>
              <a class="notice" href="<?= e(u('/registro')) ?>" style="text-decoration:none;color:inherit" data-faltantes>
                <span class="notice__icon" aria-hidden="true">◆</span>
                <span>Para tener tu perfil completo te falta <?= e(enumerar($faltantes)) ?>. Completar mi perfil ›</span>
              </a>
            <?php endif; ?>
            <div class="stack stack--2">
              <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/carnet')) ?>">Ver mi carnet</a>
              <a class="btn btn--block<?= $esHoy ? ' btn--lg' : '' ?>" href="<?= e(u('/checkin')) ?>">
                <?= $esHoy ? 'Registrar mi ingreso de hoy' : 'Registrar ingreso' ?>
              </a>
            </div>
          </div>
        </div>
      <?php elseif (!$abierto): ?>
        <?php /* Sin sesión, con el registro oculto o cerrado: ya no se ofrece
                 inscribirse. Lo que queda es entrar, completar y llevar el carnet. */ ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>¿Ya te registraste?</span></div>
          <div class="card__body stack stack--4">
            <p class="help" style="margin:0">
              Entra con tu correo para ver tu carnet y completar tu perfil.
              <?= $registro === 'cerrado' ? 'El registro de asistentes nuevos está cerrado.' : '' ?>
            </p>
            <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/entrar')) ?>">Entrar y ver mi carnet</a>
            <p class="help" style="margin:0">
              <a href="<?= e(u('/admin/entrar')) ?>" class="mono"
                 style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Soy del equipo organizador ›</a>
            </p>
          </div>
        </div>
      <?php else: ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>Registro</span></div>
          <div class="card__body stack stack--4">
            <form method="get" action="<?= e(u('/registro')) ?>" class="stack stack--3">
              <div class="field">
                <label class="label" for="correo">Correo electrónico</label>
                <input class="input" type="email" id="correo" name="correo"
                       placeholder="nombre@entidad.gov.co" autocomplete="email" inputmode="email" required>
              </div>
              <button class="btn btn--primary btn--block btn--lg" type="submit"><?= e($rotuloAlta) ?></button>
            </form>

            <!-- El ingreso de quien ya se registró tenía el mismo peso visual
                 que una nota al pie: un enlace en medio de un párrafo de
                 ayuda. Es la mitad de la gente que llega a esta pantalla, así
                 que va como botón, igual que el de arriba. -->
            <div class="stack stack--2">
              <hr class="divider">
              <p class="help" style="margin:0">¿Ya te registraste antes?</p>
              <a class="btn btn--block btn--lg" href="<?= e(u('/entrar')) ?>">Entrar y ver mi carnet</a>
            </div>

            <p class="help" style="margin:0">
              <a href="<?= e(u('/admin/entrar')) ?>" class="mono"
                 style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Soy del equipo organizador ›</a>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <div class="notice">
        <span class="notice__icon" aria-hidden="true">◆</span>
        <span>
          Tus datos se tratan conforme a la Ley 1581 de 2012. La caracterización es
          opcional y no viaja en el código QR del carnet.
        </span>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><span>Secuencia de acceso</span></div>
      <div class="card__body--tight">
        <div class="steps">
          <?php foreach ($pasos as [$n, $t, $d]): ?>
            <div class="steps__item">
              <span class="steps__n"><?= e($n) ?></span>
              <div class="stack" style="gap:5px">
                <strong class="steps__t"><?= e($t) ?></strong>
                <span class="steps__d"><?= e($d) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($jornadas): ?>
    <div class="card">
      <div class="card__head">
        <span>Jornadas</span>
        <span class="muted"><?= e($evento['sede'] ?? '') ?></span>
      </div>
      <div class="card__body grid-3">
        <?php foreach ($jornadas as $j):
          $esHoy = $j['fecha'] === date('Y-m-d');
          $pasada = $j['fecha'] < date('Y-m-d');
        ?>
          <div class="stack" style="gap:6px">
            <span class="kpi__label">Día <?= e((string) $j['numero']) ?></span>
            <strong style="font-family:var(--f-display);font-size:20px;color:var(--c-title)"><?= e(fecha((string) $j['fecha'])) ?></strong>
            <span class="tag <?= $esHoy ? '' : 'tag--mute' ?>">
              <?= $esHoy ? 'Jornada de hoy' : ($pasada ? 'Finalizada' : 'Programada') ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>
