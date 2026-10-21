<?php
/**
 * La contraseña nueva del panel, desde el enlace del correo.
 *
 * No pide la actual —para eso está el enlace— y no abre sesión al guardar:
 * el enlace demuestra que se controla el buzón, no el teléfono, así que
 * después se entra con la contraseña nueva y el segundo factor de siempre.
 *
 * @var string $accion @var string $correoOculto @var array $errores
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');

guiones('claves.js');
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1><?= e($evento['nombre'] ?? 'Plataforma de Eventos TIC') ?></h1>
      <span class="kicker">Contraseña nueva</span>
    </div>

    <form class="card" method="post" action="<?= e(u($accion)) ?>" novalidate>
      <?= testigo() ?>

      <div class="card__head"><span>Para <?= e($correoOculto) ?></span></div>
      <div class="card__body stack stack--4">

        <div class="field">
          <label class="label" for="nueva">Contraseña nueva</label>
          <input class="input<?= $err('nueva') ? ' is-invalid' : '' ?>" id="nueva" name="nueva"
                 type="password" autocomplete="new-password" minlength="12" maxlength="200"
                 autofocus required data-clave-nueva>
          <span class="help">
            Al menos 12 caracteres. Una frase que puedas recordar es mejor que una palabra
            con símbolos: «la puerta del recinto abre a las siete».
          </span>
          <?php if ($err('nueva')): ?><span class="error"><?= e($err('nueva')) ?></span><?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="nueva2">Repite la contraseña nueva</label>
          <input class="input<?= $err('nueva2') ? ' is-invalid' : '' ?>" id="nueva2" name="nueva2"
                 type="password" autocomplete="new-password" maxlength="200" required data-clave-repetir>
          <?php if ($err('nueva2')): ?><span class="error"><?= e($err('nueva2')) ?></span><?php endif; ?>
          <span class="campo-estado" data-clave-estado role="status" aria-live="polite"></span>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Guardar la contraseña</button>

        <p class="help">
          Al guardarla se cierran todas tus sesiones abiertas. Luego entras con la contraseña
          nueva y, si tienes activada la verificación en dos pasos, con el código de tu
          aplicación, como siempre.
        </p>
      </div>
    </form>

    <p class="acceso__pie">
      <a href="<?= e(u('/admin/entrar')) ?>">Volver al acceso</a>
    </p>

  </div>
</main>
