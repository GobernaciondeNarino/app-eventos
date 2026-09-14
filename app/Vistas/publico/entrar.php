<?php
/**
 * Acceso del asistente, paso 1.
 *
 * @var string $correo @var string $destino @var array $errores
 * @var string $metodo @var array $metodos @var array $catalogo
 * @var bool $puedeCrearCuenta
 */
defined('EVENTOS_TIC') || exit;

$metodos = $metodos ?? ['correo'];
$catalogo = $catalogo ?? [];
$metodo = $metodo ?? 'correo';
$varios = count($metodos) > 1;

// Qué dice el botón y la explicación, según por dónde va a llegar.
$titulos = [
    'correo'   => ['Entra con tu correo', 'Te enviamos un código de seis dígitos al buzón con el que te registraste.'],
    'clave'    => ['Entra con tu contraseña', 'La que elegiste al registrarte.'],
    'whatsapp' => ['Entra por WhatsApp', 'Te enviamos un código de seis dígitos al WhatsApp del teléfono que registraste.'],
    'sms'      => ['Entra por mensaje de texto', 'Te enviamos un código de seis dígitos al teléfono que registraste.'],
    'qr'       => ['Entra con tu QR', 'Escanea el código de tu escarapela con la cámara del teléfono.'],
];
[$titulo, $explicacion] = $titulos[$metodo] ?? $titulos['correo'];

$rotuloBoton = match ($metodo) {
    'clave' => 'Entrar',
    default => 'Enviarme el código',
};
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1><?= e($titulo) ?></h1>
    <p class="lead"><?= e($explicacion) ?></p>
  </div>

  <?php if ($varios): ?>
    <?php /* Varias formas de entrar significa que si una falla —el correo, sin ir más lejos—
             el evento sigue funcionando. Se ofrecen todas, sin esconder ninguna. */ ?>
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <?php foreach ($metodos as $m): ?>
        <a class="btn<?= $m === $metodo ? ' btn--primary' : '' ?>"
           style="font-size:13px;padding:8px 14px"
           href="<?= e(u('/entrar', ['metodo' => $m, 'destino' => $destino])) ?>">
          <?= e($catalogo[$m]['nombre'] ?? $m) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($metodo === 'qr'): ?>

    <section class="card">
      <div class="card__head"><span>Escanea tu credencial</span></div>
      <div class="card__body stack stack--3">
        <p style="margin:0">
          Abre la cámara del teléfono y apúntala al código QR de tu escarapela. Se abrirá tu
          carnet directamente, sin pedirte nada más.
        </p>
        <p class="help" style="margin:0">
          ¿No tienes la escarapela a mano? Entra por otro método, o acércate al punto de
          información: te la reimprimen en el momento.
        </p>
      </div>
    </section>

  <?php else: ?>

    <form class="card" method="post" action="<?= e(u('/entrar')) ?>" novalidate>
      <?= testigo() ?>
      <input type="hidden" name="destino" value="<?= e($destino) ?>">
      <input type="hidden" name="metodo" value="<?= e($metodo) ?>">

      <div class="card__head"><span>Identifícate</span></div>
      <div class="card__body stack stack--4">
        <div class="field">
          <label class="label" for="correo">Correo con el que te registraste</label>
          <input class="input<?= isset($errores['correo']) ? ' is-invalid' : '' ?>" type="email"
                 id="correo" name="correo" value="<?= e($correo) ?>" autocomplete="email"
                 inputmode="email" placeholder="nombre@entidad.gov.co" autofocus required>
          <?php if (isset($errores['correo'])): ?><span class="error"><?= e($errores['correo']) ?></span><?php endif; ?>
        </div>

        <?php if ($metodo === 'clave'): ?>
          <div class="field">
            <label class="label" for="clave">Tu contraseña</label>
            <input class="input<?= isset($errores['clave']) ? ' is-invalid' : '' ?>" type="password"
                   id="clave" name="clave" autocomplete="current-password" required>
            <?php if (isset($errores['clave'])): ?><span class="error"><?= e($errores['clave']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($metodo === 'whatsapp' || $metodo === 'sms'): ?>
          <p class="help" style="margin:0">
            El código va al teléfono que registraste, no al correo. Si lo cambiaste, acércate al
            punto de información.
          </p>
        <?php endif; ?>

        <button class="btn btn--primary btn--block btn--lg" type="submit"><?= e($rotuloBoton) ?></button>

        <hr class="divider">

        <?php /* La salida de quien todavía no está inscrito. Va como botón y no
                 como enlace en un párrafo: es la mitad de la gente que llega a
                 esta pantalla, y antes se topaba con un formulario de tres
                 secciones como única puerta. */ ?>
        <div class="stack stack--2">
          <p class="help" style="margin:0">¿Todavía no te has registrado?</p>
          <?php if ($puedeCrearCuenta): ?>
            <a class="btn btn--block" href="<?= e(u('/entrar/crear', ['destino' => $destino])) ?>">
              Crear mi acceso en un minuto
            </a>
            <p class="help" style="margin:0">
              Correo y contraseña, nada más. El resto de los datos los completas ya dentro, o
              llena ahora el <a href="<?= e(u('/registro')) ?>">formulario completo</a>.
            </p>
          <?php else: ?>
            <a class="btn btn--block" href="<?= e(u('/registro')) ?>">Abrir el formulario de registro</a>
          <?php endif; ?>
        </div>
      </div>
    </form>

  <?php endif; ?>

  <p class="text-center">
    <a href="<?= e(u('/admin/entrar')) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Soy del equipo organizador ›</a>
  </p>
</div>
