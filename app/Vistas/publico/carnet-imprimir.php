<?php
/**
 * Carnet listo para imprimir: las dos caras a tamaño CR80 vertical.
 * @var array $credencial @var string $documento @var string $qr @var array $persona
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$rol = (string) $persona['rol'];
?>
<main class="main" id="contenido" style="padding:24px">
  <div class="view view--narrow stack stack--4" style="max-width:820px;margin:auto">

    <div class="row row--between no-print">
      <div class="stack stack--2">
        <span class="kicker">Impresión</span>
        <h1 style="font-size:26px">Carnet para imprimir</h1>
        <p class="help">Las dos caras salen a tamaño real. Imprime en cartulina y recorta por el borde.</p>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="button" data-imprimir>Imprimir</button>
        <a class="btn" href="<?= e(u('/carnet')) ?>">Volver</a>
      </div>
    </div>

    <div class="carnet-wrap" style="align-items:flex-start">
      <div class="carnet" data-rol="<?= e($rol) ?>" style="cursor:default">
        <div class="carnet__inner">

          <div class="carnet__face">
            <div class="carnet__head">
              <?= $marca('lg') ?>
              <span class="brandtext">
                <span class="brandtext__name"><?= e($evento['nombre']) ?></span>
                <span class="brandtext__sub"><?= e($evento['dependencia'] ?: '') ?></span>
              </span>
            </div>
            <div class="carnet__photo">
              <div><span class="carnet__photo-empty"><?= e(iniciales((string) $persona['nombre'])) ?></span></div>
            </div>
            <div class="carnet__rol"><?= e(mb_strtoupper(etiquetaRol($rol))) ?></div>
            <div class="carnet__fields">
              <?php foreach ([
                ['Nombre', $persona['nombre']],
                ['Identificación', identificacion($persona['tipo_documento'], $documento) ?: 'No registrada'],
                ['Entidad', $persona['entidad'] ?: 'Independiente'],
              ] as [$k, $valor]): ?>
                <div class="carnet__field">
                  <span class="carnet__k"><?= e($k) ?></span>
                  <span class="carnet__v"><?= e($valor) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="carnet__face carnet__face--back">
            <div class="carnet__backhead">
              <span class="brandtext__name"><?= e($evento['nombre']) ?></span>
              <span class="brandtext__sub">Credencial digital · <?= e($credencial['codigo']) ?></span>
            </div>
            <div class="carnet__qrbox">
              <?= $qr ?>
              <span class="carnet__qrcap">Escanear para acceso / contacto</span>
            </div>
            <div class="carnet__foot">
              <span class="carnet__code"><?= e(identificacion($persona['tipo_documento'], $documento) ?: $credencial['codigo']) ?></span>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</main>
