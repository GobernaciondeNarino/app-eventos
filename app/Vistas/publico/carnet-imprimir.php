<?php
/**
 * El carnet para imprimir o descargar: las dos caras a tamaño real, una junto
 * a la otra.
 *
 * Tiene su propio diseño, medido en milímetros, y no el del carnet de la
 * pantalla con las medidas cambiadas a la fuerza: eso era lo que imprimía la
 * foto en blanco, el nombre y la entidad por fuera del recuadro y un marco
 * suelto encima de las dos caras. Lo que se ve aquí es lo que sale en el papel.
 *
 * Cada cara mide 6 × 9,5 cm, la proporción de una tarjeta CR80. Comparten un
 * borde: se recorta por la línea continua y se dobla por la punteada, y queda
 * un carnet con el frente de un lado y el código del otro.
 *
 * «Descargar» genera en el navegador una imagen PNG de cada cara, con el mismo
 * diseño (assets/js/carnet-imprimir.js). Los datos que necesita van en el
 * bloque JSON de abajo, que no es código y no lo toca la política de guiones.
 *
 * @var array $credencial @var string $documento @var string $qr @var array $qrMatriz
 * @var array $persona @var array $evento @var array $tema
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Tema;

guiones('carnet-imprimir.js');

$rol = (string) $persona['rol'];
// El nombre del perfil, como lo configuró el evento de esta persona.
$nombreRol = etiquetaRol($rol, (int) $persona['evento_id']);
$colores = Tema::delCarnetImpreso($tema, $rol);

$foto = (string) ($persona['foto'] ?? '') !== '' ? u('/medios/foto/' . (int) $persona['id']) : '';
$logo = ($tema['logo'] ?? '') !== '' && !empty($evento['id']) ? u('/medios/logo/' . (int) $evento['id']) : '';
$inicialEvento = mb_strtoupper(mb_substr(trim((string) $evento['nombre']), 0, 1)) ?: 'E';
$identificacion = identificacion($persona['tipo_documento'], $documento);
$entidad = (string) $persona['entidad'] !== '' ? (string) $persona['entidad'] : 'Independiente';
$pie = $identificacion !== '' ? $identificacion : (string) $credencial['codigo'];

/*
 * La talla de partida según el largo. Sin JavaScript, con esto casi todo cabe;
 * con él, carnet-imprimir.js termina de ajustar cada texto a su caja. Lo que
 * aun así no cabe se corta con «…»: nunca se sale del recuadro.
 */
$talla = static function (string $texto, int $corto, int $largo): string {
    $n = mb_strlen($texto);
    return $n <= $corto ? '' : ($n <= $largo ? ' is-largo' : ' is-muy-largo');
};

