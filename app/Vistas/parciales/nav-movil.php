<?php
/**
 * Navegación inferior del móvil, como la de las aplicaciones: una fila de
 * íconos de borde a borde, pegada abajo.
 *
 * Las cuatro primeras entradas son lo de uso diario de cada quien. La quinta es
 * la cuenta: abre una hoja con su nombre, el resto de opciones y «Cerrar
 * sesión». Sin sesión no hay hoja que abrir —todo lo que hay ya está en la
 * barra—, así que la quinta lleva a «Entrar».
 *
 * @var array  $pestanasMovil  las cuatro de navegacion.php
 * @var array  $navegacion
 * @var string $pantalla
 */
defined('EVENTOS_TIC') || exit;

if (empty($pestanasMovil)) {
    return;
}

$conSesion = $usuario !== null || $persona !== null;

// Si la pantalla actual no está en la barra pero sí en la hoja —Expositores,
// Mis datos—, se marca el botón que la abre: así siempre se sabe dónde se está.
$enLaBarra = in_array($pantalla, array_column($pestanasMovil, 'clave'), true);
$enLaHoja = false;
foreach ($navegacion as $grupo) {
    $enLaHoja = $enLaHoja || in_array($pantalla, array_column($grupo['items'], 'clave'), true);
}
$cuentaActiva = $conSesion ? (!$enLaBarra && $enLaHoja) : $pantalla === 'entrar';

// Quién tiene la sesión abierta, para el encabezado de la hoja.
if ($usuario !== null) {
    $quien = (string) $usuario['nombre'];
    $comoQue = ['administrador' => 'Administrador', 'operador' => 'Operador de acceso', 'consulta' => 'Consulta'][$usuario['rol']]
        ?? ucfirst((string) $usuario['rol']);
} elseif ($persona !== null) {
    $quien = (string) ($persona['nombre'] !== '' ? $persona['nombre'] : $persona['correo']);
    $comoQue = etiquetaRol((string) $persona['rol']);
}
?>
<nav class="tabbar" aria-label="Navegación rápida">
  <div class="tabbar__inner">
    <?php foreach ($pestanasMovil as $item): $esta = $item['clave'] === $pantalla; ?>
      <a class="tabbar__btn<?= activo($item['clave'], $pantalla) ?>" href="<?= e(u($item['ruta'])) ?>"
         aria-label="<?= e($item['etiqueta']) ?>" <?= $esta ? 'aria-current="page"' : '' ?>>
        <?= $icono($item['icono'], 24, $esta ? 2.1 : 1.6) ?>
      </a>
    <?php endforeach; ?>

    <?php if ($conSesion): ?>
      <button class="tabbar__btn<?= $cuentaActiva ? ' is-active' : '' ?>" type="button"
              data-abrir-hoja="menu-movil" aria-controls="menu-movil" aria-expanded="false"
              aria-label="<?= $usuario !== null ? 'Más opciones' : 'Mi cuenta y más opciones' ?>">
        <?= $icono($usuario !== null ? 'menu' : 'user', 24, $cuentaActiva ? 2.1 : 1.6) ?>
      </button>
    <?php else: ?>
      <a class="tabbar__btn<?= $cuentaActiva ? ' is-active' : '' ?>" href="<?= e(u('/entrar')) ?>"
         aria-label="Entrar" <?= $cuentaActiva ? 'aria-current="page"' : '' ?>>
        <?= $icono('user', 24, $cuentaActiva ? 2.1 : 1.6) ?>
      </a>
    <?php endif; ?>
  </div>
</nav>

<?php if ($conSesion): ?>
<div class="sheet hidden" id="menu-movil" hidden>
  <div class="sheet__panel" role="dialog" aria-modal="true" aria-labelledby="menu-movil-quien">
    <div class="sheet__head">
      <span class="stack" style="gap:3px;min-width:0">
        <strong class="sheet__quien" id="menu-movil-quien"><?= e($quien) ?></strong>
        <span class="sheet__rol"><?= e($comoQue) ?></span>
      </span>
      <button class="modal__close" type="button" data-cerrar-hoja aria-label="Cerrar">&times;</button>
    </div>
    <div class="sheet__list">
      <?php foreach ($navegacion as $grupo): ?>
        <span class="sidebar__groupLabel"><?= e($grupo['titulo']) ?></span>
        <?php foreach ($grupo['items'] as $item): ?>
          <a class="navlink<?= activo($item['clave'], $pantalla) ?>" href="<?= e(u($item['ruta'])) ?>"
             <?= $item['clave'] === $pantalla ? 'aria-current="page"' : '' ?>>
            <?= $icono($item['icono']) ?><span><?= e($item['etiqueta']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <form method="post" action="<?= e(u($usuario !== null ? '/admin/salir' : '/salir')) ?>" style="padding:8px 4px">
        <?= testigo() ?>
        <button class="btn btn--sm btn--block" type="submit"><?= $icono('salir', 14) ?> Cerrar sesión</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
