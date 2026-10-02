<?php
/**
 * Todos los carnets del evento.
 *
 * La ven el equipo y el Staff. Desde aquí se consulta uno suelto y se manda a
 * imprimir la tanda completa —o la que deje el filtro, que es lo que de verdad
 * se usa: «los expositores», «los de la Alcaldía de Tumaco».
 *
 * @var array $personas @var array $filtros @var int $total
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Persona;

$conFiltro = array_filter([
    'q'   => (string) ($filtros['texto'] ?? ''),
    'rol' => (string) ($filtros['rol'] ?? ''),
]);
$columnas = 'grid-template-columns:1.6fr 1.1fr 1fr .9fr';
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Carnets</span>
      <h1>Carnets del evento</h1>
      <p class="help">
        Para quien llega sin teléfono o prefiere el plástico. Se imprimen en una sola cara, con
        el código incluido: se recorta y ya sirve.
      </p>
    </div>
    <div class="row no-print">
      <?php if ($personas): ?>
        <a class="btn btn--primary" href="<?= e(u('/carnets/imprimir', $conFiltro)) ?>">
          Imprimir <?= e(numero(count($personas))) ?> carnet<?= count($personas) === 1 ? '' : 's' ?>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="table">
    <form class="table__tools no-print" method="get" action="<?= e(u('/carnets')) ?>">
      <label class="sr-only" for="q">Buscar</label>
      <input class="input" id="q" name="q" value="<?= e((string) ($filtros['texto'] ?? '')) ?>"
             placeholder="Identificación, nombre, correo o entidad…">

      <label class="sr-only" for="rol">Perfil</label>
      <select class="select" id="rol" name="rol">
        <option value="">Todos los perfiles</option>
        <?php foreach (Persona::ROLES as $rol): ?>
          <option value="<?= e($rol) ?>" <?= ($filtros['rol'] ?? '') === $rol ? 'selected' : '' ?>>
            <?= e(etiquetaRol($rol)) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="btn btn--sm" type="submit">Filtrar</button>
      <?php if ($conFiltro): ?>
        <a class="btn btn--sm btn--dashed" href="<?= e(u('/carnets')) ?>">Limpiar</a>
      <?php endif; ?>
    </form>

    <div class="table__scroll">
      <div class="table__grid" style="min-width:720px">
        <div class="table__head" style="<?= $columnas ?>">
          <span>Asistente</span><span>Entidad</span><span>Territorio</span><span>Perfil</span>
        </div>

        <?php if (!$personas): ?>
          <div class="empty" style="margin:16px">
            <?= $conFiltro
              ? 'Ningún carnet coincide con el filtro'
              : 'Todavía no hay nadie con el registro completo' ?>
          </div>
        <?php else: foreach ($personas as $p): ?>
          <div class="table__row" style="<?= $columnas ?>">
            <div class="stack" style="gap:2px;min-width:0">
              <strong style="font-weight:500;color:var(--c-title)"><?= e($p['nombre']) ?></strong>
              <span class="mono muted" style="font-size:11px;word-break:break-all"><?= e($p['correo']) ?></span>
            </div>
            <span style="color:var(--c-text)"><?= e($p['entidad'] ?: 'Independiente') ?></span>
            <span style="color:var(--c-text)"><?= e($p['municipio'] ?: '—') ?></span>
            <span><span class="tag <?= e(claseRol((string) $p['rol'])) ?>"><?= e(etiquetaRol((string) $p['rol'])) ?></span></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="table__foot">
      <span>
        <?= e(numero(count($personas))) ?> con carnet
        <?php if (count($personas) !== $total): ?>
          · <?= e(numero($total)) ?> registros en total
        <?php endif; ?>
      </span>
      <span>Se imprime lo que deje el filtro</span>
    </div>
  </div>

  <?php if ($total > count($personas)): ?>
    <div class="notice">
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Hay <?= e(numero($total - count($personas))) ?> registro<?= $total - count($personas) === 1 ? '' : 's' ?>
        sin carnet: crearon su acceso con correo y contraseña y todavía no han llenado el
        formulario. Un carnet sin nombre ni identificación sería una cartulina en blanco, así
        que no se imprimen.
      </span>
    </div>
  <?php endif; ?>

</div>
