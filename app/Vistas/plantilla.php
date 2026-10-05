<?php
/**
 * Plantilla general.
 *
 * Las variables de color del evento se imprimen aquí, en línea, antes de
 * cualquier contenido: así no hay un destello con la paleta por defecto antes
 * de que llegue la del evento.
 *
 * @var string      $contenido
 * @var array|null  $evento
 * @var array       $tema
 * @var array|null  $persona   asistente con sesión abierta
 * @var array|null  $usuario   miembro del equipo con sesión abierta
 * @var array|null  $aviso
 * @var string      $titulo
 * @var string      $pantalla
 */

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Tema;

$sinPlantilla = $sinPlantilla ?? false;
$pantalla = $pantalla ?? '';
$nombreEvento = $evento['nombre'] ?? 'Plataforma de Eventos TIC';
?><!DOCTYPE html>
<html lang="es" data-preset="<?= e($tema['preset']) ?>" data-tipografia="<?= e($tema['tipografia']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<?php if (!empty($noIndexar) || $usuario !== null): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= e(($titulo ?? '') !== '' ? $titulo . ' · ' . $nombreEvento : $nombreEvento) ?></title>
<link rel="icon" href="<?= e(recurso('assets/img/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/fonts/fuentes.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/print.css')) ?>">
<style><?= Tema::estilo($tema) /* solo hexadecimales validados */ ?></style>
</head>
<body data-pantalla="<?= e($pantalla) ?>" data-base="<?= e(u('/')) ?>">

<a class="skip-link" href="#contenido">Saltar al contenido</a>

<?php if ($sinPlantilla): ?>
  <?= $contenido /* la vista trae su propio armazón */ ?>
<?php else: ?>
<div class="app">
  <?php require __DIR__ . '/parciales/barra-lateral.php'; ?>

  <div class="app__body">
    <?php require __DIR__ . '/parciales/barra-superior.php'; ?>

    <main class="main" id="contenido">
      <?php if (!empty($esquemaAtrasado)): ?>
        <!-- La base se quedó atrás del código. Va aquí y no solo en el panel
             porque el camino que de verdad duele es otro: se suben los archivos
             nuevos, se entra directo a una pantalla que estrena columna, y la
             pantalla falla sin decir por qué. Lo ven el equipo y el staff; a un
             asistente no se le enseña, que no puede hacer nada con eso. -->
        <div class="view view--wide" style="padding-bottom:0">
          <div class="notice notice--warn">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span class="stack" style="gap:8px;flex:1">
              <strong>La base de datos está atrasada respecto al código</strong>
              <span class="help" style="margin:0"><?= e((string) ($esquemaMotivo ?? '')) ?></span>
              <span class="help" style="margin:0">
                Hasta que se actualice, las pantallas que estrenan columnas pueden fallar.
                Se agregan solo las tablas y columnas que falten para la versión
                <?= e((string) ($esquemaVersion ?? '')) ?>; no se borra ni se cambia nada de lo
                que ya hay, así que se puede hacer con el evento en curso.
              </span>
              <?php if ($usuario !== null && App\Nucleo\Guardia::puede('administrador')): ?>
                <form method="post" action="<?= e(u('/admin/actualizar-esquema')) ?>">
                  <?= testigo() ?>
                  <button class="btn btn--sm btn--primary" type="submit">Actualizar la base de datos</button>
                </form>
              <?php else: ?>
                <span class="help" style="margin:0">
                  Avísale a quien administra la plataforma: se actualiza desde el panel.
                </span>
              <?php endif; ?>
            </span>
          </div>
        </div>
      <?php endif; ?>
      <?= $contenido ?>
    </main>
  </div>
</div>

<?php require __DIR__ . '/parciales/nav-movil.php'; ?>
<?php endif; ?>

<?php if (!empty($aviso)): ?>
<div class="toast toast--<?= e($aviso['tipo']) ?>" role="status" aria-live="polite" data-autocerrar="5000">
  <?= e($aviso['mensaje']) ?>
</div>
<?php endif; ?>

<script src="<?= e(recurso('assets/js/app.js')) ?>" defer></script>
<?php foreach (App\Nucleo\Respuesta::guionesDeclarados() as $guion): ?>
<script src="<?= e(recurso('assets/js/' . $guion)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
