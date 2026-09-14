<?php
/**
 * Panel del evento.
 * @var array $resumen @var array $porJornada @var array $municipios
 * @var array $bitacora @var array $pendientes
 * @var bool $esquemaPendiente @var string $motivoEsquema @var string $versionEsquema
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bitacora;

$maxJornada = max(1, max(array_map(static fn($j) => (int) $j['ingresos'], $porJornada ?: [['ingresos' => 1]])));
$maxMunicipio = max(1, max(array_map(static fn($m) => (int) $m['n'], $municipios ?: [['n' => 1]])));
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Panel del evento</h1>
      <p class="help"><?= e($evento['nombre']) ?><?= $evento['sede'] !== '' ? ' · ' . e($evento['sede']) : '' ?></p>
    </div>
    <div class="row">
      <a class="btn btn--sm" href="<?= e(u('/admin/qr-dias')) ?>">Código del día</a>
      <a class="btn btn--sm btn--primary" href="<?= e(u('/admin/escaner')) ?>">Escanear carnet</a>
    </div>
  </div>

  <?php if ($esquemaPendiente): ?>
    <!-- Se actualiza el código de la plataforma copiando archivos, pero la
         base no se entera sola. Este aviso es lo que faltaba para que quien
         administra sepa que hay algo que hacer, y pueda hacerlo desde aquí. -->
    <div class="notice notice--warn">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:9px;flex:1">
        <strong>La base de datos está atrasada respecto al código</strong>
        <span class="help" style="margin:0"><?= e($motivoEsquema) ?></span>
        <span class="help" style="margin:0">
          Al actualizar solo se agregan las tablas y columnas que falten para la versión
          <?= e($versionEsquema) ?>. No se borra ni se cambia nada de lo que ya hay, así que
          se puede hacer con el evento en curso.
        </span>
        <form method="post" action="<?= e(u('/admin/actualizar-esquema')) ?>">
          <?= testigo() ?>
          <button class="btn btn--sm btn--primary" type="submit">Actualizar la base de datos</button>
        </form>
      </span>
    </div>
  <?php endif; ?>

  <div class="grid-4">
    <?php
    $jornada = $resumen['jornada_hoy'];
    $kpis = [
      ['Registrados', numero($resumen['registros']), 'Total del evento'],
      [
        $jornada ? 'Ingresos día ' . $jornada['numero'] : 'Ingresos hoy',
        numero($resumen['ingresos_hoy']),
        $resumen['registros'] > 0 && $jornada
          ? round($resumen['ingresos_hoy'] / $resumen['registros'] * 100) . '% de los registrados'
          : 'Sin jornada hoy',
      ],
      ['Expositores', numero($resumen['expositores']), $resumen['por_aprobar'] . ' propuestas por aprobar'],
      ['Municipios', numero($resumen['municipios']), 'con al menos un registro'],
    ];
    foreach ($kpis as [$etiqueta, $valor, $sub]): ?>
      <div class="kpi">
        <span class="kpi__label"><?= e($etiqueta) ?></span>
        <strong class="kpi__value"><?= e($valor) ?></strong>
        <span class="kpi__sub"><?= e($sub) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="split" style="align-items:start">
    <div class="stack stack--4">

      <div class="card">
        <div class="card__head"><span>Ingresos por jornada</span></div>
        <div class="card__body stack stack--4">
          <?php if (!$porJornada): ?>
            <div class="empty">Este evento todavía no tiene jornadas</div>
          <?php else: foreach ($porJornada as $j): ?>
            <div class="stack" style="gap:6px">
              <div class="row row--between">
                <span class="mono" style="font-size:12px;color:var(--c-text)">
                  Día <?= e((string) $j['numero']) ?> · <?= e(fecha((string) $j['fecha'])) ?>
                </span>
                <span class="mono accent" style="font-size:13px"><?= e(numero($j['ingresos'])) ?></span>
              </div>
              <div class="progress">
                <div class="progress__bar" style="width:<?= (int) round((int) $j['ingresos'] / $maxJornada * 100) ?>%"></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <span>Cobertura territorial</span>
          <span class="muted">Municipios con registros</span>
        </div>
        <div class="card__body stack stack--3">
          <?php if (!$municipios): ?>
            <div class="empty">Todavía no hay registros con municipio</div>
          <?php else: foreach ($municipios as $m): ?>
            <div class="row" style="gap:12px;flex-wrap:nowrap">
              <span style="width:150px;flex-shrink:0;font-size:13px;color:var(--c-title)"><?= e($m['municipio']) ?></span>
              <span class="progress" style="flex:1">
                <span class="progress__bar" style="display:block;width:<?= (int) round((int) $m['n'] / $maxMunicipio * 100) ?>%"></span>
              </span>
              <span class="mono muted" style="width:32px;text-align:right;font-size:12px"><?= e((string) $m['n']) ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>

    <div class="stack stack--3 is-sticky">
      <?php if ($pendientes): ?>
        <div class="card">
          <div class="card__head"><span>Pendientes</span></div>
          <div class="card__body stack stack--3">
            <?php foreach ($pendientes as $p): ?>
              <a class="notice<?= $p['tipo'] !== '' ? ' notice--' . e($p['tipo']) : '' ?>"
                 href="<?= e(u($p['ruta'])) ?>" style="text-decoration:none;color:inherit">
                <span class="notice__icon" aria-hidden="true">›</span>
                <span><?= e($p['texto']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__head"><span>Bitácora</span></div>
        <div class="card__body--tight">
          <?php if (!$bitacora): ?>
            <div class="empty">Sin actividad registrada</div>
          <?php else: foreach ($bitacora as $b): ?>
            <div class="stack" style="gap:3px;padding:10px 0;border-bottom:1px solid var(--hair-soft)">
              <span style="font-size:13px;color:var(--c-title)"><?= e(Bitacora::describir($b)) ?></span>
              <span class="mono muted" style="font-size:11px">
                <?= e(fecha((string) $b['creado_en'])) ?> · <?= e(hora((string) $b['creado_en'])) ?>
              </span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>
