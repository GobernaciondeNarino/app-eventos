<?php
/**
 * El equipo pide un enlace para cambiar la contraseña del panel.
 *
 * La respuesta es la misma exista o no la cuenta, y esté o no suspendida: la
 * lista del equipo no es algo que esta pantalla deba confirmarle a nadie.
 *
 * @var string $correo @var array $errores @var bool $enviado @var int $minutos
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1><?= e($evento['nombre'] ?? 'Plataforma de Eventos TIC') ?></h1>
      <span class="kicker">Recuperar mi contraseña</span>
    </div>

    <?php if ($enviado): ?>

      <div class="card" data-recuperar-enviado>
        <div class="card__head"><span>Revisa tu correo</span></div>
        <div class="card__body stack stack--3">
          <p style="margin:0">
            Si ese correo corresponde a una cuenta activa del equipo, en unos minutos te llega un
            enlace para cambiar la contraseña. Vence en <?= e((string) $minutos) ?> minutos y sirve
            una sola vez.
          </p>
          <p class="help" style="margin:0">
            Si tienes activada la verificación en dos pasos, al entrar con la contraseña nueva te
            pediremos también el código de tu aplicación. Si tampoco tienes el teléfono, pide a
            otra persona administradora que la restablezca desde «Organizadores».
          </p>
          <a class="btn btn--primary btn--block" href="<?= e(u('/admin/entrar')) ?>">Volver al acceso</a>
        </div>
      </div>

    <?php else: ?>

      <form class="card" method="post" action="<?= e(u('/admin/recuperar')) ?>" novalidate>
        <?= testigo() ?>

        <div class="card__head"><span>Tu correo del equipo</span></div>
        <div class="card__body stack stack--4">
          <p class="help" style="margin:0">
            Te enviamos un enlace para poner una contraseña nueva. La actual sigue sirviendo
            mientras no la cambies.
          </p>

          <div class="field">
            <label class="label" for="correo">Correo institucional</label>
            <input class="input<?= $err('correo') ? ' is-invalid' : '' ?>" id="correo" name="correo"
                   type="email" value="<?= e($correo) ?>" autocomplete="username" inputmode="email"
                   placeholder="nombre@narino.gov.co" autofocus required>
            <?php if ($err('correo')): ?><span class="error"><?= e($err('correo')) ?></span><?php endif; ?>
          </div>

          <button class="btn btn--primary btn--block btn--lg" type="submit">Enviarme el enlace</button>
        </div>
      </form>

    <?php endif; ?>

    <p class="acceso__pie">
      <a href="<?= e(u('/admin/entrar')) ?>">Ya la recordé ›</a>
    </p>

  </div>
</main>
