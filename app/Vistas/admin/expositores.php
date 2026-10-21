<?php
/**
 * Expositores: las propuestas de exposición y, aparte, quienes se registraron
 * como expositores sin enviar la suya todavía.
 * @var array $propuestas @var array $conteos @var string $estado @var array $jornadas
 * @var bool $puedeVerDocumento @var int $eventoId @var bool $correoListo
 * @var array $sinPropuesta @var array<int, true> $porEnlace  quiénes llegaron por el enlace privado
 * @var array<int, string> $actualizadas  cuándo cambió cada propuesta su expositor, por id
 * @var App\Nucleo\Orden $orden @var App\Nucleo\Paginacion $paginacion  de la lista que se ve
 * @var array $consulta  el filtro de estado, para los enlaces
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Formulario;
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
$columnasSin = 'grid-template-columns:1.6fr 1.1fr .9fr .8fr auto';
$soloSinPropuesta = $estado === 'sin-propuesta';
$conOrden = $consulta + $orden->consulta();
$titulo = static fn(string $etiqueta, string $clave): string =>
    tituloOrdenable($etiqueta, $clave, $orden, '/admin/expositores', $consulta + ['por' => $paginacion->porPagina]);
// Para volver a la misma página después de decidir una propuesta.
$volver = http_build_query($conOrden + ['pagina' => $paginacion->pagina, 'por' => $paginacion->porPagina]);
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Expositores</h1>
      <p class="help">
        Las propuestas de exposición y, aparte, quienes se registraron como expositores sin enviar
        la suya todavía. Al aprobar una propuesta se publica en la agenda y el expositor recibe su
        carnet con el rótulo correspondiente. El enlace privado para invitar expositores está en
        <a href="<?= e(u('/admin/configuracion/expositores')) ?>">Configuración → Registro de expositores</a>.
      </p>
    </div>
    <div class="row" role="group" aria-label="Filtrar">
      <a class="chip<?= $estado === '' ? ' is-active' : '' ?>" href="<?= e(u('/admin/expositores')) ?>">Todas</a>
      <?php foreach ($estados as $clave => [$etiqueta, $_]): ?>
        <a class="chip<?= $estado === $clave ? ' is-active' : '' ?>"
           href="<?= e(u('/admin/expositores', ['estado' => $clave])) ?>"><?= e($etiqueta) ?></a>
      <?php endforeach; ?>
      <a class="chip<?= $soloSinPropuesta ? ' is-active' : '' ?>"
         href="<?= e(u('/admin/expositores', ['estado' => 'sin-propuesta'])) ?>">Sin propuesta</a>
    </div>
  </div>

  <div class="grid-4" style="grid-template-columns:repeat(5, 1fr)">
    <?php foreach ($estados as $clave => [$etiqueta, $_]): ?>
      <div class="kpi">
        <span class="kpi__label"><?= e($etiqueta) ?></span>
        <strong class="kpi__value"><?= e((string) ($conteos[$clave] ?? 0)) ?></strong>
        <span class="kpi__sub">propuestas</span>
      </div>
    <?php endforeach; ?>
    <a class="kpi" href="<?= e(u('/admin/expositores', ['estado' => 'sin-propuesta'])) ?>" style="text-decoration:none;color:inherit">
      <span class="kpi__label">Sin propuesta</span>
      <strong class="kpi__value"><?= e((string) ($conteos['sin-propuesta'] ?? 0)) ?></strong>
      <span class="kpi__sub">registrados, sin enviarla</span>
    </a>
  </div>

  <?php if (!$soloSinPropuesta): ?>
  <div class="table">
    <div class="table__scroll">
      <div class="table__grid" style="min-width:820px">
        <div class="table__head" style="<?= $columnas ?>">
          <span><?= $titulo('Propuesta', 'propuesta') ?></span><span><?= $titulo('Expositor', 'expositor') ?></span>
          <span><?= $titulo('Categoría', 'categoria') ?></span>
          <span><?= $titulo('Jornada', 'jornada') ?></span><span><?= $titulo('Estado', 'estado') ?></span>
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
              <?php
              // El expositor la cambió desde «Mi exposición» después de la
              // última revisión del comité: hay algo nuevo que mirar. En el
              // mismo segundo no se sabe qué fue primero, y se avisa: mejor
              // de más que callar un cambio.
              $cuando = $actualizadas[(int) $p['id']] ?? null;
              if ($cuando !== null && ($p['revisada_en'] === null || $cuando >= (string) $p['revisada_en'])): ?>
                <span><span class="tag tag--warn" style="font-size:10px">Actualizada por el expositor · <?= e(fecha($cuando)) ?></span></span>
              <?php endif; ?>
            </div>
            <div class="stack" style="gap:2px;min-width:0">
              <span style="color:var(--c-title)"><?= e($p['expositor']) ?></span>
              <span class="mono muted" style="font-size:11px;word-break:break-all"><?= e($p['correo']) ?></span>
              <span class="mono muted" style="font-size:11px"><?= e($p['entidad'] ?: 'Independiente') ?></span>
              <?php if (isset($porEnlace[(int) $p['persona_id']])): ?>
                <span><span class="tag" style="font-size:10px">Por el enlace</span></span>
              <?php endif; ?>
            </div>
            <span style="color:var(--c-text);font-size:13px"><?= e($p['categoria']) ?></span>
            <span class="mono" style="font-size:12.5px;color:var(--c-text)">
              <?php if ($p['dia_asignado'] !== null): ?>
                Día <?= e((string) $p['dia_asignado']) ?><?= $p['hora_inicio'] ? ' · ' . e(substr((string) $p['hora_inicio'], 0, 5)) : '' ?>
              <?php elseif ((int) $p['dia_preferido'] > 0): ?>
                <span class="muted">Pide el día <?= e((string) $p['dia_preferido']) ?></span>
              <?php else: ?>
                <span class="muted">Sin día</span>
              <?php endif; ?>
            </span>
            <span><span class="tag <?= e($clase) ?>"><?= e($etiqueta) ?></span></span>
          </button>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="table__foot">
      <?= App\Nucleo\Respuesta::parcial('parciales/paginacion', [
          'paginacion'    => $paginacion,
          'rutaLista'     => '/admin/expositores',
          'consultaLista' => $conOrden,
          'nombreLista'   => 'propuestas',
      ]) /* ya viene escapado */ ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($soloSinPropuesta || ($estado === '' && $sinPropuesta)): ?>
    <!-- Quienes se registraron como expositores y todavía no mandaron su
         propuesta. En la lista de arriba no salen —es de propuestas—, y son
         justo a quienes hay que escribirles. -->
    <section class="table" id="sin-propuesta" aria-labelledby="titulo-sin-propuesta">
      <div class="table__tools" style="justify-content:space-between">
        <strong id="titulo-sin-propuesta" style="color:var(--c-title);font-weight:600">
          Registrados como expositores, sin propuesta todavía
        </strong>
        <span class="tag"><?= e((string) ($conteos['sin-propuesta'] ?? count($sinPropuesta))) ?></span>
      </div>
      <p class="help" style="margin:0;padding:12px var(--sp-4) 0">
        Tienen el perfil Expositor o llegaron por el enlace privado, pero no han enviado su
        propuesta: casi siempre porque no tenían a mano la hoja de vida o la presentación. La pueden
        enviar cuando quieran desde «Mi exposición», entrando con su correo.
      </p>
      <div class="table__scroll">
        <div class="table__grid" style="min-width:760px">
          <div class="table__head" style="<?= $columnasSin ?>">
            <?php if ($soloSinPropuesta): ?>
              <span><?= $titulo('Expositor', 'expositor') ?></span><span><?= $titulo('Entidad', 'entidad') ?></span>
              <span>Teléfono</span><span><?= $titulo('Se registró', 'registro') ?></span><span></span>
            <?php else: ?>
              <span>Expositor</span><span>Entidad</span><span>Teléfono</span><span>Se registró</span><span></span>
            <?php endif; ?>
          </div>
          <?php if (!$sinPropuesta): ?>
            <div class="empty" style="margin:16px">Nadie: quienes se registraron como expositores ya enviaron su propuesta</div>
          <?php else: foreach ($sinPropuesta as $s): ?>
            <div class="table__row" style="<?= $columnasSin ?>;align-items:center">
              <div class="stack" style="gap:3px;min-width:0">
                <span style="color:var(--c-title)"><?= e($s['nombre'] !== '' ? $s['nombre'] : 'Registro sin completar') ?></span>
                <span class="mono muted" style="font-size:11px;word-break:break-all"><?= e($s['correo']) ?></span>
                <span class="row" style="gap:5px">
                  <span class="tag <?= e(claseRol((string) $s['rol'])) ?>" style="font-size:10px"><?= e(etiquetaRol((string) $s['rol'])) ?></span>
                  <?php if (isset($porEnlace[(int) $s['id']])): ?>
                    <span class="tag" style="font-size:10px">Por el enlace</span>
                  <?php endif; ?>
                </span>
              </div>
              <span class="mono muted" style="font-size:12px"><?= e($s['entidad'] !== '' ? $s['entidad'] : 'Independiente') ?></span>
              <span class="mono" style="font-size:12px"><?= e($s['telefono'] !== '' ? $s['telefono'] : '—') ?></span>
              <span class="mono muted" style="font-size:12px"><?= e(fecha((string) $s['creado_en'])) ?></span>
              <a class="btn btn--sm" href="<?= e(u('/admin/registros/' . (int) $s['id'])) ?>">Ver ficha ›</a>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php if ($soloSinPropuesta): ?>
        <div class="table__foot">
          <?= App\Nucleo\Respuesta::parcial('parciales/paginacion', [
              'paginacion'    => $paginacion,
              'rutaLista'     => '/admin/expositores',
              'consultaLista' => $conOrden,
              'nombreLista'   => 'registrados sin propuesta',
          ]) /* ya viene escapado */ ?>
        </div>
      <?php elseif (($conteos['sin-propuesta'] ?? 0) > count($sinPropuesta)): ?>
        <?php /* Debajo de las propuestas van solo los primeros: la lista entera,
                 con páginas y orden, está en su propio filtro. */ ?>
        <div class="table__foot">
          <span>Los <?= e((string) count($sinPropuesta)) ?> más recientes de <?= e((string) $conteos['sin-propuesta']) ?></span>
          <a href="<?= e(u('/admin/expositores', ['estado' => 'sin-propuesta'])) ?>">Ver los <?= e((string) $conteos['sin-propuesta']) ?> ›</a>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

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
          <?php if ((int) $p['dia_preferido'] > 0): ?>
            <span class="tag">Día preferido: <?= e((string) $p['dia_preferido']) ?></span>
          <?php else: ?>
            <span class="tag tag--mute" title="El día que había pedido se eliminó del evento">Sin día preferido</span>
          <?php endif; ?>
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
          <input type="hidden" name="volver" value="<?= e($volver) ?>">

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
            pueden cambiar después volviendo a entrar aquí, y en las tres le llega un correo con
            la decisión, los cambios que hagas abajo y tus observaciones.
          </p>

          <!-- Ajustes del comité a la exposición. Lo que cambie aquí se guarda
               en la propuesta y se le cuenta al expositor en el correo. -->
          <div class="grid-2" style="grid-template-columns:1.6fr 1fr">
            <div class="field">
              <label class="label" for="titulo-<?= (int) $p['id'] ?>">Título de la exposición</label>
              <input class="input" id="titulo-<?= (int) $p['id'] ?>" name="titulo" maxlength="200"
                     value="<?= e((string) $p['titulo']) ?>">
            </div>
            <div class="field">
              <label class="label" for="categoria-<?= (int) $p['id'] ?>">Categoría</label>
              <select class="select" id="categoria-<?= (int) $p['id'] ?>" name="categoria">
                <?php foreach (Formulario::categoriasDePropuestas($eventoId, (string) $p['categoria']) as $categoria): ?>
                  <option value="<?= e($categoria) ?>" <?= $categoria === (string) $p['categoria'] ? 'selected' : '' ?>><?= e($categoria) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-3">
            <div class="field">
              <label class="label" for="dia-<?= (int) $p['id'] ?>">Jornada asignada</label>
              <?php /* Por id y no por número: si alguien elimina un día mientras
                       esta pantalla está abierta, los siguientes se renumeran.
                       Arranca en el día ya asignado —volver a decidir para
                       cambiar el salón no puede mover la charla de día— y, si
                       no hay, en el que pidió. */ ?>
              <select class="select" id="dia-<?= (int) $p['id'] ?>" name="jornada">
                <?php foreach ($jornadas as $j):
                  $elegida = $p['jornada_asignada'] !== null
                      ? (int) $j['id'] === (int) $p['jornada_asignada']
                      : (int) $j['numero'] === (int) $p['dia_preferido']; ?>
                  <option value="<?= (int) $j['id'] ?>" <?= $elegida ? 'selected' : '' ?>>
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

          <div class="field" style="max-width:220px">
            <label class="label" for="duracion-<?= (int) $p['id'] ?>">Duración</label>
            <select class="select" id="duracion-<?= (int) $p['id'] ?>" name="duracion">
              <?php foreach (Formulario::duracionesDePropuestas($eventoId, (int) $p['duracion_min']) as $min): ?>
                <option value="<?= $min ?>" <?= $min === (int) $p['duracion_min'] ? 'selected' : '' ?>><?= $min ?> minutos</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="obs-<?= (int) $p['id'] ?>">Observación para el expositor</label>
            <textarea class="textarea" id="obs-<?= (int) $p['id'] ?>" name="observacion" rows="3"
                      maxlength="1000" placeholder="Obligatoria si devuelves la propuesta. Le llega tal cual por correo."></textarea>
          </div>

          <label class="row" style="gap:10px;cursor:pointer;align-items:flex-start">
            <input type="checkbox" name="avisar" value="1" checked
                   style="width:18px;height:18px;margin-top:2px;accent-color:var(--c-accent)">
            <span class="help" style="margin:0">
              Avisar a <?= e((string) $p['correo']) ?> por correo
              <?php if (!$correoListo): ?>
                — <strong>el correo saliente no está configurado</strong>; se guardará la decisión
                pero el aviso no podrá salir.
              <?php endif; ?>
            </span>
          </label>

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
