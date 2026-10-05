<?php
/**
 * Todos los carnets, en una sola cara cada uno.
 *
 * El carnet individual se imprime en dos caras porque su dueño lo dobla por la
 * mitad. Para una tanda eso no sirve: imprimir cuatrocientas caras y aparearlas
 * a mano es lo que hace que nadie use la función. Aquí cada persona ocupa una
 * tarjeta con el código dentro, se recorta y ya está.
 *
 * El reparto por hoja lo hace el navegador con «break-inside: avoid» del CSS de
 * impresión. Paginarlo aquí obligaría a saber el tamaño del papel, que es
 * justamente lo que el servidor no sabe.
 *
 * @var array $tarjetas @var array $filtros
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';

$conFiltro = array_filter([
    'q'   => (string) ($filtros['texto'] ?? ''),
    'rol' => (string) ($filtros['rol'] ?? ''),
]);
?>
<main class="main" id="contenido" style="padding:24px">
  <div class="view view--wide stack stack--4">

    <div class="row row--between no-print">
      <div class="stack stack--2">
        <span class="kicker">Impresión</span>
        <h1 style="font-size:26px">
          <?= e(numero(count($tarjetas))) ?> carnet<?= count($tarjetas) === 1 ? '' : 's' ?>
        </h1>
        <p class="help">
          Una cara por persona, con el código incluido. Salen a tamaño real: imprime en
          cartulina y recorta por el borde. Caben seis por hoja tamaño carta.
        </p>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="button" data-imprimir>Imprimir</button>
        <a class="btn" href="<?= e(u('/carnets', $conFiltro)) ?>">Volver</a>
      </div>
    </div>

    <?php if (!$tarjetas): ?>
      <div class="empty">No hay ningún carnet que imprimir con ese filtro</div>
    <?php else: ?>
      <div class="carnet-tanda">
        <?php foreach ($tarjetas as $t):
          $p = $t['persona'];
          $rol = (string) $p['rol'];
          $foto = (string) ($p['foto'] ?? '') !== '' ? u('/medios/foto/' . (int) $p['id']) : '';
        ?>
          <div class="carnet-uno" data-rol="<?= e($rol) ?>">

            <div class="carnet-uno__head">
              <?= $marca('sm') ?>
              <span class="brandtext">
                <span class="brandtext__name"><?= e($evento['nombre']) ?></span>
                <span class="brandtext__sub"><?= e($evento['dependencia'] ?: '') ?></span>
              </span>
            </div>

            <div class="carnet-uno__rol"><?= e(mb_strtoupper(etiquetaRol($rol))) ?></div>

            <div class="carnet-uno__quien">
              <div class="carnet-uno__foto">
                <?php if ($foto !== ''): ?>
                  <img src="<?= e($foto) ?>" alt="">
                <?php else: ?>
                  <span><?= e(iniciales((string) $p['nombre'])) ?></span>
                <?php endif; ?>
              </div>
              <div class="carnet-uno__datos">
                <span class="carnet-uno__nombre"><?= e($p['nombre']) ?></span>
                <?php if (identificacion($p['tipo_documento'], $t['documento']) !== ''): ?>
                  <span class="carnet-uno__dato"><?= e(identificacion($p['tipo_documento'], $t['documento'])) ?></span>
                <?php endif; ?>
                <span class="carnet-uno__dato"><?= e($p['entidad'] ?: 'Independiente') ?></span>
                <?php if ((string) $p['municipio'] !== ''): ?>
                  <span class="carnet-uno__dato"><?= e($p['municipio']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="carnet-uno__pie">
              <div class="carnet-uno__qr"><?= $t['qr'] ?></div>
              <div class="carnet-uno__codigo">
                <span><?= e($t['credencial']['codigo']) ?></span>
                <span class="carnet-uno__cap">Escanear para acceso / contacto</span>
              </div>
            </div>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</main>