$datos = [
    'archivo'        => 'carnet-' . (preg_replace('/[^A-Za-z0-9-]/', '', (string) $credencial['codigo']) ?: 'evento'),
    'evento'         => (string) $evento['nombre'],
    'dependencia'    => (string) ($evento['dependencia'] ?? ''),
    'logo'           => $logo,
    'inicial'        => $inicialEvento,
    'foto'           => $foto,
    'iniciales'      => iniciales((string) $persona['nombre']),
    'rol'            => $nombreRol,
    'nombre'         => (string) $persona['nombre'],
    'identificacion' => $identificacion !== '' ? $identificacion : 'No registrada',
    'entidad'        => $entidad,
    'credencial'     => (string) $credencial['codigo'],
    'pie'            => $pie,
    'qr'             => array_map(
        static fn(array $fila): string => implode('', array_map(static fn($m): string => $m ? '1' : '0', $fila)),
        $qrMatriz
    ),
    'colores'        => $colores,
];
?>
<main class="main" id="contenido" style="padding:24px">
  <div class="view stack stack--4" style="max-width:860px;margin:auto">

    <div class="row row--between no-print" style="align-items:flex-start">
      <div class="stack stack--2" style="max-width:520px">
        <span class="kicker">Impresión</span>
        <h1 style="font-size:26px">Tu carnet</h1>
        <p class="help" style="margin:0">
          Así sale, a tamaño real: cada cara mide 6 × 9,5 cm. <strong>Descárgalo</strong> en dos
          imágenes —frente y reverso— para llevarlo a imprimir o guardarlo en el celular, o
          <strong>imprímelo</strong> aquí en cartulina: recorta por la línea continua y dobla por
          la punteada.
        </p>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="button" data-descargar-carnet>Descargar imágenes</button>
        <button class="btn" type="button" data-imprimir>Imprimir</button>
        <a class="btn" href="<?= e(u('/carnet')) ?>">Volver</a>
      </div>
    </div>

    <!-- Lo llena carnet-imprimir.js al descargar: las dos imágenes, con un
         enlace para cada una por si el navegador solo dejó bajar la primera. -->
    <div class="card no-print" data-imagenes-carnet hidden>
      <div class="card__head"><span>Imágenes del carnet</span><span class="muted" data-imagenes-estado role="status"></span></div>
      <div class="card__body impreso-descarga" data-imagenes-lista></div>
    </div>

    <div class="impreso" data-carnet-impreso
         style="--tinta:<?= e($colores['tinta']) ?>;--tinta-rol:<?= e($colores['rol']) ?>;--tinta-suave:<?= e($colores['suave']) ?>">
      <div class="impreso__pliego">

        <section class="impreso__cara impreso__cara--frente" aria-label="Frente del carnet">
          <div class="impreso__cabeza">
            <span class="impreso__logo<?= $logo !== '' ? ' impreso__logo--imagen' : '' ?>">
              <?php if ($logo !== ''): ?>
                <img src="<?= e($logo) ?>" alt="">
              <?php else: ?>
                <?= e($inicialEvento) ?>
              <?php endif; ?>
            </span>
            <span class="impreso__evento">
              <span class="impreso__evento-nombre" data-ajustar="5"><?= e($evento['nombre']) ?></span>
              <?php if ((string) ($evento['dependencia'] ?? '') !== ''): ?>
                <span class="impreso__evento-sub" data-ajustar="3.8"><?= e($evento['dependencia']) ?></span>
              <?php endif; ?>
            </span>
          </div>

          <div class="impreso__foto">
            <?php if ($foto !== ''): ?>
              <img src="<?= e($foto) ?>" alt="Fotografía de <?= e($persona['nombre']) ?>">
            <?php else: ?>
              <span class="impreso__iniciales"><?= e(iniciales((string) $persona['nombre'])) ?></span>
            <?php endif; ?>
          </div>

          <div class="impreso__rol">
            <span class="<?= trim($talla($nombreRol, 12, 20)) ?>" data-ajustar="5.5"><?= e($nombreRol) ?></span>
          </div>

          <div class="impreso__datos">
            <div class="impreso__dato">
              <span class="impreso__k">Nombre</span>
              <span class="impreso__v impreso__v--nombre<?= $talla((string) $persona['nombre'], 30, 44) ?>" data-ajustar="6"><?= e($persona['nombre']) ?></span>
            </div>
            <div class="impreso__dato">
              <span class="impreso__k">Identificación</span>
              <span class="impreso__v impreso__v--linea" data-ajustar="5.5"><?= e($identificacion !== '' ? $identificacion : 'No registrada') ?></span>
            </div>
            <div class="impreso__dato">
              <span class="impreso__k">Entidad</span>
              <span class="impreso__v impreso__v--entidad<?= $talla($entidad, 34, 52) ?>" data-ajustar="5.5"><?= e($entidad) ?></span>
            </div>
          </div>
        </section>

        <section class="impreso__cara impreso__cara--reverso" aria-label="Reverso del carnet">
          <div class="impreso__cabeza-reverso">
            <span class="impreso__evento-nombre" data-ajustar="5"><?= e($evento['nombre']) ?></span>
            <span class="impreso__credencial" data-ajustar="3.8">Credencial digital · <?= e($credencial['codigo']) ?></span>
          </div>

          <div class="impreso__qr"><?= $qr /* SVG generado por el servidor */ ?></div>
          <span class="impreso__qr-pie">Escanear para acceso / contacto</span>

          <div class="impreso__quien">
            <span class="impreso__quien-nombre<?= $talla((string) $persona['nombre'], 30, 44) ?>" data-ajustar="5.5"><?= e($persona['nombre']) ?></span>
            <span class="impreso__quien-entidad" data-ajustar="5"><?= e($entidad) ?></span>
          </div>

          <div class="impreso__pie" data-ajustar="5"><?= e($pie) ?></div>
        </section>

      </div>
      <p class="impreso__guia">Recorta por la línea continua y dobla por la punteada · cada cara: 6 × 9,5 cm</p>
    </div>

  </div>
</main>
<script type="application/json" id="carnet-datos"><?= json_encode($datos,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
