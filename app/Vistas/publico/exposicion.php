<?php
/**
 * «Mi exposición»: la propuesta de quien expone, para enviarla si se registró
 * sin ella, corregirla y cambiar sus documentos, aparte de sus datos
 * personales.
 *
 * @var array|null $propuesta con el día, la hora y el salón, si ya está aprobada
 * @var bool $puedeProponer @var bool $aprobada @var array $valores @var array $errores
 * @var \App\Modelos\Formulario $formulario @var array $categorias @var array $duraciones
 * @var array $jornadas @var array $documentos @var bool $reelegir
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Documento;

$f = $formulario;
$v = static fn(string $clave, string $porDefecto = ''): string => (string) ($valores[$clave] ?? $porDefecto);
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
$estado = (string) ($propuesta['estado'] ?? '');

// Lo que significa cada estado para quien expone, en sus palabras.
[$etiquetaEstado, $claseEstado, $explicacion] = [
    'pendiente' => ['En revisión', 'tag--warn',
        'El comité la está revisando. Puedes cambiarla mientras tanto: sigue en revisión.'],
    'observada' => ['Con observaciones', '',
        'El comité te pide unos ajustes. Corrige lo que te dice abajo y guarda: vuelve a revisión.'],
    'aprobada'  => ['Aprobada', 'tag--ok',
        'Ya está publicada en la agenda. Puedes reemplazar tus documentos —por ejemplo, con la presentación final— y cambiar los requerimientos.'],
    'rechazada' => ['No seleccionada', 'tag--danger',
        'Esta vez el comité no la incluyó en la agenda. Si la cambias y la guardas, vuelve a revisión.'],
][$estado] ?? ['Sin enviar', 'tag--mute', ''];
$bloqueada = $aprobada ? 'disabled' : '';
?>
<form class="view view--narrow stack stack--4" method="post" action="<?= e(u('/exposicion')) ?>"
      enctype="multipart/form-data" novalidate>
  <?= testigo() ?>

  <div class="stack stack--2">
    <span class="kicker">Expositores</span>
    <h1>Mi exposición</h1>
    <p class="help">
      Tu propuesta y tus documentos. Lo que cambies aquí no toca tus datos personales: esos
      están en <a href="<?= e(u('/registro')) ?>">Mis datos</a>.
    </p>
  </div>

  <?php if ($propuesta !== null): ?>
    <section class="card">
      <div class="card__head">
        <span>Estado de tu propuesta</span>
        <span class="tag <?= e($claseEstado) ?>"><?= e($etiquetaEstado) ?></span>
      </div>
      <div class="card__body stack stack--3">
        <p class="help" style="margin:0"><?= e($explicacion) ?></p>

        <?php if ($aprobada && $propuesta['dia_asignado'] === null): ?>
          <p class="help" style="margin:0;color:var(--c-title)">
            La organización te confirmará el día, la hora y el salón.
          </p>
        <?php elseif ($aprobada): ?>
          <div class="stack" style="gap:0">
            <div class="kv"><span>Día</span>
              <strong>Día <?= e((string) $propuesta['dia_asignado']) ?> · <?= e(fecha((string) $propuesta['fecha_asignada'])) ?></strong></div>
            <div class="kv"><span>Hora</span>
              <strong><?= e(substr((string) $propuesta['hora_inicio'], 0, 5)) ?></strong></div>
            <div class="kv"><span>Salón</span>
              <strong><?= e((string) $propuesta['salon'] !== '' ? (string) $propuesta['salon'] : 'Te lo confirma la organización') ?></strong></div>
          </div>
        <?php endif; ?>

        <?php if (in_array($estado, ['observada', 'rechazada'], true) && trim((string) $propuesta['observacion']) !== ''): ?>
          <div class="notice notice--warn">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span class="stack" style="gap:6px">
              <strong>Lo que dice el comité</strong>
              <span><?= nl2br(e((string) $propuesta['observacion'])) ?></span>
            </span>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!$puedeProponer): ?>
    <div class="notice">
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Por ahora este evento no está recibiendo propuestas por aquí. Si la organización te
        invitó a exponer, entra por el enlace que te envió.
      </span>
    </div>
  <?php else: ?>

    <?php if ($err('general')): ?>
      <div class="notice notice--danger" role="alert">
        <span class="notice__icon" aria-hidden="true">▲</span><span><?= e($err('general')) ?></span>
      </div>
    <?php endif; ?>

    <!-- ================= Datos de la exposición ================= -->
    <section class="card">
      <div class="card__head"><span><?= $propuesta === null ? 'Tu propuesta' : 'Datos de la exposición' ?></span></div>
      <div class="card__body stack stack--4">
        <?php if ($propuesta === null): ?>
          <p class="help" style="margin:0">
            Cuéntanos qué vas a presentar —una charla, un stand o una demostración— y adjunta tus
            dos documentos. El comité la revisa y te avisa por correo.
          </p>
        <?php endif; ?>

        <?php if ($aprobada): ?>
          <!-- Aprobada, el tema y el horario ya están publicados: los cambia el
               comité. Decirlo evita que alguien escriba, guarde y crea que
               cambió algo que no cambió. -->
          <div class="notice">
            <span class="notice__icon" aria-hidden="true">◆</span>
            <span>
              Como ya está en la agenda, el tema, la categoría, el detalle, el día y la duración
              los cambia el comité: escríbele a la organización si necesitas mover algo.
            </span>
          </div>
        <?php endif; ?>

        <div class="grid-2" style="grid-template-columns:1.4fr 1fr">
          <div class="field">
            <label class="label" for="tema">Tema de la exposición <span class="req">*</span></label>
            <input class="input<?= $err('tema') ? ' is-invalid' : '' ?>" id="tema" name="tema"
                   value="<?= e($v('tema')) ?>" maxlength="200" minlength="5"
                   placeholder="Ej. Datos abiertos para decidir mejor" <?= $bloqueada ?>>
            <?php if ($err('tema')): ?><span class="error"><?= e($err('tema')) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="categoria">Categoría <span class="req">*</span></label>
            <select class="select<?= $err('categoria') ? ' is-invalid' : '' ?>" id="categoria" name="categoria" <?= $bloqueada ?>>
              <option value="">Selecciona…</option>
              <?php foreach ($categorias as $c): ?>
                <option value="<?= e($c) ?>" <?= $v('categoria') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($err('categoria')): ?><span class="error"><?= e($err('categoria')) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field">
          <label class="label" for="detalle">Detalle de lo que vas a exponer <span class="req">*</span></label>
          <textarea class="textarea<?= $err('detalle') ? ' is-invalid' : '' ?>" id="detalle" name="detalle"
                    rows="5" maxlength="600" minlength="30" data-contador="contador-detalle" <?= $bloqueada ?>
                    placeholder="Resumen que verán los asistentes en la agenda (máx. 600 caracteres)"><?= e($v('detalle')) ?></textarea>
          <div class="row row--between">
            <?php if ($err('detalle')): ?><span class="error"><?= e($err('detalle')) ?></span><?php else: ?><span></span><?php endif; ?>
            <span class="help mono" id="contador-detalle"><?= e((string) mb_strlen($v('detalle'))) ?> / 600</span>
          </div>
        </div>

        <?php if ($f->visible('dia_preferido') || $f->visible('duracion') || $f->visible('requerimientos')): ?>
          <div class="grid-3">
            <?php if ($f->visible('dia_preferido')): ?>
              <div class="field">
                <label class="label" for="dia_preferido">Día preferido</label>
                <select class="select" id="dia_preferido" name="dia_preferido" <?= $bloqueada ?>>
                  <?php foreach ($jornadas as $j): ?>
                    <option value="<?= e((string) $j['numero']) ?>" <?= (int) $v('dia_preferido', '1') === (int) $j['numero'] ? 'selected' : '' ?>>
                      Día <?= e((string) $j['numero']) ?> — <?= e(fecha((string) $j['fecha'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <?php if ($f->visible('duracion')): ?>
              <div class="field">
                <label class="label" for="duracion">Duración</label>
                <select class="select<?= $err('duracion') ? ' is-invalid' : '' ?>" id="duracion" name="duracion" <?= $bloqueada ?>>
                  <?php foreach ($duraciones as $min): ?>
                    <option value="<?= $min ?>" <?= (int) $v('duracion') === $min ? 'selected' : '' ?>>
                      <?= e($min === 60 ? '1 hora' : ($min % 60 === 0 ? ($min / 60) . ' horas' : $min . ' minutos')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($err('duracion')): ?><span class="error"><?= e($err('duracion')) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($f->visible('requerimientos')): ?>
              <div class="field">
                <label class="label" for="requerimientos">Requerimientos<?= $f->obligatorio('requerimientos') ? ' <span class="req">*</span>' : '' ?></label>
                <input class="input<?= $err('requerimientos') ? ' is-invalid' : '' ?>" id="requerimientos" name="requerimientos"
                       value="<?= e($v('requerimientos')) ?>" maxlength="255" placeholder="HDMI, internet…">
                <?php if ($err('requerimientos')): ?><span class="error"><?= e($err('requerimientos')) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ================= Documentos ================= -->
    <section class="card" id="documentos">
      <div class="card__head"><span>Documentos de respaldo</span></div>
      <div class="card__body stack stack--3">
        <p class="help" style="margin:0">
          Los dos son obligatorios para exponer: son con lo que el comité evalúa la propuesta. Para
          cambiar uno, elige el archivo nuevo y guarda: reemplaza al anterior. Lo demás no hace
          falta volver a escribirlo.
        </p>

        <?php if ($reelegir): ?>
          <div class="notice notice--warn">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span>
              Vuelve a elegir los archivos: el navegador los descarta cada vez que el formulario
              se repinta con un error, así que no llegaron al servidor.
            </span>
          </div>
        <?php endif; ?>

        <?php foreach (Documento::CLASES as $clase => $regla):
          $ya = $documentos[$clase] ?? null; ?>
          <div class="field">
            <label class="label" for="<?= e($clase) ?>">
              <?= e($regla['etiqueta']) ?> <span class="req">*</span>
              <span class="muted" style="text-transform:none;letter-spacing:normal">
                (<?= e(Documento::formatosLegibles($clase)) ?>, máximo <?= e(Documento::pesoLegible($clase)) ?>)
              </span>
            </label>
            <div class="row" style="gap:10px;margin-bottom:8px">
              <?php if ($ya !== null): ?>
                <span class="tag tag--ok">Ya la subiste</span>
                <a href="<?= e($ya['url']) ?>">Descargar<?= $ya['peso'] !== '' ? ' (' . e($ya['peso']) . ')' : '' ?></a>
              <?php else: ?>
                <span class="tag tag--mute">Falta</span>
              <?php endif; ?>
            </div>
            <input class="input<?= $err($clase) ? ' is-invalid' : '' ?>" type="file"
                   id="<?= e($clase) ?>" name="<?= e($clase) ?>" accept="<?= e(Documento::aceptados($clase)) ?>">
            <?php if ($err($clase)): ?><span class="error"><?= e($err($clase)) ?></span><?php endif; ?>
            <?php if ($ya !== null): ?>
              <span class="help">Si eliges otro archivo, reemplaza al anterior.</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <p class="help" style="margin:0">
          Los ve solo el equipo que revisa las propuestas. No se publican en la agenda ni se
          comparten con los demás asistentes.
        </p>
      </div>
    </section>

    <div class="row row--between" style="padding-top:4px">
      <span class="help mono">Los campos con <span class="req">*</span> son obligatorios.</span>
      <div class="row">
        <a class="btn" href="<?= e(u('/carnet')) ?>">Volver a mi carnet</a>
        <button class="btn btn--primary" type="submit"><?= $propuesta === null ? 'Enviar mi propuesta' : 'Guardar cambios' ?></button>
      </div>
    </div>
  <?php endif; ?>
</form>
