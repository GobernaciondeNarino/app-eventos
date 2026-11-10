<?php
/** Barra lateral de escritorio. @var array $tema @var string $pantalla */
defined('EVENTOS_TIC') || exit;

require __DIR__ . '/navegacion.php';
$marca = require __DIR__ . '/marca.php';
?>
<aside class="sidebar">
  <div class="sidebar__brand">
    <?= $marca('lg') ?>
    <span class="brandtext">
      <span class="brandtext__name" data-evento-actual="<?= (int) ($evento['id'] ?? 0) ?>"><?= e($evento['nombre'] ?? 'Eventos TIC') ?></span>
      <span class="brandtext__sub"><?= e($evento['sede'] ?? 'Plataforma de eventos') ?></span>
      <?php if ($usuario !== null && ($eventosDelEquipo ?? 0) > 1): ?>
        <a class="brandtext__sub" href="<?= e(u('/admin/evento')) ?>" data-cambiar-evento
           style="color:var(--c-accent);text-decoration:underline;text-underline-offset:3px">Cambiar de evento</a>
      <?php endif; ?>
    </span>
  </div>

  <nav class="sidebar__nav" aria-label="Navegación principal">
    <?php foreach ($navegacion as $grupo): ?>
      <div class="sidebar__group">
        <span class="sidebar__groupLabel"><?= e($grupo['titulo']) ?></span>
        <?php foreach ($grupo['items'] as $item): ?>
          <a class="navlink<?= activo($item['clave'], $pantalla) ?>" href="<?= e(u($item['ruta'])) ?>"
             <?= $item['clave'] === $pantalla ? 'aria-current="page"' : '' ?>>
            <?= $icono($item['icono']) ?><span><?= e($item['etiqueta']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar__foot">
    <?php if ($usuario !== null): ?>
      <form method="post" action="<?= e(u('/admin/salir')) ?>" class="w-full">
        <?= testigo() ?>
        <div class="stack" style="gap:6px">
          <span class="brandtext__sub" style="letter-spacing:.1em"><?= e($usuario['nombre']) ?></span>
          <button class="btn btn--sm btn--block" type="submit"><?= $icono('salir', 14) ?> Cerrar sesión</button>
        </div>
      </form>
    <?php elseif ($persona !== null): ?>
      <form method="post" action="<?= e(u('/salir')) ?>" class="w-full">
        <?= testigo() ?>
        <div class="stack" style="gap:6px">
          <span class="brandtext__sub" style="letter-spacing:.1em"><?= e($persona['nombre']) ?></span>
          <button class="btn btn--sm btn--block" type="submit"><?= $icono('salir', 14) ?> Cerrar sesión</button>
        </div>
      </form>
    <?php else: ?>
      <span class="pulse"></span><span>Sesión segura</span>
    <?php endif; ?>
  </div>
</aside>
