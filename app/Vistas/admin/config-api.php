<?php
/**
 * Configuración → API: los tokens con que otros sistemas consultan los
 * registros en JSON, y cómo se consulta.
 *
 * @var array  $tokens       todos, los vigentes primero
 * @var string $tokenNuevo   el recién creado, solo en la respuesta de crearlo
 * @var string $nombreNuevo @var string $alcanceNuevo
 * @var bool   $fotoNuevo    si el recién creado trae el enlace de la fotografía
 * @var bool   $fotoDisponible si la base ya tiene la columna del permiso (1.13.0)
 * @var string $base         la dirección absoluta de la API: …/api/v1
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\TokenApi;

$ejemplo = $tokenNuevo !== '' ? $tokenNuevo : 'TU_TOKEN';
$vigentes = count(array_filter($tokens, static fn(array $t): bool => $t['revocado_en'] === null));
$consultas = [
    ['Todo lo que se puede consultar', $base . '?token=' . $ejemplo],
    ['Los perfiles y cuántos hay de cada uno', $base . '/perfiles?token=' . $ejemplo],
    ['Los participantes', $base . '/personas?perfil=participante&token=' . $ejemplo],
    ['Los expositores aprobados, en el orden de la agenda', $base . '/expositores?estado=aprobada&orden=jornada&token=' . $ejemplo],
    ['Quienes entraron el día 1, de 100 en 100', $base . '/personas?dia=1&por=100&token=' . $ejemplo],
];
?>
<div class="view view--wide stack stack--4">

  <?php $pestana = 'api'; require __DIR__ . '/../parciales/pestanas-configuracion.php'; ?>

  <div class="stack stack--2">
    <span class="kicker">Configuración</span>
    <h1>API de consulta</h1>
    <p class="help">
      Para que otro sistema —un sitio web, un tablero, otra dependencia— consulte en JSON quién
      se registró: expositores, participantes y los demás perfiles, con filtros en la dirección.
      Solo lee: por aquí no se cambia nada.
    </p>
  </div>

  <?php if ($tokenNuevo !== ''): ?>
    <!-- Se muestra una sola vez: en la base queda solo su huella. -->
    <div class="notice notice--warn" data-token-nuevo>
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:10px;flex:1;min-width:0">
        <strong>Token de «<?= e($nombreNuevo) ?>» · <?= e(TokenApi::ALCANCES[$alcanceNuevo] ?? '') ?><?= $fotoNuevo ? ' · con la fotografía' : '' ?></strong>
        <span class="sql-preview" style="max-height:none;font-size:15px;letter-spacing:.04em;white-space:normal;word-break:break-all"><?= e($tokenNuevo) ?></span>
        <span class="help" style="margin:0">
          Cópialo ahora y entrégalo por un canal seguro a quien administra ese sistema: esta es la
          única vez que aparece. Si se pierde, crea otro y revoca este.
        </span>
        <span class="row">
          <button class="btn btn--sm" type="button" data-copiar="<?= e($tokenNuevo) ?>">Copiar el token</button>
        </span>
      </span>
    </div>
  <?php endif; ?>

  <div class="split" style="align-items:start">
    <div class="stack stack--4">

      <div class="card">
        <div class="card__head">
          <span>Tokens</span>
          <span class="muted" data-vigentes="<?= $vigentes ?>"><?= e(numero($vigentes)) ?> <?= $vigentes === 1 ? 'vigente' : 'vigentes' ?></span>
        </div>
        <div class="card__body--tight">
          <?php if (!$tokens): ?>
            <div class="empty" style="margin:8px 0">Todavía no hay ninguno: la API no responde a nadie</div>
          <?php else: foreach ($tokens as $t):
            $vigente = $t['revocado_en'] === null;
            $conFoto = TokenApi::daFoto($t); ?>
            <div class="kv" style="align-items:flex-start;gap:14px" data-token="<?= (int) $t['id'] ?>">
              <span class="stack" style="gap:4px;min-width:0;flex:1">
                <strong style="font-weight:500;color:var(--c-title)"><?= e($t['nombre']) ?></strong>
                <span class="row" style="gap:6px">
                  <span class="tag <?= $vigente ? 'tag--ok' : 'tag--mute' ?>"><?= $vigente ? 'Vigente' : 'Revocado' ?></span>
                  <span class="tag <?= $t['alcance'] === 'contacto' ? 'tag--warn' : '' ?>"><?= e(TokenApi::ALCANCES[$t['alcance']] ?? $t['alcance']) ?></span>
                  <?php if ($conFoto): ?><span class="tag tag--warn" data-con-foto
                        title="Desde el <?= e(fecha((string) $t['foto_desde'])) ?>">Con fotografía</span><?php endif; ?>
                </span>
                <span class="mono muted" style="font-size:11px">
                  <?= e((string) $t['pista']) ?>… · creado <?= e(fecha((string) $t['creado_en'])) ?><?= $t['creado_por_nombre'] ? ' por ' . e($t['creado_por_nombre']) : '' ?>
                </span>
                <span class="mono muted" style="font-size:11px">
                  <?= $t['ultimo_uso_en'] !== null
                      ? 'Último uso ' . e(fecha((string) $t['ultimo_uso_en'])) . ' ' . e(hora((string) $t['ultimo_uso_en'])) . ' · ' . e(numero($t['usos'])) . ' consultas'
                      : 'Nunca se ha usado' ?>
                  <?= !$vigente ? ' · revocado ' . e(fecha((string) $t['revocado_en'])) : '' ?>
                </span>
              </span>
              <?php if ($vigente): ?>
                <span class="stack" style="gap:8px;align-items:flex-end;flex:none">
                  <?php if ($fotoDisponible): ?>
                  <!-- El permiso de la fotografía se da o se quita sin cambiar el token. -->
                  <form method="post" action="<?= e(u('/admin/configuracion/api/foto')) ?>"
                        data-confirmar="<?= $conFoto
                            ? 'La API dejará de dar la fotografía con el token de «' . e($t['nombre']) . '», y los enlaces que ya entregó dejarán de servir. ¿Continuar?'
                            : 'Desde la próxima consulta, cada persona traerá el enlace de su fotografía con el token de «' . e($t['nombre']) . '». Ese enlace se abre sin el token. ¿Continuar?' ?>">
                    <?= testigo() ?>
                    <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="permitir" value="<?= $conFoto ? '0' : '1' ?>">
                    <button class="btn btn--sm" type="submit"><?= $conFoto ? 'Quitar la fotografía' : 'Incluir la fotografía' ?></button>
                  </form>
                  <?php endif; ?>
                  <form method="post" action="<?= e(u('/admin/configuracion/api/revocar')) ?>"
                        data-confirmar="Desde ahora la API rechazará el token de «<?= e($t['nombre']) ?>». Los demás siguen funcionando. ¿Continuar?">
                    <?= testigo() ?>
                    <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn--sm btn--danger" type="submit">Revocar</button>
                  </form>
                </span>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <form class="card" method="post" action="<?= e(u('/admin/configuracion/api/crear')) ?>">
        <?= testigo() ?>
        <div class="card__head"><span>Crear un token</span></div>
        <div class="card__body stack stack--4">
          <div class="field">
            <label class="label" for="api-nombre">Para qué sistema es</label>
            <input class="input" id="api-nombre" name="nombre" required minlength="3" maxlength="120"
                   placeholder="Ej. Sitio web de la Secretaría TIC">
            <span class="help">Uno por sistema: así se sabe quién consulta y se revoca uno sin afectar a los demás.</span>
          </div>

          <fieldset class="stack stack--2" style="border:0;padding:0;margin:0">
            <legend class="label">Qué datos puede consultar</legend>
            <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
              <input type="radio" name="alcance" value="basico" checked style="margin-top:3px;accent-color:var(--c-accent)">
              <span class="stack" style="gap:2px">
                <span>Sin datos de contacto</span>
                <span class="help" style="margin:0">Nombre, perfil, entidad, municipio, carnet, ingresos y la propuesta de quien expone.</span>
              </span>
            </label>
            <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
              <input type="radio" name="alcance" value="contacto" style="margin-top:3px;accent-color:var(--c-accent)">
              <span class="stack" style="gap:2px">
                <span>Con correo y teléfono</span>
                <span class="help" style="margin:0">Lo mismo, y además cómo contactar a cada persona. Solo para sistemas de la propia organización.</span>
              </span>
            </label>
          </fieldset>

          <?php if (!$fotoDisponible): ?>
            <p class="help" style="margin:0">
              Para dar también el enlace de la fotografía, la base de datos tiene que ponerse al día:
              en el panel, pulsa «Actualizar la base de datos».
            </p>
          <?php else: ?>
          <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
            <input type="checkbox" name="foto" value="1"
                   style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--c-accent)">
            <span class="stack" style="gap:2px">
              <span>Incluir el enlace de la fotografía</span>
              <span class="help" style="margin:0">
                Cada persona trae en <span class="mono">foto</span> la dirección de su fotografía, para
                mostrarla en una página: esa dirección no lleva el token. Deja de servir si se revoca el
                token o se le quita este permiso, que se puede dar o quitar después.
              </span>
            </span>
          </label>
          <?php endif; ?>

          <div class="row row--end">
            <button class="btn btn--primary" type="submit">Crear token</button>
          </div>
        </div>
      </form>
    </div>

    <div class="stack stack--4">
      <div class="card">
        <div class="card__head"><span>Cómo se consulta</span></div>
        <div class="card__body stack stack--3">
          <p class="help" style="margin:0">
            Cada consulta lleva el token en la dirección, <span class="mono">?token=…</span>, o en la
            cabecera <span class="mono">Authorization: Bearer …</span>. Los filtros se agregan en la
            dirección, todos opcionales. La respuesta es JSON, por páginas de 50 —hasta 200 con
            <span class="mono">por=200</span>—.
          </p>
          <?php foreach ($consultas as [$que, $url]): ?>
            <div class="stack" style="gap:4px">
              <span class="help" style="margin:0"><?= e($que) ?></span>
              <span class="mono" style="font-size:11.5px;word-break:break-all;color:var(--c-title)"><?= e($url) ?></span>
            </div>
          <?php endforeach; ?>
          <p class="help" style="margin:0">
            Filtros de <span class="mono">/personas</span>: <span class="mono">perfil</span> (uno o varios
            separados por comas), <span class="mono">q</span>, <span class="mono">entidad</span>,
            <span class="mono">municipio</span>, <span class="mono">departamento</span>,
            <span class="mono">dia</span>, <span class="mono">completo</span>,
            <span class="mono">registrado_desde</span>, <span class="mono">registrado_hasta</span>,
            <span class="mono">orden</span> y <span class="mono">dir</span>. De
            <span class="mono">/expositores</span>: <span class="mono">estado</span>,
            <span class="mono">categoria</span>, <span class="mono">dia</span> y los mismos de texto.
            La propia API los describe en <span class="mono">/api/v1</span>, y la referencia
            completa, con ejemplos, está en <span class="mono">docs/API.md</span> del repositorio.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><span>Cuidado con el token</span></div>
        <div class="card__body stack stack--3">
          <p class="help" style="margin:0">
            Quien tenga el token puede consultar los registros. <strong>No lo pongas en una página web
            pública ni en el JavaScript de un navegador</strong>: úsalo desde un servidor. Cuando se
            pueda, mándalo en la cabecera <span class="mono">Authorization: Bearer …</span> en vez de
            en la dirección, que queda escrita en los registros de los servidores web.
          </p>
          <p class="help" style="margin:0">
            La API nunca entrega el número de identificación, la caracterización ni las observaciones
            del comité. La fotografía, solo a los tokens con ese permiso. Cada token admite
            <?= e(numero(TokenApi::TOPE)) ?> consultas cada <?= e((string) TokenApi::VENTANA_MINUTOS) ?>
            minutos; ver una fotografía no cuenta como consulta.
          </p>
          <p class="help" style="margin:0">
            Los enlaces de la fotografía sí se pueden poner en una página pública, porque no llevan el
            token. La foto es un dato personal: publica solo la de quien lo autorizó —la API dice en
            <span class="mono">en_directorio</span> quién aceptó aparecer en el directorio del evento—.
          </p>
        </div>
      </div>
    </div>
  </div>

</div>
