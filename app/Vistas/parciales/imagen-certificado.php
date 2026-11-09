<?php
/**
 * Una de las imágenes del certificado en su configuración: la que está
 * cargada, para verla y quitarla, y el campo para subir otra.
 *
 * @var string $ranura   'fondo', 'texto' o 'firma'
 * @var array  $imagenes ranura => [título, ayuda]
 * @var array  $config   Certificado::configuracion()
 * @var callable $err
 */
defined('EVENTOS_TIC') || exit;

[$tituloImagen, $ayudaImagen] = $imagenes[$ranura];
$cargada = (string) $config[$ranura . '_imagen'];
$campo = $ranura . '_imagen';
?>
<div class="stack stack--2 config-imagen-certificado" data-imagen-certificado="<?= e($ranura) ?>">
  <?php if ($cargada !== ''): ?>
    <img class="config-banner__vista<?= $ranura === 'fondo' ? ' config-banner__vista--hoja' : '' ?>"
         src="<?= e(u('/admin/configuracion/certificados/imagen/' . $ranura, ['v' => substr(md5($cargada), 0, 10)])) ?>"
         alt="<?= e($tituloImagen) ?> que está cargada">
    <label class="row" style="gap:8px;cursor:pointer">
      <input type="checkbox" name="quitar_<?= e($ranura) ?>" value="1" style="width:18px;height:18px;accent-color:var(--c-accent)">
      <span class="help" style="margin:0">Quitar esta imagen</span>
    </label>
  <?php endif; ?>
  <div class="field">
    <label class="label" for="<?= e($campo) ?>">
      <?= e($cargada !== '' ? 'Cambiar: ' . mb_strtolower($tituloImagen) : $tituloImagen) ?>
      <span class="muted" style="text-transform:none;letter-spacing:normal">(JPG, PNG o WEBP, máximo 6 MB)</span>
    </label>
    <input class="input<?= $err($campo) ? ' is-invalid' : '' ?>" type="file" id="<?= e($campo) ?>"
           name="<?= e($campo) ?>" accept="image/jpeg,image/png,image/webp">
    <?php if ($err($campo)): ?><span class="error"><?= e($err($campo)) ?></span><?php endif; ?>
    <span class="help"><?= e($ayudaImagen) ?></span>
  </div>
</div>
