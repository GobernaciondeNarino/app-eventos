<?php
/**
 * Crear el acceso con solo correo y contraseña.
 *
 * Deliberadamente corta: tres campos y una casilla. Los datos del registro se
 * piden después, ya dentro, donde la persona no está de pie en una fila.
 *
 * @var string $correo @var string $destino @var array $errores @var int $claveMinima
 */
defined('EVENTOS_TIC') || exit;

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');

guiones('claves.js');
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1>Crea tu acceso</h1>
    <p class="lead">
      Con tu correo y una contraseña quedas dentro. Los datos del registro los completas
      enseguida, sin fila y sin afán.
    </p>
  </div>

  <?php if ($err('general')): ?>
    <div class="notice notice--danger">
      <span class="notice__icon" aria-hidden="true">▲</span><span><?= e($err('general')) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($err('ofrecer_acceso')): ?>
    <div class="notice notice--warn">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:6px">
        <span><?= e($err('correo')) ?></span>
        <span><a href="<?= e(u('/entrar', ['metodo' => 'clave', 'destino' => $destino])) ?>">Entrar con ese correo ›</a></span>
      </span>
    </div>
  <?php endif; ?>

  <form class="card" method="post" action="<?= e(u('/entrar/crear')) ?>" novalidate>
    <?= testigo() ?>
    <input type="hidden" name="destino" value="<?= e($destino) ?>">

    <div class="card__head"><span>Datos de acceso</span></div>
    <div class="card__body stack stack--4">

      <div class="field">
        <label class="label" for="correo">Correo electrónico</label>
        <input class="input<?= $err('correo') ? ' is-invalid' : '' ?>" type="email"
               id="correo" name="correo" value="<?= e($correo) ?>" autocomplete="email"
               inputmode="email" placeholder="nombre@entidad.gov.co" autofocus required>
        <?php if ($err('correo') && !$err('ofrecer_acceso')): ?>
          <span class="error"><?= e($err('correo')) ?></span>
        <?php endif; ?>
      </div>

      <div class="field">
        <label class="label" for="clave">Contraseña</label>
        <input class="input<?= $err('clave') ? ' is-invalid' : '' ?>" type="password"
               id="clave" name="clave" autocomplete="new-password"
               minlength="<?= e((string) $claveMinima) ?>" maxlength="200" required data-clave-nueva>
        <?php if ($err('clave')): ?><span class="error"><?= e($err('clave')) ?></span><?php endif; ?>
        <span class="help">Mínimo <?= e((string) $claveMinima) ?> caracteres.</span>
      </div>

      <div class="field">
        <label class="label" for="clave2">Repítela</label>
        <input class="input<?= $err('clave2') ? ' is-invalid' : '' ?>" type="password"
               id="clave2" name="clave2" autocomplete="new-password" maxlength="200" required data-clave-repetir>
        <?php if ($err('clave2')): ?><span class="error"><?= e($err('clave2')) ?></span><?php endif; ?>
        <!-- Lo escribe claves.js mientras se teclea. Nace vacío: sin JavaScript
             no aparece nada y el servidor valida igual. -->
        <span class="campo-estado" data-clave-estado role="status" aria-live="polite"></span>
      </div>

      <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer;flex-wrap:nowrap">
        <input type="checkbox" id="habeas" name="habeas" value="1"
               style="margin-top:3px;width:18px;height:18px;flex:none;accent-color:var(--c-accent)">
        <span class="help" style="flex:1">
          Autorizo el tratamiento de mis datos personales por parte de la Gobernación de
          Nariño para la gestión de este evento, conforme a la Ley 1581 de 2012.
        </span>
      </label>
      <?php if ($err('habeas')): ?><span class="error"><?= e($err('habeas')) ?></span><?php endif; ?>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Crear mi acceso</button>

      <p class="help" style="margin:0">
        ¿Prefieres llenarlo todo de una vez?
        <a href="<?= e(u('/registro')) ?>">Abre el formulario completo</a>.
      </p>
    </div>
  </form>

  <p class="text-center">
    <a href="<?= e(u('/entrar', ['destino' => $destino])) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Ya tengo acceso ›</a>
  </p>
</div>
