<?php
/**
 * Elegir el evento sobre el que se trabaja (1.17.0).
 *
 * @var array  $eventos  los eventos en los que trabaja, con 'rol_equipo' y 'direccion'
 * @var int    $elegido  el que tiene abierto, o 0
 * @var string $destino  a dónde iba
 * @var bool   $esGlobal
 */
defined('EVENTOS_TIC') || exit;

$estados = [
    'borrador' => ['Borrador', 'tag--mute'],
    'abierto'  => ['Abierto', ''],
    'en_curso' => ['En curso', 'tag--ok'],
    'cerrado'  => ['Cerrado', 'tag--mute'],
];
$roles = ['administrador' => 'Administrador', 'operador' => 'Operador', 'consulta' => 'Consulta'];
?>
<div class="view view--medium stack stack--4" data-elegir-evento-pantalla>

  <div class="stack stack--2">
    <span class="kicker"><?= $esGlobal ? 'Administración global' : 'Tu equipo' ?></span>
    <h1>¿En qué evento vas a trabajar?</h1>
    <p class="help">
      Cada evento funciona por su cuenta: su identidad, su formulario de registro, sus personas,
      sus certificados y su equipo. Lo que hagas desde ahora será del evento que elijas; lo cambias
      cuando quieras desde el menú, en «Cambiar de evento».
    </p>
  </div>

  <?php if ($eventos === []): ?>
    <div class="notice notice--warn" role="status">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>Todavía no trabajas en ningún evento. Pídele a quien lo administra que te agregue a su equipo.</span>
    </div>
  <?php endif; ?>

  <div class="stack stack--3">
    <?php foreach ($eventos as $ev):
      [$etiqueta, $clase] = $estados[$ev['estado']] ?? ['—', ''];
      $esEste = (int) $ev['id'] === $elegido; ?>
      <div class="card" data-evento-opcion="<?= (int) $ev['id'] ?>">
        <div class="card__body row row--between" style="gap:14px;align-items:center">
          <div class="stack" style="gap:6px;min-width:0;flex:1 1 260px">
            <strong style="font-weight:600;color:var(--c-title);font-size:16px"><?= e($ev['nombre']) ?></strong>
            <div class="row" style="gap:6px">
              <span class="tag <?= e($clase) ?>"><?= e($etiqueta) ?></span>
              <?php if ((int) $ev['activo'] === 1): ?><span class="tag tag--ok">Principal</span><?php endif; ?>
              <span class="tag tag--mute">Tu rol: <?= e($roles[$ev['rol_equipo']] ?? $ev['rol_equipo']) ?></span>
            </div>
            <span class="mono muted" style="font-size:11.5px;overflow-wrap:anywhere">
              <?= e(fecha((string) $ev['fecha_inicio'])) ?> · <?= e((string) $ev['jornadas']) ?> jornada(s)
              · <?= e(numero($ev['registros'])) ?> registros · <?= e($ev['direccion']) ?>
            </span>
          </div>
          <form method="post" action="<?= e(u('/admin/evento')) ?>" style="flex:none">
            <?= testigo() ?>
            <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">
            <input type="hidden" name="destino" value="<?= e($destino) ?>">
            <button class="btn btn--sm<?= $esEste ? '' : ' btn--primary' ?>" type="submit"
                    data-elegir-evento="<?= (int) $ev['id'] ?>" <?= $esEste ? 'data-evento-elegido' : '' ?>>
              <?= $esEste ? 'Seguir en este' : 'Trabajar en este evento' ?>
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($esGlobal): ?>
    <p class="help">
      Como administrador global ves todos los eventos. Para crear uno, elegir el principal o
      eliminarlo, ve a <a href="<?= e(u('/admin/eventos')) ?>">Eventos</a>.
    </p>
  <?php endif; ?>
</div>
