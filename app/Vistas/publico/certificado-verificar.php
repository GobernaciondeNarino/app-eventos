<?php
/**
 * Verificar un certificado (3.21). Pública: es lo que abre el QR impreso y lo
 * que consulta quien recibe un certificado y quiere saber si es auténtico.
 *
 * @var string $escrito  el código que llegó, tal como llegó
 * @var array|null $resultado null si todavía no se ha consultado nada
 */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--4">

  <div class="stack stack--2">
    <span class="kicker">Certificados</span>
    <h1>Verificar un certificado</h1>
    <p class="help">
      Escribe el código que aparece debajo del QR del certificado —doce letras y números— o
      escanea el QR con la cámara del teléfono.
    </p>
  </div>

  <?php if ($resultado !== null && $resultado['valido']): ?>
    <section class="card certificado certificado--valido" data-verificacion="valido">
      <div class="card__head">
        <span>Certificado auténtico</span>
        <span class="tag tag--ok">Válido</span>
      </div>
      <div class="card__body stack stack--3">
        <p style="margin:0">
          La plataforma del evento emitió este certificado, y lo que dice coincide con sus registros
          de hoy.
        </p>
        <div class="stack" style="gap:0">
          <div class="kv"><span class="kv__k">A nombre de</span><strong class="kv__v" data-nombre><?= e($resultado['nombre']) ?></strong></div>
          <div class="kv"><span class="kv__k">Certificado</span><strong class="kv__v"><?= e($resultado['titulo']) ?></strong></div>
          <div class="kv"><span class="kv__k">Detalle</span><strong class="kv__v"><?= e($resultado['resumen']) ?></strong></div>
          <div class="kv"><span class="kv__k">Evento</span><strong class="kv__v"><?= e($resultado['evento']) ?></strong></div>
          <?php if ($resultado['horas'] !== ''): ?>
            <div class="kv"><span class="kv__k">Intensidad</span><strong class="kv__v"><?= e($resultado['horas']) ?></strong></div>
          <?php endif; ?>
          <div class="kv"><span class="kv__k">Expedido</span><strong class="kv__v"><?= e($resultado['emitido']) ?></strong></div>
          <div class="kv"><span class="kv__k">Código</span><strong class="kv__v mono"><?= e($resultado['codigo']) ?></strong></div>
        </div>
        <blockquote class="certificado__texto">
          <strong><?= e($resultado['nombre']) ?></strong> <?= e($resultado['cuerpo']) ?>
        </blockquote>
        <p class="help" style="margin:0">
          Compara el nombre y el código con los del certificado que tienes en la mano: si no
          coinciden, ese certificado no es este.
        </p>
      </div>
    </section>

  <?php elseif ($resultado !== null && $resultado['existe']): ?>
    <div class="notice notice--warn" role="alert" data-verificacion="sin-vigencia">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        El certificado <strong class="mono"><?= e($resultado['codigo']) ?></strong> se emitió, pero ya no es
        válido: los registros del evento cambiaron después. Si es tuyo, comunícate con la organización.
      </span>
    </div>

  <?php elseif ($resultado !== null): ?>
    <div class="notice notice--warn" role="alert" data-verificacion="no-existe">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        No hay ningún certificado con ese código. Revisa que esté completo —son doce letras y
        números, sin la O ni el 0, la I ni el 1— y vuelve a intentarlo.
      </span>
    </div>
  <?php endif; ?>

  <form class="card" method="get" action="<?= e(u('/v')) ?>">
    <div class="card__body stack stack--3">
      <div class="field">
        <label class="label" for="codigo">Código del certificado</label>
        <input class="input input--mono" id="codigo" name="codigo" maxlength="24" autocomplete="off"
               autocapitalize="characters" spellcheck="false" placeholder="K7M2-9QXR-HT4P"
               value="<?= e($resultado !== null && !$resultado['valido'] ? $escrito : '') ?>" required>
      </div>
      <div class="row row--end">
        <button class="btn btn--primary" type="submit">Verificar</button>
      </div>
    </div>
  </form>
</div>
