<?php
/**
 * La pantalla de la puerta.
 *
 * La misma para el equipo organizador y para el Staff. Quién la abre solo
 * cambia dos cosas: el rótulo de quién está en turno y a dónde apunta el
 * formulario de búsqueda, que es distinto para cada uno.
 *
 * @var array $jornadas @var array|null $jornadaHoy @var int $escaneosHoy
 * @var array|null $acreditador
 * @var string|null $busqueda @var array|null $encontradas
 */
defined('EVENTOS_TIC') || exit;

guiones('qr-lector.js', 'escaner.js');

$esStaff = ($acreditador['tipo'] ?? 'equipo') === 'staff';
$rutaBuscar = $esStaff ? '/acreditar/buscar' : '/admin/escaner/buscar';
?>
<div class="view view--medium split" style="align-items:start">

  <div class="stack stack--4">
    <span class="kicker">
      <?= $esStaff ? 'Staff' : 'Administrador' ?><?= $jornadaHoy ? ' · Día ' . e((string) $jornadaHoy['numero']) : '' ?>
    </span>
    <h1 class="hero-title">Escanear el carnet<br><em>del asistente</em></h1>
    <p class="lead">
      Ruta alterna cuando la persona no puede leer el código de la puerta. Al leer el QR
      del carnet, el ingreso queda sellado con la fecha y la hora, atribuido a tu cuenta.
    </p>

    <div class="card">
      <div class="card__head"><span>Quién está en turno</span></div>
      <div class="card__body row row--between">
        <span style="font-size:14px;color:var(--c-title)">
          <?= e((string) ($acreditador['nombre'] ?? '—')) ?><?php
            $puesto = (string) ($acreditador['puesto'] ?? '');
            echo $puesto !== '' ? ' · ' . e($puesto) : '';
          ?>
        </span>
        <span class="tag"><?= e(numero($escaneosHoy)) ?> escaneos hoy</span>
      </div>
    </div>

    <?php if (!$jornadaHoy): ?>
      <div class="notice notice--warn">
        <span class="notice__icon">▲</span>
        <span>
          Hoy no hay ninguna jornada programada. Los escaneos no podrán sellarse hasta que
          la fecha coincida con una jornada del evento.
        </span>
      </div>
    <?php endif; ?>

    <!-- Búsqueda manual: la salida cuando el carnet no se puede leer -->
    <form class="card" method="post" action="<?= e(u($rutaBuscar)) ?>">
      <?= testigo() ?>
      <div class="card__head"><span>Buscar a mano</span></div>
      <div class="card__body stack stack--3">
        <p class="help">
          Cuando el teléfono se quedó sin batería, el código está rayado o la persona llegó
          sin nada. Por <strong>número de identificación</strong> hay que escribirlo completo:
          está cifrado en la base y solo se puede comparar exacto. Por nombre, correo o
          entidad basta con una parte.
        </p>
        <div class="row" style="flex-wrap:nowrap">
          <label class="sr-only" for="q">Identificación, nombre o correo</label>
          <input class="input" id="q" name="q" value="<?= e((string) ($busqueda ?? '')) ?>"
                 placeholder="Identificación, nombre o correo" style="flex:1">
          <button class="btn btn--sm btn--primary" type="submit">Buscar</button>
        </div>

        <?php if (isset($encontradas)): ?>
          <?php if (!$encontradas): ?>
            <div class="empty">Nadie coincide con «<?= e((string) $busqueda) ?>»</div>
          <?php else: ?>
            <div class="stack stack--2">
              <?php foreach ($encontradas as $p): ?>
                <div class="contact" style="grid-template-columns:1fr auto">
                  <div class="stack" style="gap:2px;min-width:0">
                    <strong class="contact__name" style="font-size:15px"><?= e($p['nombre']) ?></strong>
                    <span class="contact__org"><?= e($p['entidad'] ?: 'Independiente') ?> · <?= e($p['municipio'] ?: '—') ?></span>
                  </div>
                  <?php
                  $cred = \App\Modelos\Credencial::dePersona((int) $p['id']);
                  if ($cred): ?>
                    <a class="btn btn--sm btn--primary" href="<?= e(u('/c/' . $cred['token'])) ?>">Acreditar</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="phone">
    <div class="phone__screen" style="min-height:520px">
      <div class="phone__status">
        <span><?= e(date('g:i')) ?></span>
        <span>Lector integrado</span>
      </div>

      <div class="phone__pane" data-escaner data-destino-tipo="carnet">
        <div class="scanner" data-escaner-marco>
          <video data-escaner-video playsinline muted hidden></video>
          <div class="scanner__box"></div>
          <div class="scanner__line"></div>
          <span class="scanner__hint" data-escaner-pista>Lee el QR del reverso del carnet</span>
        </div>
        <div class="stack stack--2">
          <button class="btn btn--primary btn--block btn--lg" type="button" data-escaner-iniciar>
            Abrir la cámara
          </button>

          <!-- La salida cuando el navegador no entrega la cámara en directo
               —Safari dentro de otra aplicación, un permiso denegado—: se toma
               una foto con la aplicación de cámara del sistema y la leemos
               nosotros. El campo va oculto porque el botón es el que se ve. -->
          <button class="btn btn--block" type="button" data-escaner-foto hidden>
            Lector desde cámara
          </button>
          <input class="sr-only" type="file" accept="image/*" capture="environment"
                 data-escaner-archivo tabindex="-1" aria-hidden="true">
        </div>

        <p class="help" data-escaner-alterna>
          «Abrir la cámara» lee en vivo. «Lector desde cámara» abre la aplicación de cámara
          del teléfono para tomar una foto del código, que sirve igual.
        </p>
      </div>
    </div>
  </div>

</div>
