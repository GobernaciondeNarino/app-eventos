<?php
/**
 * Las páginas de un listado, para el pie de la tabla.
 *
 * Todo son enlaces, sin JavaScript: llevan los filtros y el orden de la
 * pantalla, y cambian solo la página o cuántas filas se ven por página.
 *
 * @var App\Nucleo\Paginacion $paginacion
 * @var string $rutaLista      la dirección del listado, p. ej. '/admin/registros'
 * @var array  $consultaLista  los filtros y el orden que hay que conservar
 * @var string $nombreLista    «registros», «carnets», «propuestas»
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Paginacion;

$pg = $paginacion;
$enlace = static fn(array $cambio): string => u($rutaLista, array_merge(
    array_diff_key($consultaLista, ['pagina' => true, 'por' => true]),
    ['por' => $pg->porPagina],
    $cambio
));
?>
<nav class="paginas no-print" aria-label="Páginas de <?= e($nombreLista) ?>" data-paginas>
  <span class="paginas__cuenta">
    <?php if ($pg->total === 0): ?>
      Sin <?= e($nombreLista) ?> que mostrar
    <?php else: ?>
      <?= e(numero($pg->primero())) ?>–<?= e(numero($pg->ultimo())) ?> de <?= e(numero($pg->total)) ?> <?= e($nombreLista) ?>
    <?php endif; ?>
  </span>

  <?php if ($pg->paginas() > 1): ?>
    <?php /* En el celular no caben los números: queda «‹ Página 4 de 9 ›», con
             las flechas del mismo tamaño que un botón. */ ?>
    <span class="paginas__numeros">
      <?php if ($pg->pagina > 1): ?>
        <a class="paginas__paso" href="<?= e($enlace(['pagina' => $pg->pagina - 1])) ?>" rel="prev"
           aria-label="Página anterior">‹<span class="paginas__texto">Anterior</span></a>
      <?php endif; ?>
      <span class="paginas__lista">
        <?php foreach ($pg->numeros() as $n): ?>
          <?php if ($n === null): ?>
            <span class="paginas__hueco" aria-hidden="true">…</span>
          <?php elseif ($n === $pg->pagina): ?>
            <span class="paginas__num is-actual" aria-current="page" aria-label="Página <?= e((string) $n) ?>, la actual"><?= e((string) $n) ?></span>
          <?php else: ?>
            <a class="paginas__num" href="<?= e($enlace(['pagina' => $n])) ?>" aria-label="Página <?= e((string) $n) ?>"><?= e((string) $n) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </span>
      <span class="paginas__donde" aria-hidden="true">Página <?= e((string) $pg->pagina) ?> de <?= e((string) $pg->paginas()) ?></span>
      <?php if ($pg->pagina < $pg->paginas()): ?>
        <a class="paginas__paso" href="<?= e($enlace(['pagina' => $pg->pagina + 1])) ?>" rel="next"
           aria-label="Página siguiente"><span class="paginas__texto">Siguiente</span>›</a>
      <?php endif; ?>
    </span>
  <?php endif; ?>

  <?php if ($pg->total > min(Paginacion::TAMANOS)): ?>
    <?php /* Cambiar el tamaño vuelve a la primera página: con otro tamaño, la
             página que se miraba tiene otras filas. */ ?>
    <span class="paginas__tamano">
      Por página
      <?php foreach (Paginacion::TAMANOS as $tamano): ?>
        <?php if ($tamano === $pg->porPagina): ?>
          <span class="is-actual" aria-current="true"><?= e((string) $tamano) ?></span>
        <?php else: ?>
          <a href="<?= e($enlace(['por' => $tamano, 'pagina' => 1])) ?>"
             aria-label="Ver <?= e((string) $tamano) ?> por página"><?= e((string) $tamano) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </span>
  <?php endif; ?>
</nav>
