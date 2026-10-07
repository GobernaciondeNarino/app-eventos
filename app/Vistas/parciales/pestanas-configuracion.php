<?php
/**
 * Las pestañas del módulo Configuración.
 *
 * Son enlaces y no botones de una sola página: cada pestaña es su propia
 * pantalla con su propio formulario y su botón de guardar. Así se puede abrir
 * una directamente, guardarla en marcadores, y guardar una no manda lo que se
 * estaba cambiando en otra.
 *
 * Quien no es administrador ve solo «Mi cuenta»: el resto cambia lo que ve todo
 * el evento.
 *
 * @var string $pestana la activa: registro, expositores, identidad, acceso o cuenta
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Guardia;

$pestanasConfiguracion = [
    'registro'  => ['Registro', '/admin/configuracion/registro', 'administrador'],
    // El formulario privado de expositores: no está en ningún menú público,
    // así que esta pestaña es donde se consigue su enlace.
    'expositores' => ['Registro de expositores', '/admin/configuracion/expositores', 'administrador'],
    'identidad' => ['Identidad', '/admin/identidad', 'administrador'],
    'acceso'    => ['Acceso y correo', '/admin/autenticacion', 'administrador'],
    'cuenta'    => ['Mi cuenta', '/admin/cuenta', 'consulta'],
];
?>
<nav class="tabs tabs--enlaces" aria-label="Secciones de la configuración">
  <?php foreach ($pestanasConfiguracion as $clave => [$etiqueta, $ruta, $rol]): ?>
    <?php if (Guardia::puede($rol)): ?>
      <a class="tabs__btn<?= $clave === $pestana ? ' is-active' : '' ?>" href="<?= e(u($ruta)) ?>"
         <?= $clave === $pestana ? 'aria-current="page"' : '' ?>><?= e($etiqueta) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
