<?php
/**
 * El registro público, cerrado (Configuración → Registro, desde la 3.17).
 *
 * Lo ve quien llega a /registro sin sesión: por el menú de una página vieja,
 * por un enlace compartido, o con el formulario abierto desde antes de que se
 * cerrara. Quien ya se registró no llega aquí: con su sesión, /registro sigue
 * siendo «Mis datos».
 *
 * @var string     $mensajeRegistro  lo que escribió la organización, o ''
 * @var array|null $usuario          el equipo también puede abrir esta página
 */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--5" style="max-width:520px;margin:auto" data-registro-cerrado>

  <div class="stack stack--2">
    <span class="kicker">Registro cerrado</span>
    <h1>El registro está cerrado</h1>
    <p class="lead">Ya no se reciben inscripciones nuevas para este evento.</p>
  </div>

  <?php if ($mensajeRegistro !== ''): ?>
    <div class="notice notice--warn" data-mensaje-registro>
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span><?= nl2br(e($mensajeRegistro)) ?></span>
    </div>
  <?php endif; ?>

  <section class="card">
    <div class="card__head"><span>¿Ya te registraste?</span></div>
    <div class="card__body stack stack--4">
      <p class="help" style="margin:0">
        Entra con tu correo para ver tu carnet y completar tu perfil: tus datos y tu foto.
      </p>
      <p class="help" style="margin:0;color:var(--c-title)">
        Lleva tu carnet el día del evento —impreso o en el celular—: con él ingresas.
      </p>
      <a class="btn btn--primary btn--block btn--lg" href="<?= e(u('/entrar')) ?>">Entrar y ver mi carnet</a>
    </div>
  </section>

  <?php if ($usuario !== null): ?>
    <!-- Alguien del equipo que abrió el formulario para revisarlo. -->
    <div class="notice">
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Eres del equipo: el registro se abre, se oculta o se cierra en
        <a href="<?= e(u('/admin/configuracion/registro')) ?>#estado-registro">Configuración → Registro</a>.
      </span>
    </div>
  <?php endif; ?>

  <p class="text-center">
    <a href="<?= e(u('/')) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Volver al inicio ›</a>
  </p>
</div>
