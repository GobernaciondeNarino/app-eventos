<?php
/**
 * Todos los carnets del evento.
 *
 * La ven el equipo y el Staff. Desde aquí se consulta uno suelto y se manda a
 * imprimir la tanda completa —o la que deje el filtro, que es lo que de verdad
 * se usa: «los expositores», «los de la Alcaldía de Tumaco».
 *
 * @var array $personas  solo los de la página que se ve
 * @var array $filtros @var int $total @var int $conCarnet  los que deja el filtro
 * @var int $sinCarnet  los del evento con el registro a medias
 * @var App\Nucleo\Paginacion $paginacion @var App\Nucleo\Orden $orden
 * @var array $consulta @var int $tanda  cuántos se imprimen de una vez
 */
defined('EVENTOS_TIC') || exit;

$conFiltro = $consulta;
$conOrden = $consulta + $orden->consulta();
$columnas = 'grid-template-columns:1.6fr 1.1fr 1fr .9fr';
$tandas = (int) ceil($conCarnet / $tanda);
$titulo = static fn(string $etiqueta, string $clave): string =>
    tituloOrdenable($etiqueta, $clave, $orden, '/carnets', $consulta + ['por' => $paginacion->porPagina]);
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
    <div class="row no-print" data-imprimir-tandas>
      <?php if ($conCarnet > 0 && $tandas <= 1): ?>
        <a class="btn btn--primary" href="<?= e(u('/carnets/imprimir', $conOrden)) ?>">
          Imprimir <?= e(numero($conCarnet)) ?> carnet<?= $conCarnet === 1 ? '' : 's' ?>
        </a>
      <?php elseif ($conCarnet > 0): ?>
        <?php /* Más de una tanda: un botón por cada una, con qué carnets lleva.
                 Imprimirlos todos de una vez haría esperar al servidor más de
                 lo que deja correr a PHP. */ ?>
        <span class="help" style="margin:0">Imprimir por tandas de <?= e(numero($tanda)) ?>:</span>
        <?php for ($t = 1; $t <= $tandas; $t++): ?>
          <a class="btn<?= $t === 1 ? ' btn--primary' : '' ?>"
             href="<?= e(u('/carnets/imprimir', $conOrden + ['tanda' => $t])) ?>">
            <?= e(numero(($t - 1) * $tanda + 1)) ?>–<?= e(numero(min($conCarnet, $t * $tanda))) ?>
          </a>
        <?php endfor; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="table">
    <form class="table__tools no-print" method="get" action="<?= e(u('/carnets')) ?>">
      <input type="hidden" name="orden" value="<?= e($orden->clave) ?>">
      <input type="hidden" name="dir" value="<?= e($orden->direccion) ?>">
      <input type="hidden" name="por" value="<?= e((string) $paginacion->porPagina) ?>">
      <label class="sr-only" for="q">Buscar</label>
      <input class="input" id="q" name="q" value="<?= e((string) ($filtros['texto'] ?? '')) ?>"
             placeholder="Identificación, nombre, correo o entidad…">

      <label class="sr-only" for="rol">Perfil</label>
      <select class="select" id="rol" name="rol">
        <option value="">Todos los perfiles</option>
        <?php foreach (perfilesDelEvento() as $rol => $nombreRol): ?>
          <option value="<?= e($rol) ?>" <?= ($filtros['rol'] ?? '') === $rol ? 'selected' : '' ?>>
            <?= e($nombreRol) ?>
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
          <span><?= $titulo('Asistente', 'nombre') ?></span><span><?= $titulo('Entidad', 'entidad') ?></span>
          <span><?= $titulo('Territorio', 'municipio') ?></span><span><?= $titulo('Perfil', 'perfil') ?></span>
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
      <?= App\Nucleo\Respuesta::parcial('parciales/paginacion', [
          'paginacion'    => $paginacion,
          'rutaLista'     => '/carnets',
          'consultaLista' => $conOrden,
          'nombreLista'   => 'carnets',
      ]) /* ya viene escapado */ ?>
    </div>
  </div>

  <?php if ($sinCarnet > 0): ?>
    <div class="notice">
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Hay <?= e(numero($sinCarnet)) ?> registro<?= $sinCarnet === 1 ? '' : 's' ?>
        sin carnet: crearon su acceso con correo y contraseña y todavía no han llenado el
        formulario. Un carnet sin nombre ni identificación sería una cartulina en blanco, así
        que no se imprimen.
      </span>
    </div>
  <?php endif; ?>

</div>
