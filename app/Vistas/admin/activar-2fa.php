<?php
/**
 * Alta del segundo factor.
 *
 * @var string $secreto @var string $qr @var array $errores @var array $avisos @var int $horaServidor
 */
defined('EVENTOS_TIC') || exit;

guiones('segundo-factor.js');
$avisos = $avisos ?? [];
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner" style="max-width:480px">

    <div class="stack stack--2">
      <span class="kicker">Paso obligatorio</span>
      <h1 style="font-size:24px">Activa la verificación en dos pasos</h1>
      <p class="help">
        Tu cuenta puede exportar datos personales de todos los asistentes y cambiar la
        configuración del evento. Por eso el segundo factor no es opcional.
      </p>
    </div>

    <div class="card">
      <div class="card__head"><span>1 · Escanea con tu aplicación</span></div>
      <div class="card__body stack stack--3" style="align-items:center">
        <div class="qr-frame" style="width:210px"><?= $qr ?></div>
        <p class="help text-center">
          Sirve Google Authenticator, Authy, FreeOTP o el gestor de contraseñas de la entidad.
        </p>
        <hr class="divider" style="width:100%">
        <p class="help text-center">
          ¿No puedes escanear? Escribe este código a mano:<br>
          <span class="mono" style="font-size:14px;letter-spacing:.14em;color:var(--c-title)"><?= e($secreto) ?></span>
        </p>
      </div>
    </div>

    <form class="card" method="post" action="<?= e(u('/admin/activar-2fa')) ?>" novalidate>
      <?= testigo() ?>
      <div class="card__head"><span>2 · Confirma que funciona</span></div>
      <div class="card__body stack stack--4">
        <?php foreach ($avisos as $texto): ?>
          <div class="notice notice--warn" role="status">
            <span class="notice__icon" aria-hidden="true">i</span>
            <span><?= e($texto) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (isset($errores['codigo'])): ?>
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span><?= e($errores['codigo']) ?></span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="codigo"><?= $avisos ? 'El código siguiente que muestra la aplicación' : 'Código que muestra la aplicación' ?></label>
          <input class="input campo-otp" id="codigo" name="codigo" inputmode="numeric"
                 maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                 placeholder="000000" autofocus required>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Activar y entrar</button>

        <?php if (isset($horaServidor)): ?>
          <p class="help" data-hora-servidor="<?= (int) $horaServidor ?>">
            Hora de este servidor: <span class="mono"><?= e(date('H:i:s', (int) $horaServidor)) ?></span>.
          </p>
          <p class="help" data-reloj-aviso hidden></p>
        <?php endif; ?>
      </div>
    </form>

    <form method="post" action="<?= e(u('/admin/salir')) ?>" class="acceso__pie">
      <?= testigo() ?>
      <button class="btn btn--sm" type="submit">Salir</button>
    </form>

  </div>
</main>
