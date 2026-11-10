<?php
/**
 * Configuración → Certificados (3.21): si ya se pueden descargar, qué dicen,
 * cuántas horas vale cada jornada, quién firma y con qué diseño.
 *
 * @var bool  $hayTablas la base ya tiene las tablas de la 1.16.0
 * @var array $config    Certificado::configuracion()
 * @var array $jornadas  las del evento
 * @var array|null $resumen cuántos tienen derecho y cuántos descargaron
 * @var array $errores   por campo
 * @var string $nombreDeFabrica cómo se nombra el evento si no se escribe nada
 * @var array $evento
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Certificado;

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
$abierto = $config['estado'] === 'abierto';
$opciones = [
    'cerrado' => 'Nadie los ve todavía: «Mi certificado» no aparece en el menú y no se descargan. Puedes '
        . 'revisar el diseño con los ejemplos y con el certificado de cualquier persona desde su ficha.',
    'abierto' => 'Cada persona con derecho encuentra el suyo en «Mi certificado», en el menú, y lo descarga en '
        . 'PDF. Para cerrarlos otra vez, vuelve aquí: los que ya se descargaron se siguen verificando.',
];
$imagenes = [
    'fondo' => ['La hoja entera', 'Carta horizontal. Lo ideal: 3300 × 2550 px; al menos 2400 px de ancho para '
        . 'que se imprima nítido. Una imagen de otra proporción se recorta un poco por los bordes.'],
    'texto' => ['Fondo del nombre', 'El recuadro donde va el nombre de la persona: una imagen ancha, en PNG con '
        . 'fondo transparente. Sin imagen, se dibuja un recuadro del color principal.'],
    'firma' => ['Imagen de la firma', 'El visto o la firma escaneada: PNG con fondo transparente o JPG con fondo '
        . 'blanco. El papel, las motas del escaneo y el margen se quitan solos. Va encima del nombre de quien '
        . 'firma, con una pequeña parte del trazo sobre el nombre. Solo sale si escribes ese nombre.'],
];
?>
<div class="view view--wide stack stack--4">

  <?php require __DIR__ . '/../parciales/pestanas-configuracion.php'; ?>

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Configuración · <?= e((string) $evento['nombre']) ?></span>
      <h1>Certificados</h1>
      <p class="help" style="max-width:72ch">
        Cada persona que registró su ingreso en al menos una jornada recibe un certificado de
        asistencia; quien estuvo en todas, uno de <strong>asistencia completa</strong>, con sello dorado
        y el total de horas. Los expositores con propuesta aprobada reciben además uno de
        participación como conferencistas. Cada certificado lleva un código y un QR para verificarlo.
      </p>
    </div>
    <?php if ($hayTablas): ?>
      <a class="btn btn--sm" href="<?= e(u('/admin/configuracion/certificados/ejemplo', ['caso' => 'completa'])) ?>"
         target="_blank" rel="noopener" data-ejemplo>Ver un ejemplo ›</a>
    <?php endif; ?>
  </div>

  <?php if (!$hayTablas): ?>
    <div class="notice notice--warn" role="alert">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        Para los certificados, la base de datos tiene que ponerse al día: pulsa «Actualizar la base de
        datos» en el aviso de arriba. Solo se agregan dos tablas nuevas; los registros no cambian.
      </span>
    </div>
  <?php else: ?>

  <?php if ($errores): ?>
    <div class="notice notice--warn" role="alert">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span><strong>Se guardó todo menos lo marcado abajo.</strong> Corrígelo y vuelve a guardar.</span>
    </div>
  <?php endif; ?>

  <!-- Abrirlos o cerrarlos. Va fuera del formulario de abajo: tiene su propio botón. -->
  <section class="card" id="estado">
    <div class="card__head">
      <span>¿Se pueden descargar?</span>
      <span class="tag <?= $abierto ? 'tag--ok' : 'tag--mute' ?>" data-estado-certificados="<?= e($config['estado']) ?>"><?= e(Certificado::ESTADOS[$config['estado']]) ?></span>
    </div>
    <form method="post" action="<?= e(u('/admin/configuracion/certificados/estado')) ?>" class="card__body stack stack--4"
          data-confirmar="Esto cambia lo que ven todas las personas en su menú. ¿Continuar?">
      <?= testigo() ?>
      <?php if ($resumen !== null): ?>
        <div class="grid-3 certificados-resumen">
          <div class="kpi">
            <span class="kpi__label">Asistencia</span>
            <strong class="kpi__value" data-resumen="asistentes"><?= e(numero($resumen['asistentes'])) ?></strong>
            <span class="kpi__sub">con ingreso en al menos una jornada</span>
          </div>
          <div class="kpi">
            <span class="kpi__label">Asistencia completa</span>
            <strong class="kpi__value" data-resumen="completos"><?= e(numero($resumen['completos'])) ?></strong>
            <span class="kpi__sub"><?= $resumen['jornadas'] > 1 ? 'en las ' . e((string) $resumen['jornadas']) . ' jornadas' : 'el evento tiene una sola jornada' ?></span>
          </div>
          <div class="kpi">
            <span class="kpi__label">Expositores</span>
            <strong class="kpi__value" data-resumen="expositores"><?= e(numero($resumen['expositores'])) ?></strong>
            <span class="kpi__sub">con propuesta aprobada</span>
          </div>
        </div>
        <p class="help" style="margin:0" data-resumen="descargas">
          Emitidos: <?= e(numero($resumen['emitidos'])) ?> · descargados al menos una vez:
          <?= e(numero($resumen['descargados'])) ?>. Un certificado se emite la primera vez que su dueño
          abre «Mi certificado».
        </p>
      <?php endif; ?>

      <fieldset class="stack stack--3" style="border:0;padding:0;margin:0">
        <legend class="label">Estado de los certificados</legend>
        <?php foreach ($opciones as $valor => $explicacion): ?>
          <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
            <input type="radio" name="estado" value="<?= e($valor) ?>" <?= $config['estado'] === $valor ? 'checked' : '' ?>
                   style="margin-top:3px;flex:none;accent-color:var(--c-accent)">
            <span class="stack" style="gap:2px">
              <span><?= e(Certificado::ESTADOS[$valor]) ?></span>
              <span class="help" style="margin:0"><?= e($explicacion) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <div class="row row--end">
        <button class="btn btn--primary" type="submit">Guardar el estado</button>
      </div>
    </form>
  </section>

  <form method="post" action="<?= e(u('/admin/configuracion/certificados')) ?>" enctype="multipart/form-data"
        class="stack stack--4" novalidate>
    <?= testigo() ?>

    <!-- ================= Textos ================= -->
    <section class="card" id="textos">
      <div class="card__head"><span>Lo que dice</span></div>
      <div class="card__body stack stack--4">
        <div class="field">
          <label class="label" for="entidad">Quién certifica</label>
          <textarea class="textarea" id="entidad" name="entidad" rows="2" maxlength="300"><?= e($config['entidad']) ?></textarea>
          <span class="help">Se completa con «certifica que» y, debajo, el nombre de la persona.</span>
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="nombre_evento">El evento, como se nombra en el texto</label>
            <input class="input" id="nombre_evento" name="nombre_evento" maxlength="200"
                   value="<?= e($config['nombre_evento']) ?>" placeholder="<?= e($nombreDeFabrica) ?>">
            <span class="help">
              Con su artículo: «la Cumbre Tecnológica IA Nariño 2026». Vacío: «<?= e($nombreDeFabrica) ?>».
            </span>
          </div>
          <div class="field">
            <label class="label" for="ciudad">Ciudad</label>
            <input class="input" id="ciudad" name="ciudad" maxlength="120" value="<?= e($config['ciudad']) ?>"
                   placeholder="San Juan de Pasto">
            <span class="help">«…que se realizó en San Juan de Pasto…» y «Se expide en…». Vacío, no se menciona.</span>
          </div>
        </div>
        <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
          <input type="checkbox" name="expositores" value="1" <?= $config['expositores'] ? 'checked' : '' ?>
                 style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--c-accent)">
          <span class="stack" style="gap:2px">
            <span>Certificado de participación para los expositores</span>
            <span class="help" style="margin:0">
              Uno por cada propuesta aprobada, con el título de la exposición. Es aparte del de
              asistencia, que reciben si registraron su ingreso.
            </span>
          </span>
        </label>
      </div>
    </section>

    <!-- ================= Horas ================= -->
    <section class="card" id="horas">
      <div class="card__head"><span>Intensidad horaria</span></div>
      <div class="card__body stack stack--3">
        <p class="help" style="margin:0">
          Las horas de cada jornada. El certificado suma las de las jornadas a las que asistió cada
          persona: quien estuvo en todas recibe el total. Vacío o en cero, el certificado no habla de horas.
        </p>
        <?php if ($err('horas')): ?><span class="error"><?= e($err('horas')) ?></span><?php endif; ?>
        <div class="grid-3">
          <?php foreach ($jornadas as $j): $valor = $config['horas'][(int) $j['id']] ?? null; ?>
            <div class="field">
              <label class="label" for="horas-<?= e((string) $j['id']) ?>">Día <?= e((string) $j['numero']) ?> · <?= e(fecha((string) $j['fecha'])) ?></label>
              <input class="input" type="number" min="0" max="24" step="0.5" inputmode="decimal"
                     id="horas-<?= e((string) $j['id']) ?>" name="horas[<?= e((string) $j['id']) ?>]"
                     value="<?= $valor !== null ? e(rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.')) : '' ?>" placeholder="8">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ================= Firma ================= -->
    <section class="card" id="firma">
      <div class="card__head"><span>Firma</span></div>
      <div class="card__body stack stack--4">
        <div class="grid-2">
          <div class="field">
            <label class="label" for="firma_nombre">Nombre de quien firma</label>
            <input class="input" id="firma_nombre" name="firma_nombre" maxlength="160" value="<?= e($config['firma_nombre']) ?>">
          </div>
          <div class="field">
            <label class="label" for="firma_cargo">Cargo</label>
            <input class="input" id="firma_cargo" name="firma_cargo" maxlength="200" value="<?= e($config['firma_cargo']) ?>"
                   placeholder="Secretario TIC, Innovación y Gobierno Abierto">
          </div>
        </div>
        <span class="help" style="margin:0">Sin nombre, el certificado sale sin firma.</span>
        <?php $ranura = 'firma'; require __DIR__ . '/../parciales/imagen-certificado.php'; ?>
      </div>
    </section>

    <!-- ================= Diseño ================= -->
    <section class="card" id="diseno">
      <div class="card__head"><span>Diseño</span></div>
      <div class="card__body stack stack--4">
        <div class="field" style="max-width:320px">
          <label class="label" for="color">Color principal</label>
          <input class="input" type="color" id="color" name="color" value="<?= e($config['color']) ?>"
                 style="height:42px;padding:4px">
          <span class="help">El del título, el nombre y el código.</span>
        </div>
        <?php foreach (['fondo', 'texto'] as $ranura): require __DIR__ . '/../parciales/imagen-certificado.php'; endforeach; ?>
      </div>
    </section>

    <div class="row row--end" style="gap:10px">
      <button class="btn btn--primary" type="submit">Guardar los certificados</button>
    </div>
  </form>

  <section class="card" id="ejemplos">
    <div class="card__head"><span>Cómo quedan</span></div>
    <div class="card__body stack stack--3">
      <p class="help" style="margin:0">
        Con datos inventados y una marca de agua. Guarda antes los cambios para verlos.
      </p>
      <div class="row" style="gap:10px;flex-wrap:wrap">
        <?php foreach (['completa' => 'Asistencia completa', 'parcial' => 'Asistencia a una jornada', 'expositor' => 'Expositor'] as $caso => $etiqueta): ?>
          <a class="btn btn--sm" href="<?= e(u('/admin/configuracion/certificados/ejemplo', ['caso' => $caso])) ?>"
             target="_blank" rel="noopener" data-ejemplo="<?= e($caso) ?>"><?= e($etiqueta) ?> ›</a>
        <?php endforeach; ?>
      </div>
      <p class="help" style="margin:0">
        Quien recibe un certificado lo comprueba en <a href="<?= e(u('/v')) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', \App\Nucleo\Url::absoluta('/v'))) ?></a>
        con su código, o escaneando el QR. Para quien estuvo y no registró su ingreso, regístralo a
        mano desde su ficha, en Registros: sin ingreso no hay certificado.
      </p>
    </div>
  </section>
  <?php endif; ?>
</div>
