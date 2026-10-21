<?php
/**
 * La contraseña nueva, desde el enlace que llegó al correo.
 *
 * Abrir esta pantalla no gasta el enlace; guardarla sí. Así sobrevive a los
 * antivirus del correo, que abren los enlaces antes que la persona.
 *
 * @var string $accion @var string $correoOculto @var array $errores @var int $claveMinima
 */
defined('EVENTOS_TIC') || exit;

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');

guiones('claves.js');
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1>Pon tu contraseña nueva</h1>
    <p class="lead">
      Para la cuenta de <span class="mono" style="font-size:.9em"><?= e($correoOculto) ?></span>.
      Al guardarla entras de una vez.
    </p>
  </div>

  <form class="card" method="post" action="<?= e(u($accion)) ?>" novalidate>
    <?= testigo() ?>

    <div class="card__head"><span>Contraseña nueva</span></div>
    <div class="card__body stack stack--4">

      <div class="field">
        <label class="label" for="clave">Contraseña nueva</label>
        <input class="input<?= $err('clave') ? ' is-invalid' : '' ?>" type="password"
               id="clave" name="clave" autocomplete="new-password"
               minlength="<?= e((string) $claveMinima) ?>" maxlength="200" autofocus required data-clave-nueva>
        <?php if ($err('clave')): ?><span class="error"><?= e($err('clave')) ?></span><?php endif; ?>
        <span class="help">Mínimo <?= e((string) $claveMinima) ?> caracteres.</span>
      </div>

      <div class="field">
        <label class="label" for="clave2">Repítela</label>
        <input class="input<?= $err('clave2') ? ' is-invalid' : '' ?>" type="password"
               id="clave2" name="clave2" autocomplete="new-password" maxlength="200" required data-clave-repetir>
        <?php if ($err('clave2')): ?><span class="error"><?= e($err('clave2')) ?></span><?php endif; ?>
        <!-- Lo escribe claves.js mientras se teclea. -->
        <span class="campo-estado" data-clave-estado role="status" aria-live="polite"></span>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Guardar y entrar</button>

      <p class="help" style="margin:0">
        Se cerrarán las sesiones que tengas abiertas en otros teléfonos o computadores: si alguien
        más estaba usando tu cuenta, deja de poder hacerlo.
      </p>
    </div>
  </form>
</div>
