<?php
/**
 * Carnet digital.
 * @var array $credencial @var string $documento @var string $qr
 * @var string $contenidoQr @var string $qrAcceso @var string $urlAcceso
 * @var array $historial @var array $persona @var array $tema @var array $dispositivos
 * @var array|null $exposicion  la propuesta de quien expone ([] si no la ha enviado); null si no expone
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$rol = (string) $persona['rol'];
// El nombre del perfil, como lo configuró el evento de esta persona.
$nombreRol = etiquetaRol($rol, (int) $persona['evento_id']);
$foto = (string) ($persona['foto'] ?? '') !== '' ? u('/medios/foto/' . (int) $persona['id']) : '';
$entidad = (string) $persona['entidad'] !== '' ? (string) $persona['entidad'] : 'Independiente';
// Letra algo menor para los textos muy largos; el resto lo hace el máximo de
// renglones del CSS. Sin esto, la entidad se salía por debajo del carnet.
$talla = static function (string $texto, int $corto, int $largo): string {
    $n = mb_strlen($texto);
    return $n <= $corto ? '' : ($n <= $largo ? ' is-largo' : ' is-muy-largo');
};
guiones('carnet.js');
?>
<div class="view view--medium split--reverse">

  <!-- ================= La credencial ================= -->
  <div class="carnet-wrap">
    <div class="carnet-clip" aria-hidden="true"></div>

    <button class="carnet" id="carnet" type="button" data-rol="<?= e($rol) ?>"
            aria-label="Carnet digital. Presiona para ver el reverso con el código QR.">
      <div class="carnet__inner">

        <div class="carnet__face">
          <div class="carnet__head">
            <?= $marca('lg') ?>
            <span class="brandtext">
              <span class="brandtext__name"><?= e($evento['nombre']) ?></span>
              <span class="brandtext__sub"><?= e($evento['dependencia'] ?: 'Innovación · Territorio · Datos') ?></span>
            </span>
          </div>

          <div class="carnet__photo">
            <div>
              <?php if ($foto !== ''): ?>
                <img src="<?= e($foto) ?>" alt="">
              <?php else: ?>
                <span class="carnet__photo-empty"><?= e(iniciales((string) $persona['nombre'])) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="carnet__rol<?= tallaRol($nombreRol) ?>"><?= e(mb_strtoupper($nombreRol)) ?></div>

          <div class="carnet__fields">
            <?php foreach ([
              ['Nombre', $persona['nombre'], ' carnet__v--nombre' . $talla((string) $persona['nombre'], 26, 40)],
              ['Identificación', documento($documento), ''],
              ['Entidad', $entidad, ' carnet__v--entidad' . $talla($entidad, 30, 48)],
            ] as [$k, $valor, $clase]): ?>
              <div class="carnet__field">
                <span class="carnet__k"><?= e($k) ?></span>
                <span class="carnet__v<?= $clase ?>"><?= e($valor) ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="carnet__ticks" aria-hidden="true">
            <span style="width:26px"></span><span style="width:14px"></span>
            <span style="width:32px"></span><span style="width:18px"></span>
            <span style="width:24px"></span><span style="width:11px"></span>
            <span style="width:29px"></span>
          </div>
        </div>

        <div class="carnet__face carnet__face--back">
          <div class="carnet__backhead">
            <span class="brandtext__name"><?= e($evento['nombre']) ?></span>
            <span class="brandtext__sub">Credencial digital · <?= e($credencial['codigo']) ?></span>
          </div>

          <div class="carnet__qrbox">
            <?= $qr /* SVG generado por el servidor */ ?>
            <span class="carnet__qrcap">Muéstralo para intercambiar contacto</span>
          </div>

          <div class="row row--between" style="padding:0 8px">
            <span class="stack" style="gap:3px;min-width:0">
              <span class="carnet__v carnet__quien" style="font-size:14px"><?= e($persona['nombre']) ?></span>
              <span class="brandtext__sub carnet__quien"><?= e($entidad) ?></span>
            </span>
            <span class="carnet__chip" aria-hidden="true"></span>
          </div>

          <div class="carnet__foot">
            <div class="barcode" aria-hidden="true">
              <?php
              // Patrón derivado del documento: estable entre recargas, no aleatorio.
              $base = preg_replace('/\D/', '', $documento) ?: '0000000000';
              for ($i = 0; $i < 42; $i++) {
                  $d = (int) $base[$i % strlen($base)] ?: 1;
                  $claro = ($i + $d) % 3 === 0;
                  echo '<span style="flex:' . (1 + $d % 3) . ';width:0' . ($claro ? ';opacity:.15' : '') . '"></span>';
              }
              ?>
            </div>
            <span class="carnet__code"><?= e(identificacion($persona['tipo_documento'], $documento) ?: $credencial['codigo']) ?></span>
          </div>
        </div>

      </div>
    </button>

    <div class="row no-print">
      <button class="btn btn--sm" type="button" id="voltear">Ver el reverso</button>
      <a class="btn btn--sm" href="<?= e(u('/carnet/imprimir')) ?>" target="_blank" rel="noopener">Imprimir</a>
    </div>
  </div>

  <!-- ================= Explicación ================= -->
  <div class="stack stack--4">
    <span class="kicker">Credencial emitida</span>
    <h1>Tu carnet digital</h1>
    <p class="lead">
      Toca el carnet para ver el reverso. Ese QR sirve para que un organizador registre tu
      ingreso si no alcanzas a escanear el de la puerta, y para intercambiar contacto con
      otros asistentes.
    </p>

    <?php if ($exposicion !== null):
      // Quien expone llega aquí al entrar: aquí ve cómo va su propuesta y el
      // camino para enviarla, corregirla o cambiar sus documentos.
      [$tituloExpo, $textoExpo] = match ((string) ($exposicion['estado'] ?? '')) {
          'pendiente' => ['Tu propuesta está en revisión', 'Puedes cambiarla o reemplazar tus documentos mientras tanto.'],
          'observada' => ['El comité te pide ajustes a tu propuesta', 'Mira sus observaciones y corrígela.'],
          'aprobada'  => ['Tu exposición está en la agenda', 'Puedes subir la presentación final cuando la tengas.'],
          'rechazada' => ['Tu propuesta no fue seleccionada', 'Puedes ver lo que dijo el comité.'],
          default     => ['Todavía no has enviado tu propuesta', 'Envíala con tu hoja de vida y tu presentación.'],
      }; ?>
      <a class="notice no-print" href="<?= e(u('/exposicion')) ?>" style="text-decoration:none;color:inherit" data-exposicion>
        <span class="notice__icon" aria-hidden="true">◆</span>
        <span class="stack" style="gap:4px">
          <strong><?= e($tituloExpo) ?></strong>
          <span class="help" style="margin:0"><?= e($textoExpo) ?> Ir a «Mi exposición» ›</span>
        </span>
      </a>
    <?php endif; ?>

    <?php if ($qrAcceso !== ''): ?>
      <!-- ===== El otro QR: el que abre la sesión =====================
           Va aparte del carnet y con otro color a propósito. El del reverso
           se enseña; este es una llave. Confundirlos es justo lo que hacía
           que escanear el propio carnet terminara en «identifícate». -->
      <div class="card card--acceso no-print">
        <div class="card__head">
          <span>Mi QR de acceso</span>
          <span class="tag">Personal</span>
        </div>
        <div class="card__body qr-acceso">
          <div class="qr-acceso__code"><?= $qrAcceso /* SVG generado por el servidor */ ?></div>
          <div class="stack stack--3">
            <p class="help" style="margin:0">
              Escanéalo con la cámara de cualquier teléfono y entrarás a tu cuenta sin
              escribir nada: ni correo, ni código, ni contraseña. Sirve para volver a tu
              carnet desde otro dispositivo, o si perdiste la sesión.
            </p>
            <p class="help" style="margin:0;color:var(--c-title)">
              <strong>No lo compartas ni lo publiques.</strong> Quien lo escanee entra como tú.
              El del reverso del carnet sí se puede enseñar: ese solo intercambia contacto.
            </p>
            <div class="row">
              <a class="btn btn--sm" href="<?= e(u('/medios/qr/acceso.svg')) ?>"
                 download="qr-acceso.svg">Descargar</a>
              <button class="btn btn--sm" type="button" data-copiar="<?= e($urlAcceso) ?>">
                Copiar el enlace
              </button>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><span>Resumen del registro</span></div>
      <div class="card__body--tight">
        <?php foreach ([
          ['Credencial', $credencial['codigo']],
          ['Perfil', $nombreRol],
          ['Documento', identificacion($persona['tipo_documento'], $documento) ?: 'No registrado'],
          ['Correo', $persona['correo']],
          ['Municipio', $persona['municipio'] ?: 'Sin registrar'],
          ['Entidad', $persona['entidad'] ?: 'Independiente'],
        ] as [$k, $valor]): ?>
          <div class="kv">
            <span class="kv__k"><?= e($k) ?></span>
            <strong class="kv__v"><?= e($valor) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><span>Mis ingresos</span></div>
      <div class="card__body--tight">
        <?php foreach ($historial as $h): ?>
          <div class="kv">
            <span class="kv__k">Día <?= e((string) $h['numero']) ?> · <?= e(fecha((string) $h['fecha'])) ?></span>
            <span class="mono" style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:<?= $h['registrado_en'] ? 'var(--c-accent)' : 'var(--c-muted)' ?>">
              <?= $h['registrado_en'] ? 'Ingresó ' . e(hora((string) $h['registrado_en'])) : 'Sin ingreso' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card no-print">
      <div class="card__head"><span>Qué viaja en el QR</span></div>
      <div class="card__body stack stack--3">
        <p class="help">
          El código del carnet no contiene datos personales: solo un identificador que la
          plataforma resuelve. Si alguien fotografía tu carnet, no obtiene tu identificación
          ni tu caracterización.
        </p>
        <div class="sql-preview" style="max-height:none"><?= e($contenidoQr) ?></div>
      </div>
    </div>

    <?php if ($dispositivos): ?>
      <div class="card no-print">
        <div class="card__head">
          <span>Dispositivos recordados</span>
          <span class="muted"><?= e((string) count($dispositivos)) ?></span>
        </div>
        <div class="card__body stack stack--3">
          <p class="help">
            En estos teléfonos o computadores no te volvemos a pedir el código: la plataforma
            reconoce el dispositivo y abre tu sesión sola.
          </p>
          <div class="card__body--tight" style="padding:0">
            <?php foreach ($dispositivos as $d): ?>
              <div class="kv">
                <span class="kv__k"><?= e(navegadorLegible((string) $d['agente'])) ?></span>
                <span class="muted" style="font-size:12px">
                  <?= e(fecha((string) $d['ultimo_uso'])) ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="post" action="<?= e(u('/carnet/dispositivos')) ?>"
                data-confirmar="Se cerrará tu sesión en todos los dispositivos y tu QR de acceso actual dejará de servir. ¿Continuar?">
            <?= testigo() ?>
            <button class="btn btn--sm btn--danger" type="submit">
              Cerrar sesión en todos y renovar mi QR
            </button>
          </form>
          <p class="help" style="margin:0">
            Úsalo si perdiste el teléfono o si compartiste tu QR de acceso por error.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <div class="row no-print">
      <a class="btn btn--primary" href="<?= e(u('/checkin')) ?>">Registrar mi ingreso</a>
      <a class="btn" href="<?= e(u('/contactos')) ?>">Mis contactos</a>
      <a class="btn" href="<?= e(u('/registro')) ?>">Mis datos</a>
    </div>
  </div>

</div>
