<?php
/**
 * Segundo factor.
 *
 * @var array  $errores
 * @var array  $avisos
 * @var bool   $ilegible         la configuración guardada no se puede descifrar
 * @var string $mensajeIlegible
 * @var bool   $porCorreo        se ofrece el código por correo
 * @var string $correoOculto
 * @var bool   $correoEnviado
 * @var bool   $resincronizando  se espera el segundo código de una resincronización
 * @var int    $horaServidor
 */
defined('EVENTOS_TIC') || exit;

guiones('segundo-factor.js');

$marca = require __DIR__ . '/../parciales/marca.php';
$avisos = $avisos ?? [];
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1>Verificación en dos pasos</h1>
      <span class="kicker">Segundo factor</span>
    </div>

    <?php foreach ($avisos as $texto): ?>
      <div class="notice notice--warn" role="status">
        <span class="notice__icon" aria-hidden="true">i</span>
        <span><?= e($texto) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($ilegible): ?>
      <div class="card">
        <div class="card__head"><span>No se puede leer tu configuración</span></div>
        <div class="card__body stack stack--3">
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span><?= e($mensajeIlegible) ?></span>
          </div>
        </div>
      </div>
    <?php else: ?>
      <form class="card" method="post" action="<?= e(u('/admin/verificar')) ?>" novalidate>
        <?= testigo() ?>
        <input type="hidden" name="accion" value="codigo">
        <div class="card__head"><span>Código de la aplicación</span></div>
        <div class="card__body stack stack--4">

          <?php if (isset($errores['codigo'])): ?>
            <div class="notice notice--danger">
              <span class="notice__icon" aria-hidden="true">▲</span>
              <span><?= e($errores['codigo']) ?></span>
            </div>
          <?php endif; ?>

          <div class="field">
            <label class="label" for="codigo">
              <?= $resincronizando
                  ? 'El código siguiente que muestra tu aplicación'
                  : 'Los seis dígitos que muestra tu aplicación' ?>
            </label>
            <input class="input campo-otp" id="codigo" name="codigo" inputmode="numeric"
                   maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                   placeholder="000000" <?= $correoEnviado ? '' : 'autofocus' ?> required>
          </div>

          <button class="btn btn--primary btn--block btn--lg" type="submit">Verificar y entrar</button>

          <p class="help" data-hora-servidor="<?= (int) $horaServidor ?>">
            El código cambia cada treinta segundos. Hora de este servidor:
            <span class="mono"><?= e(date('H:i:s', (int) $horaServidor)) ?></span>.
          </p>
          <p class="help" data-reloj-aviso hidden></p>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($porCorreo): ?>
      <div class="card">
        <div class="card__head">
          <span><?= $ilegible ? 'Entrar con un código por correo' : '¿Problemas con la aplicación?' ?></span>
        </div>
        <div class="card__body stack stack--3">
          <?php if (isset($errores['correo'])): ?>
            <div class="notice notice--danger">
              <span class="notice__icon" aria-hidden="true">▲</span>
              <span><?= e($errores['correo']) ?></span>
            </div>
          <?php endif; ?>

          <?php if ($correoEnviado): ?>
            <form method="post" action="<?= e(u('/admin/verificar')) ?>" class="stack stack--3" novalidate>
              <?= testigo() ?>
              <input type="hidden" name="accion" value="codigo_correo">
              <?php if (isset($errores['codigo_correo'])): ?>
                <div class="notice notice--danger">
                  <span class="notice__icon" aria-hidden="true">▲</span>
                  <span><?= e($errores['codigo_correo']) ?></span>
                </div>
              <?php endif; ?>
              <div class="field">
                <label class="label" for="codigo_correo">
                  Código que llegó a <span style="text-transform:none"><?= e($correoOculto) ?></span>
                </label>
                <input class="input campo-otp" id="codigo_correo" name="codigo_correo" inputmode="numeric"
                       maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                       placeholder="000000" autofocus required>
              </div>
              <button class="btn btn--primary btn--block" type="submit">Entrar con el código del correo</button>
            </form>
          <?php elseif (isset($errores['codigo_correo'])): ?>
            <div class="notice notice--danger">
              <span class="notice__icon" aria-hidden="true">▲</span>
              <span><?= e($errores['codigo_correo']) ?></span>
            </div>
          <?php endif; ?>

          <?php if (!$correoEnviado): ?>
            <p class="help">
              <?= $ilegible
                  ? 'Te llega un código de seis dígitos a ' . e($correoOculto) . '. Después de entrar, configuras otra vez la aplicación del teléfono.'
                  : 'Si cambiaste de teléfono o la aplicación no muestra el código de esta cuenta, entra con uno que te llega a ' . e($correoOculto) . '. Después puedes restablecer la aplicación desde «Configuración».' ?>
            </p>
          <?php endif; ?>
          <form method="post" action="<?= e(u('/admin/verificar')) ?>">
            <?= testigo() ?>
            <input type="hidden" name="accion" value="enviar_correo">
            <button class="btn btn--block" type="submit">
              <?= $correoEnviado ? 'Enviar otro código' : 'Enviar un código a mi correo' ?>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(u('/admin/salir')) ?>" class="acceso__pie">
      <?= testigo() ?>
      <button class="btn btn--sm" type="submit">Cancelar y salir</button>
    </form>

  </div>
</main>
