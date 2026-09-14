<?php
/**
 * Alguien escaneó un carnet sin tener sesión abierta.
 *
 * No se revela de quién es la credencial: eso convertiría cualquier carnet
 * fotografiado en una consulta de datos personales. Solo se pregunta quién
 * está escaneando, para mandarlo al acceso que corresponde y volver aquí.
 *
 * @var string $destino
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
?>
<div class="view view--narrow stack stack--5" style="max-width:520px;margin:auto">

  <div class="stack stack--3" style="align-items:center;text-align:center">
    <?= $marca('lg') ?>
    <span class="kicker">Credencial del evento</span>
    <h1><?= e($evento['nombre'] ?? 'Eventos TIC') ?></h1>
    <p class="lead" style="max-width:42ch">
      Escaneaste el carnet de un asistente. Para continuar necesitamos saber quién eres.
    </p>
  </div>

  <div class="card">
    <div class="card__head"><span>¿Cómo participas en el evento?</span></div>
    <div class="card__body stack stack--4">

      <a class="notice" style="text-decoration:none;color:inherit;align-items:flex-start"
         href="<?= e(u('/entrar', ['destino' => $destino])) ?>">
        <span class="notice__icon" aria-hidden="true">›</span>
        <span class="stack" style="gap:4px">
          <strong style="font-family:var(--f-display);font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
            Soy asistente
          </strong>
          <span class="help">
            Entra con tu correo e intercambia datos de contacto con esta persona.
          </span>
        </span>
      </a>

      <a class="notice" style="text-decoration:none;color:inherit;align-items:flex-start"
         href="<?= e(u('/admin/entrar', ['destino' => $destino])) ?>">
        <span class="notice__icon" aria-hidden="true">›</span>
        <span class="stack" style="gap:4px">
          <strong style="font-family:var(--f-display);font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
            Soy del equipo organizador
          </strong>
          <span class="help">
            Entra con tu cuenta y registra el ingreso de esta persona a la jornada de hoy.
          </span>
        </span>
      </a>

      <hr class="divider">

      <p class="help">
        ¿Todavía no te has registrado en el evento?
        <a href="<?= e(u('/registro')) ?>">Regístrate</a> y obtén tu propio carnet.
      </p>
    </div>
  </div>
</div>
