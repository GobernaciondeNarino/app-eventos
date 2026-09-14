<?php
/** Listado de eventos. @var array $eventos @var array $estados */
defined('EVENTOS_TIC') || exit;

$columnas = 'grid-template-columns:1.6fr 1fr .8fr .8fr 1fr 96px';
$etiquetas = [
    'borrador' => ['Borrador', 'tag--mute'],
    'abierto'  => ['Abierto', ''],
    'en_curso' => ['En curso', 'tag--ok'],
    'cerrado'  => ['Cerrado', 'tag--mute'],
];
$nombreEstado = [
    'borrador' => 'Borrador · no se anuncia todavía',
    'abierto'  => 'Abierto · registro en marcha',
    'en_curso' => 'En curso · el evento está pasando',
    'cerrado'  => 'Cerrado · terminó',
];
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Eventos de la Secretaría</h1>
      <p class="help">
        La plataforma sirve a varios eventos a la vez. Cada uno tiene su propia identidad
        visual, sus jornadas, sus códigos y sus registros.
      </p>
    </div>
    <button class="btn btn--sm btn--primary" type="button" data-abrir-modal="modal-evento">Crear evento</button>
  </div>

  <div class="table">
    <div class="table__scroll">
      <div class="table__grid" style="min-width:820px">
        <div class="table__head" style="<?= $columnas ?>">
          <span>Evento</span><span>Inicio</span><span>Jornadas</span>
          <span>Registros</span><span>Estado</span><span class="sr-only">Acciones</span>
        </div>

        <?php foreach ($eventos as $ev):
          [$etiqueta, $clase] = $etiquetas[$ev['estado']] ?? ['—', ''];
          $activo = (int) $ev['activo'] === 1; ?>
          <div class="table__row" style="<?= $columnas ?>">
            <div class="stack" style="gap:2px;min-width:0">
              <strong style="font-weight:500;color:var(--c-title)"><?= e($ev['nombre']) ?></strong>
              <span class="mono muted" style="font-size:11px">
                <?= $activo ? 'Evento activo · visible para los asistentes' : e((string) ($ev['sede'] ?: 'Sin sede definida')) ?>
              </span>
            </div>
            <span class="mono" style="font-size:12.5px;color:var(--c-text)"><?= e(fecha((string) $ev['fecha_inicio'])) ?></span>
            <span style="color:var(--c-text)"><?= e((string) $ev['jornadas']) ?> días</span>
            <span class="mono accent"><?= e(numero($ev['registros'])) ?></span>
            <div class="row" style="gap:6px">
              <span class="tag <?= e($clase) ?>"><?= e($etiqueta) ?></span>
              <?php if ($activo): ?><span class="tag tag--ok">Activo</span><?php endif; ?>
            </div>

            <div class="row" style="gap:4px;flex-wrap:nowrap;justify-content:flex-end">
              <button class="btn btn--icono" type="button" data-abrir-modal="modal-editar-<?= (int) $ev['id'] ?>"
                      title="Editar <?= e($ev['nombre']) ?>" aria-label="Editar <?= e($ev['nombre']) ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 20.5h4l10.5-10.5-4-4L4 16.5zM14.5 6l4 4"></path>
                </svg>
              </button>
              <button class="btn btn--icono btn--danger" type="button"
                      data-abrir-modal="modal-borrar-<?= (int) $ev['id'] ?>"
                      title="Eliminar <?= e($ev['nombre']) ?>" aria-label="Eliminar <?= e($ev['nombre']) ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4.5 6.5h15M9 6.5V4.5h6v2M6.5 6.5l1 13h9l1-13M10 10v6M14 10v6"></path>
                </svg>
              </button>
            </div>
          </div>

          <!-- Activar o desactivar: fuera de la fila, para que la columna de
               acciones no se llene de botones que hacen cosas muy distintas. -->
          <div class="table__row" style="grid-template-columns:1fr;padding-top:0;border-top:0">
            <div class="row" style="gap:8px">
              <?php if (!$activo): ?>
                <form method="post" action="<?= e(u('/admin/eventos/activar')) ?>">
                  <?= testigo() ?>
                  <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">
                  <button class="btn btn--sm" type="submit">Activar este evento</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e(u('/admin/eventos/desactivar')) ?>"
                      data-confirmar="Los asistentes dejarán de ver este evento hasta que actives otro o vuelvas a activar este. ¿Continuar?">
                  <?= testigo() ?>
                  <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">
                  <button class="btn btn--sm" type="submit">Desactivar</button>
                </form>
              <?php endif; ?>
              <span class="help" style="margin:0"><?= e($nombreEstado[$ev['estado']] ?? '') ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table__foot">
      <span><?= e((string) count($eventos)) ?> eventos registrados</span>
      <span>El evento activo es el que ven los asistentes</span>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span>Qué pasa al crear un evento</span></div>
    <div class="card__body grid-3">
      <?php foreach ([
        ['Se crea', ['Sus jornadas, una por día', 'Un código QR distinto por jornada', 'Su identidad visual por defecto']],
        ['Queda aparte', ['Los registros de personas', 'Las asistencias', 'Los contactos intercambiados']],
        ['Se comparte', ['El equipo organizador y sus roles', 'La bitácora de auditoría', 'La configuración del servidor']],
      ] as [$titulo, $items]): ?>
        <div class="stack" style="gap:8px">
          <strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent)">
            <?= e($titulo) ?>
          </strong>
          <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.75;color:var(--c-text)">
            <?php foreach ($items as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ================= Crear ================= -->
<div class="modal hidden" id="modal-evento" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Crear evento">
    <div class="modal__head">
      <span>Nuevo evento</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body">
      <h2 style="font-size:22px">Crear evento</h2>
      <form method="post" action="<?= e(u('/admin/eventos/crear')) ?>" class="stack stack--4">
        <?= testigo() ?>

        <div class="field">
          <label class="label" for="ev-nombre">Nombre del evento</label>
          <input class="input" id="ev-nombre" name="nombre" required maxlength="160"
                 placeholder="Ej. Encuentro de Gobierno Digital">
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="ev-dependencia">Dependencia</label>
            <input class="input" id="ev-dependencia" name="dependencia" maxlength="160"
                   value="Secretaría TIC, Innovación y Gobierno Abierto">
          </div>
          <div class="field">
            <label class="label" for="ev-sede">Sede</label>
            <input class="input" id="ev-sede" name="sede" maxlength="160" placeholder="Centro de Convenciones, Pasto">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="ev-fecha">Fecha de inicio</label>
            <input class="input" id="ev-fecha" name="fecha_inicio" type="date" required>
          </div>
          <div class="field">
            <label class="label" for="ev-jornadas">Número de jornadas</label>
            <input class="input input--mono" id="ev-jornadas" name="jornadas" type="number"
                   min="1" max="30" value="3">
          </div>
        </div>

        <div class="row row--end">
          <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
          <button class="btn btn--primary" type="submit">Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= Editar y eliminar, uno por evento ================= -->
<?php foreach ($eventos as $ev): $c = $ev['contenido']; ?>

  <div class="modal hidden" id="modal-editar-<?= (int) $ev['id'] ?>" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Editar evento">
      <div class="modal__head">
        <span>Editar evento</span>
        <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
      </div>
      <div class="modal__body">
        <h2 style="font-size:22px"><?= e($ev['nombre']) ?></h2>
        <form method="post" action="<?= e(u('/admin/eventos/editar')) ?>" class="stack stack--4">
          <?= testigo() ?>
          <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">

          <div class="field">
            <label class="label" for="ed-nombre-<?= (int) $ev['id'] ?>">Nombre del evento</label>
            <input class="input" id="ed-nombre-<?= (int) $ev['id'] ?>" name="nombre" required
                   maxlength="160" value="<?= e((string) $ev['nombre']) ?>">
          </div>

          <div class="grid-2">
            <div class="field">
              <label class="label" for="ed-dep-<?= (int) $ev['id'] ?>">Dependencia</label>
              <input class="input" id="ed-dep-<?= (int) $ev['id'] ?>" name="dependencia"
                     maxlength="160" value="<?= e((string) $ev['dependencia']) ?>">
            </div>
            <div class="field">
              <label class="label" for="ed-sede-<?= (int) $ev['id'] ?>">Sede</label>
              <input class="input" id="ed-sede-<?= (int) $ev['id'] ?>" name="sede"
                     maxlength="160" value="<?= e((string) $ev['sede']) ?>">
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label class="label" for="ed-fecha-<?= (int) $ev['id'] ?>">Fecha de inicio</label>
              <input class="input" id="ed-fecha-<?= (int) $ev['id'] ?>" name="fecha_inicio"
                     type="date" required value="<?= e((string) $ev['fecha_inicio']) ?>">
            </div>
            <div class="field">
              <label class="label" for="ed-estado-<?= (int) $ev['id'] ?>">Estado</label>
              <select class="select" id="ed-estado-<?= (int) $ev['id'] ?>" name="estado">
                <?php foreach ($estados as $est): ?>
                  <option value="<?= e($est) ?>" <?= $ev['estado'] === $est ? 'selected' : '' ?>>
                    <?= e($etiquetas[$est][0] ?? $est) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer;flex-wrap:nowrap">
            <input type="checkbox" name="mover_jornadas" value="1"
                   style="margin-top:3px;width:18px;height:18px;flex:none;accent-color:var(--c-accent)">
            <span class="help" style="flex:1">
              Mover también las <?= e((string) $c['jornadas']) ?> jornadas. Se desplazan todas los
              mismos días que la fecha de inicio, conservando la separación entre ellas. Sin
              marcarlo, las jornadas se quedan donde están.
            </span>
          </label>

          <div class="row row--end">
            <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
            <button class="btn btn--primary" type="submit">Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal hidden" id="modal-borrar-<?= (int) $ev['id'] ?>" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Eliminar evento">
      <div class="modal__head">
        <span>Eliminar evento</span>
        <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
      </div>
      <div class="modal__body">
        <h2 style="font-size:22px">Eliminar «<?= e($ev['nombre']) ?>»</h2>

        <div class="notice notice--danger">
          <span class="notice__icon" aria-hidden="true">▲</span>
          <span><strong>Esto no se puede deshacer.</strong> Se borra el evento y todo lo suyo.</span>
        </div>

        <div class="card__body--tight" style="padding:0">
          <?php foreach ([
            ['Jornadas', $c['jornadas']],
            ['Personas registradas', $c['personas']],
            ['Ingresos sellados', $c['ingresos']],
            ['Propuestas de exposición', $c['propuestas']],
            ['Contactos intercambiados', $c['contactos']],
          ] as [$k, $n]): ?>
            <div class="kv">
              <span class="kv__k"><?= e($k) ?></span>
              <strong class="kv__v" style="<?= $n > 0 ? 'color:var(--c-danger)' : '' ?>"><?= e(numero($n)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="post" action="<?= e(u('/admin/eventos/eliminar')) ?>" class="stack stack--4">
          <?= testigo() ?>
          <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">

          <div class="field">
            <label class="label" for="conf-<?= (int) $ev['id'] ?>">
              Escribe ELIMINAR para confirmar
            </label>
            <input class="input input--mono" id="conf-<?= (int) $ev['id'] ?>" name="confirmacion"
                   autocomplete="off" autocapitalize="characters" placeholder="ELIMINAR" required>
            <span class="help">
              Se pide porque no hay deshacer. Si solo quieres que deje de verse, desactívalo.
            </span>
          </div>

          <div class="row row--end">
            <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
            <button class="btn btn--danger" type="submit">Eliminar definitivamente</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php endforeach; ?>
