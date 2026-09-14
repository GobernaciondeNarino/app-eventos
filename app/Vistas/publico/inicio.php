<?php
/**
 * Portada.
 * @var array $jornadas @var array|null $evento @var array|null $persona
 * @var bool $empezado @var bool $esHoy
 */
defined('EVENTOS_TIC') || exit;

$total = count($jornadas);

// El texto del botón principal depende de si el evento ya llegó. Mientras
// faltan días la acción es preregistrarse; el día de la jornada, registrarse.
$rotuloAlta = $empezado ? 'REGISTRARME' : 'Preregistrarme';
?>
<div class="view view--medium stack stack--6">

  <div class="split--balanced" style="align-items:center">
    <div class="stack stack--5">
      <span class="kicker"><?= $empezado ? 'Evento en curso' : 'Fase 01 · Registro' ?></span>
      <h1 class="hero-title">Regístrate una vez.<br><em>Entra <?= $total > 1 ? 'los ' . e((string) $total) . ' días' : 'el día del evento' ?>.</em></h1>
      <p class="lead">
        <?= $empezado
          ? 'Si aún no te has registrado, hazlo aquí mismo y en un minuto tienes tu carnet '
            . 'con el código QR. Si ya lo hiciste, entra y recupéralo.'
          : 'Diligencia tus datos antes del evento y el día de la jornada solo escaneas el '
            . 'código de la entrada. Sin filas, sin digitación manual.' ?>
      </p>

      <?php if ($persona !== null && !\App\Modelos\Persona::registroCompleto($persona)): ?>
        <?php /* Creó su acceso y todavía no llenó nada: no tiene carnet que
                 enseñar, así que la tarjeta dice lo único que falta. */ ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>Te falta un paso</span></div>
          <div class="card__body stack stack--3">
            <p class="help" style="margin:0">
              Tu acceso ya está creado. Con tu nombre y tu identificación te emitimos el
              carnet con el código QR.
            </p>
            <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/registro')) ?>">
              Completar mi registro
            </a>
          </div>
        </div>
      <?php elseif ($persona !== null): ?>
        <div class="card" style="max-width:440px">
          <div class="card__head"><span>Ya estás registrado</span></div>
          <div class="card__body stack stack--3">
            <p class="help">Tu carnet está listo, <?= e($persona['nombre']) ?>.</p>
            <div class="stack stack--2">
              <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/carnet')) ?>">Ver mi carnet</a>
              <a class="btn btn--block<?= $esHoy ? ' btn--lg' : '' ?>" href="<?= e(u('/checkin')) ?>">
                <?= $esHoy ? 'Registrar mi ingreso de hoy' : 'Registrar ingreso' ?>
              </a>
            </div>
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
          <?php foreach ([
            ['01', 'Regístrate en línea', 'Nombre e identificación son los únicos campos obligatorios; la caracterización es opcional.'],
            ['02', 'Recibe tu carnet', 'Llega por correo con un QR personal. No hace falta imprimirlo.'],
            ['03', 'Escanea al entrar, cada día', 'El código de la entrada cambia por jornada y sella tu ingreso con fecha y hora.'],
            ['04', 'Intercambia contactos', 'El QR de tu carnet comparte nombre, entidad, correo y teléfono con quien lo escanee.'],
          ] as [$n, $t, $d]): ?>
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
