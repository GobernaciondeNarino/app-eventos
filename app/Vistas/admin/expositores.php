<?php
/**
 * Propuestas de exposición.
 * @var array $propuestas @var array $conteos @var string $estado @var array $jornadas
 * @var bool $puedeVerDocumento
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Persona;
use App\Nucleo\Documento;

/**
 * Los dos adjuntos de una propuesta, listos para pintar.
 *
 * Se devuelven siempre los dos, con o sin archivo: lo que el comité necesita
 * ver de un vistazo no es solo lo que llegó, es lo que falta por pedir.
 */
$adjuntos = static function (array $p): array {
    $lista = [];
    foreach (Documento::CLASES as $clase => $regla) {
        $archivo = (string) ($p[$clase] ?? '');
        $lista[] = [
            'etiqueta' => $regla['etiqueta'],
            'hay'      => $archivo !== '',
            'peso'     => $archivo !== '' ? Documento::peso($archivo) : '',
            'formato'  => $archivo !== '' ? strtoupper(pathinfo($archivo, PATHINFO_EXTENSION)) : '',
            'url'      => u('/medios/documento/' . (int) $p['id'] . '/' . $regla['ranura']),
        ];
    }
    return $lista;
};

$estados = [
    'pendiente' => ['Pendiente', 'tag--warn'],
    'observada' => ['Con observaciones', ''],
    'aprobada'  => ['Aprobada', 'tag--ok'],
    'rechazada' => ['Rechazada', 'tag--danger'],
];
$columnas = 'grid-template-columns:1.7fr 1.1fr 1fr .8fr .9fr';
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Propuestas de exposición</h1>
      <p class="help">Al aprobar una propuesta se publica en la agenda y el expositor recibe su carnet con el rótulo correspondiente.</p>
    </div>
    <div class="row" role="group" aria-label="Filtrar por estado">
      <a class="chip<?= $estado === '' ? ' is-active' : '' ?>" href="<?= e(u('/admin/expositores')) ?>">Todas</a>
      <?php foreach ($estados as $clave => [$etiqueta, $_]): ?>
        <a class="chip<?= $estado === $clave ? ' is-active' : '' ?>"
           href="<?= e(u('/admin/expositores', ['estado' => $clave])) ?>"><?= e($etiqueta) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid-4">
    <?php foreach ($estados as $clave => [$etiqueta, $_]): ?>
      <div class="kpi">
        <span class="kpi__label"><?= e($etiqueta) ?></span>
        <strong class="kpi__value"><?= e((string) ($conteos[$clave] ?? 0)) ?></strong>
        <span class="kpi__sub">propuestas</span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="table">
    <div class="table__scroll">
      <div class="table__grid" style="min-width:820px">
        <div class="table__head" style="<?= $columnas ?>">
          <span>Propuesta</span><span>Expositor</span><span>Categoría</span>
          <span>Jornada</span><span>Estado</span>
        </div>

        <?php if (!$propuestas): ?>
          <div class="empty" style="margin:16px">Sin propuestas en este estado</div>
        <?php else: foreach ($propuestas as $p):
          [$etiqueta, $clase] = $estados[$p['estado']] ?? ['—', '']; ?>
          <button class="table__row" style="<?= $columnas ?>;cursor:pointer;text-align:left;border:0;border-bottom:1px solid var(--hair-soft);background:none;width:100%;color:inherit;font:inherit"
                  type="button" data-abrir-modal="modal-propuesta-<?= (int) $p['id'] ?>">
            <div class="stack" style="gap:4px;min-width:0">
              <strong style="font-weight:500;color:var(--c-title)"><?= e($p['titulo']) ?></strong>
              <span class="mono muted" style="font-size:11px">
                <?= e((string) $p['duracion_min']) ?> min · <?= e($p['requerimientos'] ?: 'sin requerimientos') ?>
              </span>
              <!-- Qué llegó y qué falta. Va en el listado y no solo dentro del
                   diálogo porque revisar propuestas empieza por apartar las que
                   todavía no se pueden evaluar. -->
              <span class="row" style="gap:5px">
                <?php foreach ($adjuntos($p) as $d): ?>
                  <span class="tag <?= $d['hay'] ? 'tag--ok' : 'tag--mute' ?>" style="font-size:10px">
                    <?= $d['hay'] ? '' : 'sin ' ?><?= e($d['hay'] ? $d['etiqueta'] : mb_strtolower($d['etiqueta'])) ?>
                  </span>
                <?php endforeach; ?>
              </span>
            </div>
            <div class="stack" style="gap:2px;min-width:0">
              <span style="color:var(--c-title)"><?= e($p['expositor']) ?></span>
              <span class="mono muted" style="font-size:11px;word-break:break-all"><?= e($p['correo']) ?></span>
              <span class="mono muted" style="font-size:11px"><?= e($p['entidad'] ?: 'Independiente') ?></span>
            </div>
            <span style="color:var(--c-text);font-size:13px"><?= e($p['categoria']) ?></span>
            <span class="mono" style="font-size:12.5px;color:var(--c-text)">
              Día <?= e((string) $p['dia_preferido']) ?><?= $p['hora_inicio'] ? ' · ' . e(substr((string) $p['hora_inicio'], 0, 5)) : '' ?>
            </span>
            <span><span class="tag <?= e($clase) ?>"><?= e($etiqueta) ?></span></span>
          </button>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="table__foot">
      <span>Mostrando <?= e((string) count($propuestas)) ?> propuestas</span>
      <span>La agenda pública está en <?= e(u('/agenda')) ?></span>
    </div>
  </div>

