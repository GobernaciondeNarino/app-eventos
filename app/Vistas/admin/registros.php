<?php
/**
 * Registros y asistencia.
 * @var array $personas @var array $jornadas @var array $filtros
 * @var int $total @var bool $puedeVerSensibles
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Persona;

$columnas = 'grid-template-columns:1.5fr 1fr 1.1fr 1.2fr .8fr .9fr 44px';
$conFiltro = array_filter($filtros);
guiones('registros.js');
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Registros y asistencia</h1>
    </div>
    <div class="row no-print">
      <a class="btn btn--sm" href="<?= e(u('/admin/registros/exportar', $conFiltro)) ?>">Exportar CSV</a>
      <?php if ($puedeVerSensibles): ?>
        <button class="btn btn--sm" type="button" data-abrir-modal="modal-sensible">Con caracterización</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-4">
    <?php
    $conIngreso = 0;
    $completos = 0;
    $aMedias = 0;
    $entidades = [];
    foreach ($personas as $p) {
        if (!Persona::registroCompleto($p)) {
            $aMedias++;
        }
        $dias = Persona::diasDe($p['dias'] ?? null);
        if ($dias) {
            $conIngreso++;
        }
        if (count($dias) === count($jornadas) && $jornadas) {
            $completos++;
        }
        if ($p['entidad'] !== '') {
            $entidades[$p['entidad']] = true;
        }
    }
    $kpis = [
      ['En el filtro', numero(count($personas)), 'de ' . numero($total) . ' registros'],
      ['Con al menos un ingreso', numero($conIngreso), $personas ? round($conIngreso / count($personas) * 100) . '%' : '—'],
      ['Asistencia completa', numero($completos), 'los ' . count($jornadas) . ' días'],
      // El cuarto cambia según haga falta: mientras haya registros a medias eso
      // es lo accionable hoy; cuando no los hay, vuelve el dato de reporte.
      $aMedias > 0
        ? ['Sin completar', numero($aMedias), 'crearon acceso, falta el formulario']
        : ['Entidades', numero(count($entidades)), 'representadas'],
    ];
    foreach ($kpis as [$etiqueta, $valor, $sub]): ?>
      <div class="kpi">
        <span class="kpi__label"><?= e($etiqueta) ?></span>
        <strong class="kpi__value"><?= e($valor) ?></strong>
        <span class="kpi__sub"><?= e($sub) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="table">
    <form class="table__tools no-print" method="get" action="<?= e(u('/admin/registros')) ?>">
      <label class="sr-only" for="q">Buscar</label>
      <input class="input" id="q" name="q" value="<?= e((string) $filtros['texto']) ?>"
             placeholder="Buscar por nombre, correo, entidad o municipio…">

      <label class="sr-only" for="rol">Perfil</label>
      <select class="select" id="rol" name="rol">
        <option value="">Todos los perfiles</option>
        <?php foreach (perfilesDelEvento() as $rol => $nombreRol): ?>
          <option value="<?= e($rol) ?>" <?= $filtros['rol'] === $rol ? 'selected' : '' ?>><?= e($nombreRol) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="sr-only" for="dia">Jornada</label>
      <select class="select" id="dia" name="dia">
        <option value="">Todos los días</option>
        <?php foreach ($jornadas as $j): ?>
          <option value="<?= e((string) $j['numero']) ?>" <?= (int) $filtros['dia'] === (int) $j['numero'] ? 'selected' : '' ?>>
            Con ingreso día <?= e((string) $j['numero']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="btn btn--sm" type="submit">Filtrar</button>
      <?php if ($conFiltro): ?>
        <a class="btn btn--sm btn--dashed" href="<?= e(u('/admin/registros')) ?>">Limpiar</a>
      <?php endif; ?>
    </form>

    <div class="table__scroll">
      <div class="table__grid">
        <div class="table__head" style="<?= $columnas ?>">
          <span>Asistente</span><span>Documento</span><span>Municipio</span>
          <span>Entidad</span><span>Perfil</span><span>Ingresos</span>
          <span class="sr-only">Ficha</span>
        </div>

        <?php if (!$personas): ?>
          <div class="empty" style="margin:16px">Ningún registro coincide con el filtro</div>
        <?php else: foreach ($personas as $p):
          $dias = Persona::diasDe($p['dias'] ?? null); ?>
          <div class="table__row" style="<?= $columnas ?>">
            <div class="stack" style="gap:2px;min-width:0">
              <?php /* Quien creó su acceso con correo y contraseña y todavía no
                       llenó el formulario aparece aquí igual: existe, tiene
                       sesión y va a llegar al evento. Pero no tiene carnet, y
                       eso hay que verlo antes de la puerta y no en ella. */ ?>
              <strong style="font-weight:500;color:var(--c-title)">
                <?= $p['nombre'] !== '' ? e($p['nombre']) : '—' ?>
              </strong>
              <span class="row" style="gap:6px">
                <span class="mono muted" style="font-size:11px"><?= e($p['correo']) ?></span>
                <?php if (!Persona::registroCompleto($p)): ?>
                  <span class="tag tag--warn" title="Creó su acceso pero no ha completado el formulario">Sin completar</span>
                <?php endif; ?>
              </span>
            </div>
            <span class="mono" style="font-size:12.5px;color:var(--c-text)"><?php
              if (!($puedeVerDocumento ?? false)) {
                  echo '· · ·';
              } else {
                  $doc = Persona::documento($p);
                  echo $doc === '' ? '<span class="muted">sin dar</span>' : e(documento($doc));
              }
            ?></span>
            <span style="color:var(--c-text)"><?= e($p['municipio'] ?: '—') ?></span>
            <span style="color:var(--c-text)"><?= e($p['entidad'] ?: '—') ?></span>
            <span><span class="tag <?= e(claseRol((string) $p['rol'])) ?>"><?= e(etiquetaRol((string) $p['rol'])) ?></span></span>
            <div class="daymarks">
              <?php foreach ($jornadas as $j):
                $on = in_array((int) $j['numero'], $dias, true); ?>
                <span class="daymark<?= $on ? ' is-on' : '' ?>"
                      title="Día <?= e((string) $j['numero']) ?><?= $on ? ': con ingreso' : ': sin ingreso' ?>"><?= e((string) $j['numero']) ?></span>
              <?php endforeach; ?>
            </div>

            <!-- Es un enlace de verdad, a una pantalla que existe: sin
                 JavaScript abre la ficha en su propia dirección; con él, el
                 guion la trae y la muestra en un diálogo sin salir de la
                 tabla. -->
            <a class="btn btn--icono" href="<?= e(u('/admin/registros/' . (int) $p['id'])) ?>"
               data-ficha="<?= e(u('/admin/registros/' . (int) $p['id'])) ?>"
               title="Ver la ficha de <?= e($p['nombre'] !== '' ? $p['nombre'] : $p['correo']) ?>"
               aria-label="Ver la ficha de <?= e($p['nombre'] !== '' ? $p['nombre'] : $p['correo']) ?>">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                   stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8M5 20.5a7 7 0 0 1 14 0"></path>
              </svg>
            </a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="table__foot">
      <span>Mostrando <?= e(numero(count($personas))) ?> de <?= e(numero($total)) ?> registros</span>
      <span>Actualizado ahora</span>
    </div>
  </div>

  <div class="notice">
    <span class="notice__icon" aria-hidden="true">◆</span>
    <span>
      Las exportaciones quedan registradas en la bitácora con el usuario que las generó.
      La descarga con caracterización está reservada al rol administrador porque contiene
      datos sensibles: género, pertenencia étnica y condición de discapacidad.
    </span>
  </div>

