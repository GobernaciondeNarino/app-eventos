<?php
/**
 * Códigos QR por jornada.
 * @var array $jornadas @var bool $puedeRotar @var bool $puedeEditarDias
 * @var string $siguienteFecha @var bool $seguidas @var string $paraSeguirlas
 *
 * Cada formulario manda el número del día y el id de la jornada: al eliminar
 * un día los siguientes se renumeran, y el servidor no toca nada si el número
 * cambió de dueño desde que se abrió esta pantalla.
 */
defined('EVENTOS_TIC') || exit;

$hoy = date('Y-m-d');
?>
<div class="view view--medium stack stack--4">

  <div class="stack stack--2">
    <span class="kicker">Administrador</span>
    <h1>Códigos QR por día</h1>
    <p class="lead" style="max-width:64ch">
      El sistema genera un código distinto para cada jornada. Se imprime y se ubica en la
      entrada; cada escaneo queda sellado con la fecha y la hora del día correspondiente.
    </p>
  </div>

  <?php if (!$seguidas && $puedeEditarDias): ?>
    <!-- Huecos de una eliminación anterior a la 3.7.1, que no renumeraba. -->
    <div class="notice notice--warn" role="status">
      <span class="notice__icon" aria-hidden="true">i</span>
      <div class="stack stack--2" style="flex:1;min-width:0">
        <span>
          Los números de los días no van seguidos
          (<?= e(implode(', ', array_map(static fn(array $j): string => (string) $j['numero'], $jornadas))) ?>):
          quedaron así de un día que se eliminó antes de esta versión. Ahora, al eliminar un día,
          los siguientes se renumeran solos.
        </span>
        <form method="post" action="<?= e(u('/admin/qr-dias/renumerar')) ?>"
              data-confirmar="Se renumeran los días: <?= e($paraSeguirlas) ?>. Sus códigos QR no cambian. ¿Continuar?">
          <?= testigo() ?>
          <button class="btn btn--sm" type="submit">Dejarlos seguidos</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid-3">
    <?php foreach ($jornadas as $j):
      $esHoy = $j['fecha'] === $hoy;
      $pasada = $j['fecha'] < $hoy; ?>
      <div class="card stack" style="gap:0">
        <div class="card__head" style="color:inherit">
          <div class="stack" style="gap:2px">
            <strong style="font-family:var(--f-display);font-size:17px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--c-title)">
              Día <?= e((string) $j['numero']) ?>
            </strong>
            <span class="mono muted" style="font-size:10.5px;letter-spacing:normal;text-transform:none"><?= e(fecha((string) $j['fecha'])) ?></span>
          </div>
          <span class="tag <?= $esHoy ? '' : 'tag--mute' ?>">
            <?= $esHoy ? 'Activo' : ($pasada ? 'Finalizado' : 'Programado') ?>
          </span>
        </div>

        <div class="card__body stack stack--3">
          <div class="qr-frame"><?= $j['qr'] ?></div>

          <div class="stack" style="gap:7px">
            <span class="mono muted" style="font-size:10px;letter-spacing:.06em;word-break:break-all">
              <?= e($j['url']) ?>
            </span>
            <div class="row row--between mono" style="font-size:12px">
              <span class="muted" style="letter-spacing:.1em;text-transform:uppercase">Ingresos</span>
              <strong class="accent" style="font-weight:500"><?= e(numero($j['ingresos'])) ?></strong>
            </div>
            <?php if ($j['token_rotado_en']): ?>
              <span class="mono muted" style="font-size:10.5px">
                Regenerado el <?= e(fecha((string) $j['token_rotado_en'])) ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="row" style="flex-wrap:nowrap">
            <a class="btn btn--sm" style="flex:1" target="_blank" rel="noopener"
               href="<?= e(u('/admin/qr-dias/' . (int) $j['numero'] . '/imprimir')) ?>">Imprimir</a>
            <?php if ($puedeRotar): ?>
              <button class="btn btn--sm btn--primary" style="flex:1" type="button"
                      data-abrir-modal="modal-rotar-<?= (int) $j['numero'] ?>">Regenerar</button>
            <?php endif; ?>
          </div>

          <?php if ($puedeEditarDias): ?>
            <details class="stack" style="gap:10px">
              <summary class="help" style="cursor:pointer">Cambiar fecha u horario</summary>

              <form method="post" action="<?= e(u('/admin/qr-dias/ajustar')) ?>" class="stack stack--3">
                <?= testigo() ?>
                <input type="hidden" name="numero" value="<?= e((string) $j['numero']) ?>">
                <input type="hidden" name="jornada" value="<?= (int) $j['id'] ?>">
                <div class="field">
                  <label class="label" for="fecha-<?= (int) $j['numero'] ?>">Fecha</label>
                  <input class="input" type="date" id="fecha-<?= (int) $j['numero'] ?>"
                         name="fecha" value="<?= e((string) $j['fecha']) ?>" required>
                </div>
                <div class="grid-2">
                  <div class="field">
                    <label class="label" for="abre-<?= (int) $j['numero'] ?>">Abre</label>
                    <input class="input" type="time" id="abre-<?= (int) $j['numero'] ?>"
                           name="abre_a" value="<?= e(substr((string) $j['abre_a'], 0, 5)) ?>">
                  </div>
                  <div class="field">
                    <label class="label" for="cierra-<?= (int) $j['numero'] ?>">Cierra</label>
                    <input class="input" type="time" id="cierra-<?= (int) $j['numero'] ?>"
                           name="cierra_a" value="<?= e(substr((string) $j['cierra_a'], 0, 5)) ?>">
                  </div>
                </div>
                <button class="btn btn--sm" type="submit">Guardar el día <?= e((string) $j['numero']) ?></button>
              </form>

              <?php if ((int) $j['ingresos'] === 0): ?>
                <form method="post" action="<?= e(u('/admin/qr-dias/eliminar')) ?>"
                      data-confirmar="Se eliminará el día <?= e((string) $j['numero']) ?> y su código QR dejará de servir.<?= $j['al_eliminar'] !== '' ? e(' Los siguientes se renumeran: ' . $j['al_eliminar'] . '; sus códigos no cambian.') : '' ?> ¿Continuar?">
                  <?= testigo() ?>
                  <input type="hidden" name="numero" value="<?= e((string) $j['numero']) ?>">
                  <input type="hidden" name="jornada" value="<?= (int) $j['id'] ?>">
                  <button class="btn btn--sm btn--danger btn--block" type="submit">Eliminar este día</button>
                </form>
              <?php else: ?>
                <?php /* Con ingresos sí se puede, pero diciendo cuántos se van
                         con él. Los días de prueba se llenan de escaneos
                         justamente probando, y sin esta salida la única forma
                         de limpiar era entrar a la base a mano. */ ?>
                <form method="post" action="<?= e(u('/admin/qr-dias/eliminar')) ?>"
                      data-confirmar="El día <?= e((string) $j['numero']) ?> tiene <?= e(numero($j['ingresos'])) ?> ingreso(s) registrado(s). Se eliminarán con él y no hay deshacer.<?= $j['al_eliminar'] !== '' ? e(' Los siguientes se renumeran: ' . $j['al_eliminar'] . '; sus códigos no cambian.') : '' ?> ¿Continuar?">
                  <?= testigo() ?>
                  <input type="hidden" name="numero" value="<?= e((string) $j['numero']) ?>">
                  <input type="hidden" name="jornada" value="<?= (int) $j['id'] ?>">
                  <input type="hidden" name="forzar" value="1">
                  <p class="help" style="margin:0 0 8px">
                    Tiene <?= e(numero($j['ingresos'])) ?> ingreso<?= (int) $j['ingresos'] === 1 ? '' : 's' ?>
                    registrado<?= (int) $j['ingresos'] === 1 ? '' : 's' ?>. Se irán con el día.
                  </p>
                  <button class="btn btn--sm btn--danger btn--block" type="submit">
                    Eliminar el día y sus ingresos
                  </button>
                </form>
              <?php endif; ?>
            </details>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($puedeEditarDias): ?>
      <!-- Los eventos se alargan. Hasta ahora el número de jornadas se fijaba
           al crear el evento y la única salida era volver a instalarlo. -->
      <form class="card card--dashed stack" style="gap:0" method="post"
            action="<?= e(u('/admin/qr-dias/agregar')) ?>">
        <?= testigo() ?>
        <div class="card__head" style="color:inherit">
          <strong style="font-family:var(--f-display);font-size:17px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--c-title)">
            Agregar un día
          </strong>
        </div>
        <div class="card__body stack stack--3">
          <p class="help" style="margin:0">
            Se crea con su propio código QR. No tiene que ser el día siguiente: un evento
            puede tener dos jornadas seguidas y una de cierre la semana entrante.
          </p>
          <div class="field">
            <label class="label" for="nueva-fecha">Fecha</label>
            <input class="input" type="date" id="nueva-fecha" name="fecha"
                   value="<?= e($siguienteFecha) ?>" required>
          </div>
          <div class="grid-2">
            <div class="field">
              <label class="label" for="nueva-abre">Abre</label>
              <input class="input" type="time" id="nueva-abre" name="abre_a" value="06:00">
            </div>
            <div class="field">
              <label class="label" for="nueva-cierra">Cierra</label>
              <input class="input" type="time" id="nueva-cierra" name="cierra_a" value="22:00">
            </div>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Agregar la jornada</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><span>Cómo funciona la rotación</span></div>
    <div class="card__body">
      <div class="steps">
        <?php foreach ([
          ['01', 'Un código por jornada', 'Cada día tiene su propio identificador. El del día anterior deja de servir apenas cambia la fecha del evento.'],
          ['02', 'Ventana horaria', 'El código solo acepta ingresos dentro del horario de la jornada. Fuera de esa ventana el escaneo se rechaza y queda en la bitácora.'],
          ['03', 'Regeneración manual', 'Si el pliego impreso se filtra antes de tiempo —una foto en redes, por ejemplo— se regenera y el anterior queda inválido de inmediato.'],
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

<?php if ($puedeRotar): foreach ($jornadas as $j): ?>
  <div class="modal hidden" id="modal-rotar-<?= (int) $j['numero'] ?>" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Regenerar código">
      <div class="modal__head">
        <span>Confirmación</span>
        <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
      </div>
      <div class="modal__body">
        <h2 style="font-size:22px">Regenerar el código del día <?= e((string) $j['numero']) ?></h2>
        <p class="help">
          El código actual dejará de funcionar de inmediato. Si ya hay pliegos impresos y
          pegados en la entrada, habrá que reemplazarlos antes de que abra la jornada.
        </p>
        <form method="post" action="<?= e(u('/admin/qr-dias/rotar')) ?>" class="row row--end">
          <?= testigo() ?>
          <input type="hidden" name="numero" value="<?= e((string) $j['numero']) ?>">
          <input type="hidden" name="jornada" value="<?= (int) $j['id'] ?>">
          <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
          <button class="btn btn--primary" type="submit">Regenerar</button>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>
