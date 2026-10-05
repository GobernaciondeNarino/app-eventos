<?php
/** Equipo organizador. @var array $equipo */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Usuario;

$columnas = 'grid-template-columns:1.4fr 1.35fr 1fr .7fr 1.15fr';
$permisos = [
    ['Administrador', ['Configurar el evento y la identidad', 'Aprobar propuestas',
                       'Exportar con caracterización', 'Gestionar el equipo']],
    ['Operador de acceso', ['Escanear carnets y sellar ingresos', 'Buscar a un asistente para acreditarlo']],
    ['Consulta', ['Ver indicadores y registros', 'Exportar el listado sin datos sensibles']],
];
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Equipo organizador</h1>
      <p class="help">Quiénes pueden registrar ingresos, consultar registros o cambiar la configuración.</p>
    </div>
    <button class="btn btn--sm btn--primary" type="button" data-abrir-modal="modal-nuevo">Agregar persona</button>
  </div>

  <div class="split" style="align-items:start">
    <div class="table">
      <div class="table__scroll">
        <div class="table__grid" style="min-width:760px">
          <div class="table__head" style="<?= $columnas ?>">
            <span>Persona</span><span>Rol</span><span>Puesto</span>
            <span>Escaneos hoy</span><span>Estado</span>
          </div>

          <?php foreach ($equipo as $u): ?>
            <div class="table__row" style="<?= $columnas ?>">
              <div class="stack" style="gap:2px;min-width:0">
                <strong style="font-weight:500;color:var(--c-title)"><?= e($u['nombre']) ?></strong>
                <span class="mono muted" style="font-size:11px"><?= e($u['correo']) ?></span>
              </div>
              <?php if ((int) $u['id'] === (int) $usuario['id']): ?>
                <!-- El rol propio no se cambia desde aquí: quitárselo uno mismo
                     es la forma más rápida de quedarse fuera del panel sin
                     nadie que pueda devolvérselo. -->
                <span><span class="tag <?= $u['rol'] === 'administrador' ? 'tag--warn' : 'tag--mute' ?>">
                  <?= e(ucfirst((string) $u['rol'])) ?> · tú
                </span></span>
              <?php else: ?>
                <form method="post" action="<?= e(u('/admin/organizadores/rol')) ?>"
                      data-confirmar="Se cambiará el rol de <?= e($u['nombre']) ?> y tendrá que volver a entrar. ¿Continuar?">
                  <?= testigo() ?>
                  <input type="hidden" name="usuario" value="<?= (int) $u['id'] ?>">
                  <label class="sr-only" for="rol-<?= (int) $u['id'] ?>">Rol de <?= e($u['nombre']) ?></label>
                  <div class="row" style="flex-wrap:nowrap;gap:6px">
                    <select class="select select--sm" id="rol-<?= (int) $u['id'] ?>" name="rol">
                      <?php foreach ([
                        'administrador' => 'Administrador',
                        'operador'      => 'Operador',
                        'consulta'      => 'Consulta',
                      ] as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $u['rol'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn--sm" type="submit">Cambiar</button>
                  </div>
                </form>
              <?php endif; ?>
              <span style="color:var(--c-text)"><?= e($u['puesto'] ?: '—') ?></span>
              <span class="mono accent"><?= e(numero($u['escaneos_hoy'])) ?></span>
              <div class="row" style="gap:6px">
                <span class="tag <?= $u['estado'] === 'activo' ? 'tag--ok' : 'tag--danger' ?>">
                  <?= $u['estado'] === 'activo' ? 'Activo' : 'Suspendido' ?>
                </span>
                <?php $estado2fa = Usuario::estadoSegundoFactor($u); ?>
                <?php if ($estado2fa === 'ilegible'): ?>
                  <span class="tag tag--danger" title="La configuración guardada no se puede leer con la llave actual">2FA ilegible</span>
                <?php elseif (!Usuario::tieneSegundoFactor($u) && $u['rol'] === 'administrador'): ?>
                  <span class="tag tag--danger" title="Todavía no ha activado el segundo factor">Sin 2FA</span>
                <?php endif; ?>
                <?php if ((int) $u['id'] !== (int) $usuario['id'] && $estado2fa !== 'ninguno'): ?>
                  <form method="post" action="<?= e(u('/admin/organizadores/segundo-factor')) ?>"
                        data-confirmar="Se quitará la verificación en dos pasos de <?= e($u['nombre']) ?> y se cerrarán sus sesiones. Al entrar con su contraseña escaneará un código QR nuevo. ¿Continuar?">
                    <?= testigo() ?>
                    <input type="hidden" name="usuario" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn--sm" type="submit" title="Para quien perdió el teléfono">Restablecer 2FA</button>
                  </form>
                <?php endif; ?>
                <?php if ((int) $u['id'] !== (int) $usuario['id']): ?>
                  <form method="post" action="<?= e(u('/admin/organizadores/estado')) ?>">
                    <?= testigo() ?>
                    <input type="hidden" name="usuario" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="estado" value="<?= $u['estado'] === 'activo' ? 'suspendido' : 'activo' ?>">
                    <button class="btn btn--sm" type="submit"><?= $u['estado'] === 'activo' ? 'Suspender' : 'Reactivar' ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table__foot">
        <span><?= e((string) count($equipo)) ?> personas en el equipo</span>
        <span>El carnet de organizador lleva rótulo verde</span>
      </div>
    </div>

    <div class="stack stack--3 is-sticky">
      <div class="card">
        <div class="card__head"><span>Permisos por rol</span></div>
        <div class="card__body stack stack--3">
          <?php foreach ($permisos as [$rol, $puede]): ?>
            <div class="stack" style="gap:6px;padding-bottom:12px;border-bottom:1px solid var(--hair-soft)">
              <strong style="font-family:var(--f-display);font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
                <?= e($rol) ?>
              </strong>
              <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.7;color:var(--c-text)">
                <?php foreach ($puede as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><span>Mínimo privilegio</span></div>
        <div class="card__body">
          <p class="help">
            El operador de acceso solo puede sellar ingresos: no ve la caracterización ni
            exporta con datos sensibles. Quien únicamente necesita reportes recibe el rol de
            consulta, sin capacidad de escribir nada.
          </p>
        </div>
      </div>
    </div>
  </div>

</div>

<div class="modal hidden" id="modal-nuevo" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Agregar persona al equipo">
    <div class="modal__head">
      <span>Equipo</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body">
      <h2 style="font-size:22px">Agregar persona al equipo</h2>
      <form method="post" action="<?= e(u('/admin/organizadores/crear')) ?>" class="stack stack--4">
        <?= testigo() ?>

        <div class="field">
          <label class="label" for="n-nombre">Nombre completo</label>
          <input class="input" id="n-nombre" name="nombre" required maxlength="160" placeholder="Ej. Andrea Lucía Erazo">
        </div>
        <div class="field">
          <label class="label" for="n-correo">Correo institucional</label>
          <input class="input" id="n-correo" name="correo" type="email" required placeholder="nombre@narino.gov.co">
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="n-rol">Rol</label>
            <select class="select" id="n-rol" name="rol">
              <option value="operador">Operador de acceso</option>
              <option value="consulta">Consulta</option>
              <option value="administrador">Administrador</option>
            </select>
          </div>
          <div class="field">
            <label class="label" for="n-puesto">Puesto</label>
            <input class="input" id="n-puesto" name="puesto" maxlength="80" placeholder="Ej. Puerta principal">
          </div>
        </div>
        <div class="field">
          <label class="label" for="n-clave">Contraseña inicial</label>
          <input class="input" id="n-clave" name="clave" type="password" minlength="12" required
                 autocomplete="new-password">
          <span class="help">Mínimo 12 caracteres. La persona deberá cambiarla al entrar.</span>
        </div>

        <div class="notice">
          <span class="notice__icon">◆</span>
          <span>
            Entrégale la contraseña por un canal seguro, nunca por correo junto con el
            enlace. Si el rol es administrador, tendrá que activar el segundo factor antes
            de poder usar el panel.
          </span>
        </div>

        <div class="row row--end">
          <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
          <button class="btn btn--primary" type="submit">Crear cuenta</button>
        </div>
      </form>
    </div>
  </div>
</div>
