<?php
/**
 * Ficha de acreditación: lo que ve quien acredita tras leer un carnet.
 *
 * La abren el equipo organizador y el Staff, que entra con su propio acceso de
 * asistente. Lo único que cambia entre los dos es a dónde vuelve el botón del
 * final: cada uno a su pantalla de escaneo.
 *
 * @var array $credencial @var string $documento @var array|null $jornada
 * @var array|null $yaTiene @var array $historial @var int $escaneosHoy
 * @var array $acreditador
 */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--4" style="max-width:560px;margin:auto">

  <div class="row row--between">
    <span class="kicker">Carnet reconocido</span>
    <span class="tag"><?= e(numero($escaneosHoy)) ?> escaneos hoy</span>
  </div>

  <div class="card">
    <div class="card__head">
      <span><?= e($credencial['codigo']) ?></span>
      <span class="tag <?= e(claseRol((string) $credencial['rol'])) ?>"><?= e(etiquetaRol((string) $credencial['rol'])) ?></span>
    </div>
    <div class="card__body stack stack--3">
      <strong style="font-family:var(--f-display);font-size:22px;font-weight:600;letter-spacing:.04em;color:var(--c-title);line-height:1.2">
        <?= e($credencial['nombre']) ?>
      </strong>
      <span class="mono" style="font-size:13px;color:var(--c-text)">
        <?= e(identificacion($credencial['tipo_documento'], $documento) ?: 'Sin identificación registrada') ?>
      </span>
      <span class="mono" style="font-size:13px;color:var(--c-text)">
        <?= e($credencial['entidad'] ?: 'Independiente') ?><?= $credencial['municipio'] !== '' ? ' · ' . e($credencial['municipio']) : '' ?>
      </span>
    </div>
  </div>

  <?php if (!$jornada): ?>
    <div class="notice notice--warn">
      <span class="notice__icon">▲</span>
      <span>Hoy no hay ninguna jornada programada, así que no se puede sellar el ingreso.</span>
    </div>
  <?php elseif ($yaTiene): ?>
    <div class="notice notice--ok">
      <span class="notice__icon">✓</span>
      <span>
        Ya tiene ingreso del día <?= e((string) $jornada['numero']) ?>, registrado a las
        <?= e(hora((string) $yaTiene['registrado_en'])) ?>. Puede pasar.
      </span>
    </div>
  <?php else: ?>
    <div class="notice">
      <span class="notice__icon">◆</span>
      <span>Sin ingreso registrado para el día <?= e((string) $jornada['numero']) ?>. Confirma para sellar la asistencia.</span>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head"><span>Historial en el evento</span></div>
    <div class="card__body--tight">
      <?php foreach ($historial as $h): ?>
        <div class="kv">
          <span class="kv__k">Día <?= e((string) $h['numero']) ?> · <?= e(fecha((string) $h['fecha'])) ?></span>
          <span class="mono" style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:<?= $h['registrado_en'] ? 'var(--c-accent)' : 'var(--c-muted)' ?>">
            <?= $h['registrado_en'] ? e(hora((string) $h['registrado_en'])) : 'Sin ingreso' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($jornada && !$yaTiene): ?>
    <form method="post" action="<?= e(u('/c/' . $credencial['token'] . '/asistencia')) ?>" class="stack stack--2">
      <?= testigo() ?>
      <input type="hidden" name="jornada" value="<?= e((string) $jornada['numero']) ?>">
      <button class="btn btn--primary btn--block btn--lg" type="submit">
        Registrar ingreso · Día <?= e((string) $jornada['numero']) ?>
      </button>
    </form>
  <?php endif; ?>

  <a class="btn btn--block"
     href="<?= e(u($acreditador['tipo'] === 'staff' ? '/acreditar' : '/admin/escaner')) ?>">
    Escanear el siguiente
  </a>
</div>
