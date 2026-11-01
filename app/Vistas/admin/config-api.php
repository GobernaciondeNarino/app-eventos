<?php
/**
 * Configuración → API: los tokens con que otros sistemas consultan los
 * registros en JSON, y cómo se consulta.
 *
 * @var array  $tokens       todos, los vigentes primero, sin el token cifrado: 'copiable' dice si hay copia
 * @var ?array $enPantalla   un token en claro, solo en la respuesta de crearlo o de pedir verlo:
 *                           token, nombre, alcance, foto, caracterizacion, nuevo
 * @var bool   $fotoDisponible si la base ya tiene la columna del permiso de la foto (1.13.0)
 * @var bool   $caracterizacionDisponible y la del de la caracterización (1.15.0)
 * @var bool   $copiaDisponible si la base ya guarda los tokens para volver a verlos (1.15.0)
 * @var string $base         la dirección absoluta de la API: …/api/v1
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\TokenApi;

$enPantalla = $enPantalla ?? null;
$ejemplo = $enPantalla !== null ? (string) $enPantalla['token'] : 'TU_TOKEN';
$vigentes = count(array_filter($tokens, static fn(array $t): bool => $t['revocado_en'] === null));
// Si el que está a la vista quedó guardado para volver a verlo: el recién
// creado no, si la base todavía no tiene la columna o si cifrarlo falló.
$enPantallaCopiable = $enPantalla !== null && (bool) array_filter($tokens, static fn(array $t): bool =>
    $t['copiable'] && hash_equals((string) $t['token_hash'], hash('sha256', (string) $enPantalla['token'])));
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

  <?php if ($enPantalla !== null): ?>
    <!-- Solo en esta respuesta: al volver a la pestaña ya no está. -->
    <div class="notice notice--warn" <?= $enPantalla['nuevo'] ? 'data-token-nuevo' : 'data-token-mostrado' ?>>
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:10px;flex:1;min-width:0">
        <strong>Token de «<?= e((string) $enPantalla['nombre']) ?>» · <?= e(TokenApi::ALCANCES[$enPantalla['alcance']] ?? '') ?><?= $enPantalla['foto'] ? ' · con la fotografía' : '' ?><?= $enPantalla['caracterizacion'] ? ' · con la caracterización' : '' ?></strong>
        <span class="sql-preview" style="max-height:none;font-size:15px;letter-spacing:.04em;white-space:normal;word-break:break-all"><?= e((string) $enPantalla['token']) ?></span>
        <span class="help" style="margin:0">
          <?php if (!$enPantalla['nuevo']): ?>
            Cópialo y entrégalo por un canal seguro a quien administra ese sistema. Queda en la
            bitácora que lo volviste a ver.
          <?php elseif ($enPantallaCopiable): ?>
            Cópialo y entrégalo por un canal seguro a quien administra ese sistema. Si se pierde,
            vuelve a verlo aquí con «Ver el token».
          <?php else: ?>
            Cópialo ahora y entrégalo por un canal seguro a quien administra ese sistema: esta es la
            única vez que aparece. Si se pierde, crea otro y elimina este.
          <?php endif; ?>
        </span>
        <span class="row">
          <button class="btn btn--sm" type="button" data-copiar="<?= e((string) $enPantalla['token']) ?>">Copiar el token</button>
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
            $conFoto = TokenApi::daFoto($t);
            $conCaracterizacion = TokenApi::daCaracterizacion($t); ?>
            <div class="kv" style="flex-direction:column;align-items:stretch;gap:10px" data-token="<?= (int) $t['id'] ?>">
              <span class="stack" style="gap:4px;min-width:0">
                <strong style="font-weight:500;color:var(--c-title)"><?= e($t['nombre']) ?></strong>
                <span class="row" style="gap:6px">
                  <span class="tag <?= $vigente ? 'tag--ok' : 'tag--mute' ?>"><?= $vigente ? 'Vigente' : 'Revocado' ?></span>
                  <span class="tag <?= $t['alcance'] === 'contacto' ? 'tag--warn' : '' ?>"><?= e(TokenApi::ALCANCES[$t['alcance']] ?? $t['alcance']) ?></span>
                  <?php if ($conFoto): ?><span class="tag tag--warn" data-con-foto
                        title="Desde el <?= e(fecha((string) $t['foto_desde'])) ?>">Con fotografía</span><?php endif; ?>
                  <?php if ($conCaracterizacion): ?><span class="tag tag--warn" data-con-caracterizacion
                        title="Desde el <?= e(fecha((string) $t['caracterizacion_desde'])) ?>">Con caracterización</span><?php endif; ?>
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
                <?php if ($vigente && !$t['copiable'] && $copiaDisponible): ?>
                  <span class="help" style="margin:0" data-sin-copia>
                    Se creó antes de que la plataforma guardara una copia: no se puede volver a ver. Si se
                    perdió, crea otro y elimina este cuando el sistema ya use el nuevo.
                  </span>
                <?php endif; ?>
              </span>
              <span class="row" style="gap:8px">
                <?php if ($vigente && $t['copiable']): ?>
                  <!-- Un POST con testigo: el token no viaja en la lista, y verlo queda en la bitácora. -->
                  <form method="post" action="<?= e(u('/admin/configuracion/api/ver')) ?>">
                    <?= testigo() ?>
                    <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn--sm" type="submit">Ver el token</button>
                  </form>
                <?php endif; ?>
                <?php if ($vigente && $fotoDisponible): ?>
                  <!-- Los permisos se dan o se quitan sin cambiar el token. -->
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
                <?php if ($vigente && $caracterizacionDisponible): ?>
                  <form method="post" action="<?= e(u('/admin/configuracion/api/caracterizacion')) ?>"
                        data-confirmar="<?= $conCaracterizacion
                            ? 'La API dejará de dar la caracterización con el token de «' . e($t['nombre']) . '». ¿Continuar?'
                            : 'Desde la próxima consulta, cada persona traerá su género, rango de edad, grupo étnico y discapacidad con el token de «' . e($t['nombre']) . '». Son datos sensibles: dalos solo a un sistema de la propia organización que los necesite. ¿Continuar?' ?>">
                    <?= testigo() ?>
                    <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="permitir" value="<?= $conCaracterizacion ? '0' : '1' ?>">
                    <button class="btn btn--sm" type="submit"><?= $conCaracterizacion ? 'Quitar la caracterización' : 'Incluir la caracterización' ?></button>
                  </form>
                <?php endif; ?>
                <!-- Los dos que cortan el token van juntos, a la derecha: si no caben
                     en la línea, bajan juntos a la siguiente. -->
                <span class="row" style="gap:8px;margin-left:auto">
                  <?php if ($vigente): ?>
                    <form method="post" action="<?= e(u('/admin/configuracion/api/revocar')) ?>"
                          data-confirmar="Desde ahora la API rechazará el token de «<?= e($t['nombre']) ?>». Queda en la lista como revocado; los demás siguen funcionando. ¿Continuar?">
                      <?= testigo() ?>
                      <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                      <button class="btn btn--sm btn--danger" type="submit">Revocar</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= e(u('/admin/configuracion/api/eliminar')) ?>"
                        data-confirmar="<?= $vigente
                            ? 'Se eliminará el token de «' . e($t['nombre']) . '»: desde ahora la API lo rechazará y saldrá de esta lista. Los demás siguen funcionando. ¿Continuar?'
                            : 'Se eliminará de la lista el token revocado de «' . e($t['nombre']) . '». ¿Continuar?' ?>">
                    <?= testigo() ?>
                    <input type="hidden" name="token" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn--sm btn--danger" type="submit">Eliminar</button>
                  </form>
                </span>
              </span>
            </div>
          <?php endforeach; ?>
            <p class="help" style="margin:10px 0 4px">
              Revocar deja el token en la lista, con su historial; eliminar lo quita. En los dos casos
              la API lo rechaza desde ese momento, y la bitácora guarda quién lo hizo.
            </p>
          <?php endif; ?>
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

          <?php if ($caracterizacionDisponible): ?>
          <label class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap;cursor:pointer">
            <input type="checkbox" name="caracterizacion" value="1"
                   style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--c-accent)">
            <span class="stack" style="gap:2px">
              <span>Incluir la caracterización</span>
              <span class="help" style="margin:0">
                Cada persona trae en <span class="mono">caracterizacion</span> su género, rango de edad,
                grupo étnico y discapacidad. Son datos sensibles (Ley 1581 de 2012): solo para un sistema
                de la propia organización que los necesite, como un tablero de indicadores. Se puede
                dar o quitar después.
              </span>
            </span>
          </label>
          <?php elseif ($fotoDisponible): ?>
            <p class="help" style="margin:0">
              Para dar también la caracterización, la base de datos tiene que ponerse al día: en el
              panel, pulsa «Actualizar la base de datos».
            </p>
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
            La API nunca entrega el número de identificación ni las observaciones del comité. La
            fotografía y la caracterización, solo a los tokens con cada permiso. Cada token admite
            <?= e(numero(TokenApi::TOPE)) ?> consultas cada <?= e((string) TokenApi::VENTANA_MINUTOS) ?>
            minutos; ver una fotografía no cuenta como consulta.
          </p>
          <p class="help" style="margin:0">
            La caracterización —género, rango de edad, grupo étnico y discapacidad— es un dato
            sensible: no la publiques persona por persona. La bitácora anota cada día en que un token
            la recibe.
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
