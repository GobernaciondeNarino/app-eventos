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
 * @var bool       $puedeRegistrar   operador de acceso o administrador: registra igual (3.18)
 * @var bool       $esAdministrador  el que abre, oculta o cierra el registro
 */
defined('EVENTOS_TIC') || exit;

$puedeRegistrar = $puedeRegistrar ?? false;
$esAdministrador = $esAdministrador ?? false;
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
    <!-- Alguien del equipo. Cerrado para el público, no para el equipo: el
         operador de acceso y el administrador siguen registrando a quien llegue
         sin registro. -->
    <section class="card">
      <div class="card__head"><span>Eres del equipo</span></div>
      <div class="card__body stack stack--4">
        <?php if ($puedeRegistrar): ?>
          <p class="help" style="margin:0">
            Con el registro cerrado, el operador de acceso y el administrador siguen registrando a
            quien llegue sin registro. Lo llenas con la persona y su carnet le llega al correo.
          </p>
          <a class="btn btn--block" href="<?= e(u('/admin/registros/nuevo')) ?>" data-registro-equipo>Registrar a una persona</a>
        <?php else: ?>
          <p class="help" style="margin:0">
            Registrar a quien llegue sin registro les corresponde al operador de acceso y al
            administrador.
          </p>
        <?php endif; ?>
        <?php if ($esAdministrador): ?>
          <p class="help" style="margin:0">
            El registro se abre, se oculta o se cierra en
            <a href="<?= e(u('/admin/configuracion/registro')) ?>#estado-registro">Configuración → Registro</a>.
          </p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <p class="text-center">
    <a href="<?= e(u('/')) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Volver al inicio ›</a>
  </p>
</div>