</div>

<!-- Diálogo de la ficha. El contenido lo trae el guion desde
     /admin/registros/{id}; sin JavaScript el botón navega a esa misma
     dirección y este bloque no se usa nunca. -->
<div class="modal hidden" id="modal-ficha" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Ficha del asistente">
    <div class="modal__head">
      <span>Ficha del asistente</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body" data-ficha-destino>
      <div class="row" style="justify-content:center;padding:24px">
        <span class="spinner"></span>
      </div>
    </div>
  </div>
</div>

<?php if ($puedeVerSensibles): ?>
<div class="modal hidden" id="modal-sensible" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Exportar con caracterización">
    <div class="modal__head">
      <span>Datos sensibles</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body">
      <h2 style="font-size:22px">Exportar con caracterización</h2>
      <p class="help">
        Este archivo incluye género, pertenencia étnica y condición de discapacidad: son
        datos sensibles según el artículo 5 de la Ley 1581 de 2012. La descarga queda
        registrada en la bitácora a tu nombre.
      </p>
      <div class="notice notice--warn">
        <span class="notice__icon">▲</span>
        <span>Guárdalo en un lugar seguro y bórralo cuando termines de usarlo.</span>
      </div>
      <div class="row row--end">
        <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
        <a class="btn btn--primary"
           href="<?= e(u('/admin/registros/exportar', $conFiltro + ['caracterizacion' => '1'])) ?>">
          Entiendo, exportar
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
