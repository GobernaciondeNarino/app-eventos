<?php
/**
 * «Mi certificado» (3.21): los certificados de quien tiene la sesión abierta,
 * para descargarlos en PDF. Mientras la organización no los abra, o si todavía
 * no tiene derecho a ninguno, dice por qué y qué puede hacer.
 *
 * @var bool $abiertos  la organización ya los publicó
 * @var bool $completo  tiene nombre: sin él no hay certificado
 * @var array $certificados cada uno con su emisión y sus textos (Certificado::dePersona + redactar)
 * @var array|null $asistencia sus jornadas, para explicar lo que hay
 * @var array $eventoPersona
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Certificado;

$jornadas = $asistencia['jornadas'] ?? [];
$total = count($jornadas);
$asistidas = (int) ($asistencia['asistidas'] ?? 0);

$medalla = static fn(int $tam = 44): string => '<svg viewBox="0 0 48 48" width="' . $tam . '" height="' . $tam . '" aria-hidden="true" style="flex:none">'
    . '<path d="M16 28l-5 16 7-3 4 6 4-13z" fill="var(--c-accent)" opacity=".85"/>'
    . '<path d="M32 28l5 16-7-3-4 6-4-13z" fill="var(--c-accent)" opacity=".85"/>'
    . '<circle cx="24" cy="19" r="15" fill="#F7C531" stroke="#C99A12" stroke-width="2"/>'
    . '<circle cx="24" cy="19" r="10" fill="#FFF3C4" stroke="#fff" stroke-width="1.2"/>'
    . '<path d="M24 12.5l2 4.2 4.6.6-3.3 3.2.8 4.6-4.1-2.2-4.1 2.2.8-4.6-3.3-3.2 4.6-.6z" fill="#102951"/></svg>';
?>
<div class="view view--narrow stack stack--4">

  <div class="stack stack--2">
    <span class="kicker">Certificados · <?= e((string) $eventoPersona['nombre']) ?></span>
    <h1>Mi certificado</h1>
    <p class="help">
      Tu certificado en PDF, listo para descargar, imprimir o enviar. Cada uno lleva un código y un
      QR con los que cualquiera comprueba que es auténtico.
    </p>
  </div>

  <?php if (!$abiertos): ?>
    <div class="notice" data-certificados-cerrados>
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Los certificados todavía no están disponibles. La organización los publica al terminar el
        evento; cuando estén, los descargas aquí mismo.
      </span>
    </div>

  <?php elseif (!$completo): ?>
    <div class="notice notice--warn" role="alert">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        Para emitir tu certificado necesitamos tu nombre completo, tal como debe salir en él.
        <a href="<?= e(u('/registro')) ?>">Complétalo en «Mis datos»</a> y vuelve aquí.
      </span>
    </div>

  <?php elseif ($certificados === []): ?>
    <div class="notice notice--warn" role="alert" data-sin-certificado>
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        No encontramos tu ingreso en ninguna jornada del evento, y el certificado de asistencia se
        entrega a quienes registraron su ingreso. Si estuviste y no escaneaste el código de la
        entrada, comunícate con la organización: pueden registrarlo y tu certificado aparecerá aquí.
      </span>
    </div>

  <?php else: foreach ($certificados as $c):
    $texto = $c['texto'];
    $codigo = Certificado::codigoLegible((string) $c['emision']['codigo']);
    $esAsistencia = $c['tipo'] === 'asistencia';
  ?>
    <section class="card certificado<?= $texto['completa'] ? ' certificado--completo' : '' ?>"
             data-certificado="<?= e($c['tipo']) ?>">
      <div class="card__head">
        <span><?= e(Certificado::TIPOS[$c['tipo']]) ?></span>
        <?php if ($texto['completa']): ?>
          <span class="tag tag--oro" data-asistencia-completa>Asistencia completa</span>
        <?php elseif ($esAsistencia && $total > 1): ?>
          <span class="tag tag--mute"><?= e((string) $asistidas) ?> de <?= e((string) $total) ?> jornadas</span>
        <?php endif; ?>
      </div>
      <div class="card__body stack stack--3">
        <?php if ($texto['completa']): ?>
          <div class="row certificado__sello" style="gap:14px;flex-wrap:nowrap;align-items:center">
            <?= $medalla() ?>
            <p style="margin:0">
              <strong>Asististe a todas las jornadas.</strong> Tu certificado es de asistencia
              completa: lleva el sello dorado y suma las horas de todo el evento.
            </p>
          </div>
        <?php endif; ?>

        <blockquote class="certificado__texto">
          <strong><?= e($texto['nombre']) ?></strong>
          <?= e(implode('', array_column($texto['cuerpo'], 'texto'))) ?>
        </blockquote>

        <div class="stack" style="gap:0">
          <?php if ($esAsistencia): foreach ($jornadas as $j): ?>
            <div class="kv">
              <span class="kv__k">Día <?= e((string) $j['numero']) ?> · <?= e(fecha($j['fecha'])) ?></span>
              <strong class="kv__v" style="color:<?= $j['asistio'] ? 'var(--c-ok)' : 'var(--c-muted)' ?>">
                <?= $j['asistio'] ? '✓ Asististe' : 'Sin ingreso' ?>
              </strong>
            </div>
          <?php endforeach; if ($c['asistencia']['horas'] > 0): ?>
            <div class="kv"><span class="kv__k">Intensidad</span>
              <strong class="kv__v"><?= e(Certificado::horas((float) $c['asistencia']['horas'])) ?></strong></div>
          <?php endif; endif; ?>
          <div class="kv"><span class="kv__k">Código</span>
            <strong class="kv__v mono" data-codigo><?= e($codigo) ?></strong></div>
        </div>

        <div class="stack stack--2">
          <a class="btn btn--primary btn--block" href="<?= e(u('/certificado/' . $c['emision']['codigo'])) ?>"
             download data-descargar-certificado>Descargar certificado (PDF)</a>
          <span class="help" style="margin:0;text-align:center">
            Para comprobarlo: <a href="<?= e(u('/v/' . $c['emision']['codigo'])) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', \App\Nucleo\Url::absoluta('/v'))) ?></a>
            con el código <?= e($codigo) ?>.
          </span>
        </div>
      </div>
    </section>

    <?php if ($esAsistencia && !$texto['completa'] && $total > 1): ?>
      <p class="help" style="margin:0">
        Tu certificado es por <?= $asistidas === 1 ? 'la jornada a la que asististe' : 'las jornadas a las que asististe' ?>.
        Quienes estuvieron en todas reciben el de asistencia completa, con sello y el total de horas.
        Si estuviste en otra y no quedó tu ingreso, comunícate con la organización.
      </p>
    <?php endif; ?>
  <?php endforeach; endif; ?>

  <?php if ((!$abiertos || $certificados === []) && $jornadas !== []): ?>
    <div class="card">
      <div class="card__head">
        <span>Tus ingresos</span>
        <span class="muted"><?= e((string) $asistidas) ?> de <?= e((string) $total) ?></span>
      </div>
      <div class="card__body--tight">
        <?php foreach ($jornadas as $j): ?>
          <div class="kv">
            <span class="kv__k">Día <?= e((string) $j['numero']) ?> · <?= e(fecha($j['fecha'])) ?></span>
            <span class="mono" style="font-size:11px;text-transform:uppercase;color:<?= $j['asistio'] ? 'var(--c-accent)' : 'var(--c-muted)' ?>">
              <?= $j['asistio'] ? 'Ingresó' : 'Sin ingreso' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (!$abiertos && $total > 1): ?>
      <p class="help" style="margin:0">
        Quienes registran su ingreso en todas las jornadas reciben el certificado de asistencia
        completa, con un sello dorado y el total de horas del evento.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
