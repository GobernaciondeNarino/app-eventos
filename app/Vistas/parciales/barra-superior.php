<?php
/** Barra superior, solo visible en móvil. */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/marca.php';

$etiquetaPantalla = '';
foreach (($navegacion ?? []) as $grupo) {
    foreach ($grupo['items'] as $item) {
        if ($item['clave'] === ($pantalla ?? '')) {
            $etiquetaPantalla = $item['etiqueta'];
        }
    }
}
?>
<header class="topbar">
  <?php /* En una sola línea, como el encabezado de una aplicación: con .row el
           nombre del evento bajaba a un segundo renglón en vez de recortarse,
           y la barra ocupaba el doble de alto. */ ?>
  <a class="topbar__marca" href="<?= e(u('/')) ?>">
    <?= $marca('sm') ?>
    <span class="brandtext__name"><?= e($evento['nombre'] ?? 'Eventos TIC') ?></span>
  </a>
  <span class="topbar__screen"><?= e($etiquetaPantalla ?: ($titulo ?? '')) ?></span>
</header>
