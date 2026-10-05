<?php
/**
 * Identidad del evento: colores, tipografía y logo.
 * @var array $temaEvento @var array $presets @var array $tipografias @var array $contraste
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$colores = $temaEvento['colores'];
$campos = [
    'brand'   => ['Color principal', 'Barras y botones sólidos'],
    'accent'  => ['Color de énfasis', 'Bordes, foco y estados activos'],
    'bg'      => ['Fondo de la aplicación', 'Lienzo plano'],
    'surface' => ['Fondo de tarjetas', 'Paneles y formularios'],
    'sunken'  => ['Fondo de campos', 'Formularios y huecos'],
    'line'    => ['Color de líneas', 'Bordes y separadores'],
    'title'   => ['Color de títulos', 'Encabezados'],
    'text'    => ['Color del texto', 'Párrafos'],
    'muted'   => ['Color de metadatos', 'Rótulos pequeños'],
    'onBrand' => ['Texto sobre el principal', 'Contraste de la barra'],
];
guiones('identidad.js');
?>
<div class="view view--wide stack stack--4">

<?php $pestana = 'identidad'; require __DIR__ . '/../parciales/pestanas-configuracion.php'; ?>

<form class="split" method="post" action="<?= e(u('/admin/identidad')) ?>"
      enctype="multipart/form-data" style="align-items:start"
      data-presets='<?= e(json_encode(array_map(static fn($p) => $p['colores'], $presets), JSON_UNESCAPED_UNICODE)) ?>'>
  <?= testigo() ?>

  <div class="stack stack--4">

    <div class="stack stack--2">
      <span class="kicker">Configuración</span>
      <h1>Identidad del evento</h1>
      <p class="lead" style="max-width:58ch">
        Cada evento se personaliza sin tocar código: nombre, logo, paleta y tipografía se
        aplican a toda la plataforma, al carnet y a los documentos impresos.
      </p>
    </div>

    <!-- ---------- Nombre y logo ---------- -->
    <section class="card">
      <div class="card__head"><span>Nombre e imagen</span></div>
      <div class="card__body" style="display:grid;grid-template-columns:104px 1fr;gap:16px;align-items:start">
        <div class="stack stack--2">
          <div style="aspect-ratio:1;border:1px dashed var(--a-28);display:grid;place-items:center;background:var(--c-sunken);overflow:hidden;padding:10px">
            <?php if ($temaEvento['logo'] !== ''): ?>
              <img src="<?= e(u('/medios/logo/' . (int) $evento['id'])) ?>" alt="Logo actual"
                   style="max-width:100%;max-height:100%;object-fit:contain">
            <?php else: ?>
              <span class="mono" style="font-size:9.5px;color:var(--c-muted);text-align:center;letter-spacing:.08em;text-transform:uppercase">
                Sin logo
              </span>
            <?php endif; ?>
          </div>
          <?php if ($temaEvento['logo'] !== ''): ?>
            <label class="row" style="gap:6px;cursor:pointer">
              <input type="checkbox" name="quitar_logo" value="1" style="width:16px;height:16px;accent-color:var(--c-accent)">
              <span class="help">Quitar</span>
            </label>
          <?php endif; ?>
        </div>

        <div class="stack stack--4">
          <div class="field">
            <label class="label" for="nombre">Nombre del evento</label>
            <input class="input" id="nombre" name="nombre" value="<?= e($evento['nombre']) ?>"
                   maxlength="160" data-vista="vista-nombre">
          </div>
          <div class="field">
            <label class="label" for="dependencia">Bajada / dependencia</label>
            <input class="input" id="dependencia" name="dependencia" value="<?= e($evento['dependencia']) ?>" maxlength="160">
          </div>
          <div class="field">
            <label class="label" for="sede">Sede</label>
            <input class="input" id="sede" name="sede" value="<?= e($evento['sede']) ?>" maxlength="160">
          </div>
          <div class="field">
            <label class="label" for="logo">Logo</label>
            <input class="input" id="logo" name="logo" type="file"
                   accept="image/png,image/jpeg,image/webp,image/svg+xml">
            <span class="help">
              SVG, PNG, JPG o WEBP hasta 512 KB. Se recomienda SVG para que el carnet
              impreso no salga pixelado.
            </span>
          </div>
        </div>
      </div>
    </section>

    <!-- ---------- Paleta ---------- -->
    <section class="card">
      <div class="card__head"><span>Paleta</span></div>
      <div class="card__body stack stack--4">
        <div class="field">
          <span class="label">Combinación base</span>
          <div class="row" role="group" aria-label="Combinación de colores">
            <?php foreach ($presets as $clave => $p): ?>
              <label class="chip<?= $temaEvento['preset'] === $clave ? ' is-active' : '' ?>"
                     title="<?= e($p['descripcion']) ?>" style="display:flex;align-items:center;gap:8px">
                <input type="radio" name="preset" value="<?= e($clave) ?>" class="sr-only"
                       data-preset-radio <?= $temaEvento['preset'] === $clave ? 'checked' : '' ?>>
                <span style="display:flex;gap:2px">
                  <?php foreach (['brand', 'accent', 'bg', 'title'] as $c): ?>
                    <span style="width:11px;height:11px;background:<?= e($p['colores'][$c]) ?>;border:1px solid rgba(255,255,255,.15)"></span>
                  <?php endforeach; ?>
                </span>
                <?= e($p['nombre']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <hr class="divider">

        <div>
          <?php foreach ($campos as $clave => [$etiqueta, $pista]): ?>
            <div class="plan-row" style="grid-template-columns:1fr 44px 96px">
              <div class="stack" style="gap:3px">
                <strong style="font-size:13px;font-weight:500;color:var(--c-title)"><?= e($etiqueta) ?></strong>
                <span class="mono muted" style="font-size:10.5px"><?= e($pista) ?></span>
              </div>
              <input type="color" value="<?= e($colores[$clave]) ?>" data-color="<?= e($clave) ?>"
                     aria-label="<?= e($etiqueta) ?>"
                     style="width:44px;height:32px;padding:0;border:1px solid var(--a-28);background:transparent;cursor:pointer">
              <input class="input input--mono campo-hex" name="color_<?= e($clave) ?>" data-hex="<?= e($clave) ?>"
                     value="<?= e($colores[$clave]) ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}"
                     aria-label="Código hexadecimal de <?= e($etiqueta) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ---------- Tipografía ---------- -->
    <section class="card">
      <div class="card__head"><span>Tipografía</span></div>
      <div class="card__body stack stack--3">
        <p class="help">
          Las familias están alojadas en el propio servidor: no se consulta ningún servicio
          externo y la interfaz se ve igual en redes cerradas.
        </p>
        <div class="grid-2" role="group" aria-label="Familia tipográfica">
          <?php foreach ($tipografias as $clave => $t): ?>
            <label class="chip<?= $temaEvento['tipografia'] === $clave ? ' is-active' : '' ?>"
                   style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:12px 14px;text-align:left">
              <input type="radio" name="tipografia" value="<?= e($clave) ?>" class="sr-only"
                     data-tipografia-radio <?= $temaEvento['tipografia'] === $clave ? 'checked' : '' ?>>
              <span style="font-family:var(--f-display);font-size:15px;font-weight:600;letter-spacing:.06em"><?= e($t['nombre']) ?></span>
              <span class="mono" style="font-size:10.5px;opacity:.75"><?= e($t['muestra']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ---------- Contraste ---------- -->
    <section class="card">
      <div class="card__head"><span>Revisión de contraste</span></div>
      <div class="card__body stack stack--3">
        <p class="help">
          Se comprueba cada combinación contra el mínimo de la norma WCAG 2.1 AA. Un evento
          puede verse muy bien en la pantalla del diseñador y ser ilegible bajo el sol en la
          puerta del recinto.
        </p>
        <div class="check-list" data-contraste>
          <?php foreach ($contraste as $c): ?>
            <div class="check <?= $c['cumple'] ? 'check--ok' : 'check--warn' ?>">
              <span class="check__icon" aria-hidden="true"><?= $c['cumple'] ? '✓' : '▲' ?></span>
              <div class="stack" style="gap:2px">
                <span class="check__name"><?= e($c['etiqueta']) ?></span>
                <span class="check__detail">
                  mínimo <?= e((string) $c['minimo']) ?>:1<?= $c['cumple'] ? '' : ' — poco legible' ?>
                </span>
              </div>
              <span class="check__value"><?= e(number_format($c['razon'], 2)) ?>:1</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

  </div>

  <!-- ---------- Previsualización ---------- -->
  <div class="stack stack--3 is-sticky">
    <span class="label">Previsualización</span>

    <div class="card">
      <div class="card__head" style="background:var(--c-brand);color:var(--c-on-brand)">
        <span class="row" style="gap:10px">
          <?= $marca('sm') ?>
          <span class="brandtext__name" id="vista-nombre"><?= e($evento['nombre']) ?></span>
        </span>
      </div>
      <div class="card__body stack stack--3">
        <h3>Preregístrate</h3>
        <p class="help">Diligencia tus datos una vez y entra con un solo escaneo.</p>
        <hr class="divider">
        <div class="input mono" style="font-size:12.5px">nombre@entidad.gov.co</div>
        <button class="btn btn--primary btn--block" type="button" disabled>Continuar</button>
        <div class="row" style="gap:6px">
          <span class="tag">Participante</span>
          <span class="tag tag--warn">Expositor</span>
          <span class="tag tag--ok">Organizador</span>
        </div>
      </div>
    </div>

    <p class="help mono" style="font-size:11.5px">
      Los cambios se aplican a la plataforma pública, la credencial y los reportes.
    </p>

    <button class="btn btn--primary btn--block btn--lg" type="submit">Guardar identidad</button>
  </div>

</form>

</div>