</div>

<?php foreach ($propuestas as $p):
  // El estado se vuelve a resolver aquí. Heredar el del bucle de la tabla
  // habría pintado en todos los diálogos el de la última fila.
  [$suEtiqueta, $suClase] = $estados[$p['estado']] ?? ['—', ''];
?>
  <div class="modal hidden" id="modal-propuesta-<?= (int) $p['id'] ?>" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Propuesta de exposición">
      <div class="modal__head">
        <span><?= e($p['categoria']) ?></span>
        <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
      </div>
      <div class="modal__body">
        <h2 style="font-size:24px"><?= e($p['titulo']) ?></h2>

        <div class="row">
          <span class="tag">Día preferido: <?= e((string) $p['dia_preferido']) ?></span>
          <span class="tag tag--mute"><?= e((string) $p['duracion_min']) ?> minutos</span>
          <?php if ($p['requerimientos'] !== ''): ?>
            <span class="tag tag--mute"><?= e($p['requerimientos']) ?></span>
          <?php endif; ?>
        </div>

        <p style="font-size:14.5px;line-height:1.7;color:var(--c-text)"><?= nl2br(e($p['detalle'])) ?></p>

        <!-- ---------- Quién la presenta ----------
             Decidir sobre una propuesta es decidir sobre quién la presenta, así
             que los datos van aquí y no a una pantalla aparte: tener que abrir
             la ficha en otra pestaña para ver de qué entidad viene convertía la
             revisión en un ir y venir. La ficha completa —ingresos, carnet,
             caracterización— sigue estando a un clic. -->
        <div class="card">
          <div class="card__head">
            <span>Quién la presenta</span>
            <a href="<?= e(u('/admin/registros/' . (int) $p['persona_id'])) ?>">Ficha completa ›</a>
          </div>
          <div class="card__body stack stack--3">

            <div class="ficha">
              <div class="ficha__foto">
                <?php if ((string) $p['foto'] !== ''): ?>
                  <img src="<?= e(u('/medios/foto/' . (int) $p['persona_id'])) ?>"
                       alt="Fotografía de <?= e($p['expositor']) ?>">
                <?php else: ?>
                  <span class="ficha__iniciales" aria-hidden="true"><?= e(iniciales((string) $p['expositor'])) ?></span>
                <?php endif; ?>
              </div>
              <div class="stack stack--2" style="min-width:0">
                <h3 style="font-size:19px;margin:0;line-height:1.2"><?= e($p['expositor'] ?: 'Registro sin completar') ?></h3>
                <div class="row">
                  <span class="tag <?= e(claseRol((string) $p['rol'])) ?>"><?= e(etiquetaRol((string) $p['rol'])) ?></span>
                  <?php if ((string) $p['rol'] !== 'expositor'): ?>
                    <!-- Pasa cuando alguien cambia su perfil después de mandar
                         la propuesta. No es un error, pero al aprobar hay que
                         saberlo: el carnet se imprime con lo que diga el perfil. -->
                    <span class="tag tag--warn">Su carnet no dice «expositor»</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="card__body--tight" style="padding:0">
              <?php
              $doc = $puedeVerDocumento ? Persona::documento($p) : '';
              foreach ([
                ['Correo', $p['correo']],
                ['Teléfono', $p['telefono'] !== '' ? $p['telefono'] : 'Sin registrar'],
                ['Identificación', $doc !== ''
                    ? $p['tipo_documento'] . ' ' . documento($doc)
                    : ($puedeVerDocumento ? 'Todavía no la ha dado' : 'Reservada')],
                ['Entidad', $p['entidad'] ?: 'Independiente'],
                ['Territorio', trim((string) $p['municipio'] . ' · ' . (string) $p['departamento'], ' ·') ?: 'Sin registrar'],
                ['Se registró', fecha((string) $p['registrado_en'])],
                ['Envió la propuesta', fecha((string) $p['creado_en'])],
              ] as [$k, $valor]): ?>
                <div class="kv">
                  <span class="kv__k"><?= e($k) ?></span>
                  <strong class="kv__v" style="word-break:break-word"><?= e((string) $valor) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>

          </div>
        </div>

        <!-- ---------- Los adjuntos ----------
             Se bajan al disco en vez de abrirse dentro de la página: un PDF
             incrustado es un documento que puede traer sus propios guiones, y
             estos los subió alguien de fuera. -->
        <div class="card">
          <div class="card__head"><span>Documentos de respaldo</span></div>
          <div class="card__body stack stack--3">
            <div class="row" style="gap:10px">
              <?php foreach ($adjuntos($p) as $d): ?>
                <?php if ($d['hay']): ?>
                  <a class="btn btn--sm" href="<?= e($d['url']) ?>" download>
                    <?= e($d['etiqueta']) ?>
                    <span class="muted" style="font-weight:400">
                      <?= e(trim($d['formato'] . ($d['peso'] !== '' ? ' · ' . $d['peso'] : ''))) ?>
                    </span>
                  </a>
                <?php else: ?>
                  <span class="tag tag--mute">Sin <?= e(mb_strtolower($d['etiqueta'])) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <?php if (!$p['hoja_vida'] || !$p['exposicion']): ?>
              <!-- Desde la 3.3 son obligatorios, así que una propuesta a la que
                   le falta alguno es de antes del cambio. Decirlo evita que
                   alguien lo tome por un fallo de la plataforma. -->
              <span class="help">
                Los dos son obligatorios desde que se pide la propuesta, así que a esta le
                faltan porque se envió antes de ese cambio. Devuélvela con observaciones
                pidiéndolos: el expositor entra a su registro y los sube.
              </span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($p['observacion']): ?>
          <div class="notice notice--warn">
            <span class="notice__icon">▲</span>
            <span><strong>Observación anterior:</strong> <?= e($p['observacion']) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(u('/admin/expositores/decidir')) ?>" class="card">
          <?= testigo() ?>
          <input type="hidden" name="propuesta" value="<?= (int) $p['id'] ?>">

          <div class="card__head">
            <span>Validar la participación</span>
            <span class="tag <?= e($suClase) ?>">Hoy: <?= e($suEtiqueta) ?></span>
          </div>

          <div class="card__body stack stack--3">

          <p class="help" style="margin:0">
            <strong>Aprobar</strong> publica la charla en la agenda con el día, la hora y el salón
            de abajo, y el carnet del expositor sale con ese rótulo.
            <strong>Devolver</strong> se la regresa para que corrija lo que le digas, y puede
            volver a enviarla. <strong>Rechazar</strong> la deja fuera del evento. Las tres se
            pueden cambiar después volviendo a entrar aquí.
          </p>

          <div class="grid-3">
            <div class="field">
              <label class="label" for="dia-<?= (int) $p['id'] ?>">Jornada asignada</label>
              <select class="select" id="dia-<?= (int) $p['id'] ?>" name="dia">
                <?php foreach ($jornadas as $j): ?>
                  <option value="<?= e((string) $j['numero']) ?>" <?= (int) $j['numero'] === (int) $p['dia_preferido'] ? 'selected' : '' ?>>
                    Día <?= e((string) $j['numero']) ?> — <?= e(fecha((string) $j['fecha'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="label" for="hora-<?= (int) $p['id'] ?>">Hora</label>
              <input class="input input--mono" id="hora-<?= (int) $p['id'] ?>" name="hora" type="time"
                     value="<?= e($p['hora_inicio'] ? substr((string) $p['hora_inicio'], 0, 5) : '09:00') ?>">
            </div>
            <div class="field">
              <label class="label" for="salon-<?= (int) $p['id'] ?>">Salón</label>
              <input class="input" id="salon-<?= (int) $p['id'] ?>" name="salon"
                     value="<?= e((string) ($p['salon'] ?? '')) ?>" placeholder="Auditorio principal" maxlength="80">
            </div>
          </div>

          <div class="field">
            <label class="label" for="obs-<?= (int) $p['id'] ?>">Observación para el expositor</label>
            <textarea class="textarea" id="obs-<?= (int) $p['id'] ?>" name="observacion" rows="3"
                      maxlength="1000" placeholder="Obligatoria si devuelves la propuesta."></textarea>
          </div>

          <div class="row row--end">
            <button class="btn btn--danger" type="submit" name="decision" value="rechazada"
                    data-confirmar="Se rechaza la propuesta de <?= e($p['expositor']) ?> y queda fuera del evento. ¿Continuar?">
              Rechazar
            </button>
            <button class="btn" type="submit" name="decision" value="observada">Devolver con observaciones</button>
            <button class="btn btn--primary" type="submit" name="decision" value="aprobada">Aprobar y agendar</button>
          </div>

          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
