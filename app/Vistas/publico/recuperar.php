<?php
/**
 * Pedir un enlace para poner una contraseña nueva.
 *
 * Después de pedirlo, la pantalla dice lo mismo exista o no el correo: si
 * dijera «ese correo no está registrado», cualquiera podría averiguar quién
 * se inscribió al evento probando direcciones.
 *
 * @var string $correo @var array $errores @var bool $enviado @var int $minutos
 * @var bool $codigoActivo
 */
defined('EVENTOS_TIC') || exit;

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1><?= $enviado ? 'Revisa tu correo' : 'Recupera tu contraseña' ?></h1>
    <p class="lead">
      <?= $enviado
          ? 'Si ese correo está registrado en el evento, en unos minutos te llega un enlace para poner tu contraseña nueva.'
          : 'Escribe el correo con el que te registraste y te enviamos un enlace para poner una contraseña nueva.' ?>
    </p>
  </div>

  <?php if ($enviado): ?>

    <section class="card" data-recuperar-enviado>
      <div class="card__head"><span>Enlace en camino</span></div>
      <div class="card__body stack stack--3">
        <p style="margin:0">
          Ábrelo desde este mismo teléfono o computador. Vence en <?= e((string) $minutos) ?> minutos
          y sirve una sola vez; si pides otro, el anterior deja de servir.
        </p>
        <p class="help" style="margin:0">
          ¿No llega? Revisa las carpetas de no deseado y de promociones. Si te registraste con
          otra dirección, pide el enlace con esa.
        </p>
        <div class="row">
          <a class="btn btn--primary" href="<?= e(u('/entrar', ['metodo' => 'clave'])) ?>">Volver a entrar</a>
          <a class="btn" href="<?= e(u('/entrar/recuperar')) ?>">Pedir otro enlace</a>
        </div>
      </div>
    </section>

  <?php else: ?>

    <form class="card" method="post" action="<?= e(u('/entrar/recuperar')) ?>" novalidate>
      <?= testigo() ?>

      <div class="card__head"><span>Tu correo</span></div>
      <div class="card__body stack stack--4">
        <div class="field">
          <label class="label" for="correo">Correo con el que te registraste</label>
          <input class="input<?= $err('correo') ? ' is-invalid' : '' ?>" type="email"
                 id="correo" name="correo" value="<?= e($correo) ?>" autocomplete="email"
                 inputmode="email" placeholder="nombre@entidad.gov.co" autofocus required>
          <?php if ($err('correo')): ?><span class="error"><?= e($err('correo')) ?></span><?php endif; ?>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Enviarme el enlace</button>

        <p class="help" style="margin:0">
          Tu contraseña actual sigue sirviendo hasta que pongas la nueva.
        </p>
      </div>
    </form>

  <?php endif; ?>

  <?php if ($codigoActivo): ?>
    <?php /* Quien está en la fila de la puerta no tiene por qué esperar un enlace:
             con el código al correo entra en un minuto y pone la contraseña
             después, en «Mis datos». */ ?>
    <p class="help text-center" style="margin:0">
      ¿Te urge entrar? <a href="<?= e(u('/entrar', ['metodo' => 'correo'])) ?>">Pide un código a tu correo</a>
      y entra sin contraseña.
    </p>
  <?php endif; ?>

  <p class="text-center">
    <a href="<?= e(u('/entrar', ['metodo' => 'clave'])) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Ya la recordé ›</a>
  </p>
</div>
