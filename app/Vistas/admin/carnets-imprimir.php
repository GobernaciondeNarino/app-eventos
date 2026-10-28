<?php
/**
 * Todos los carnets, en una sola cara cada uno.
 *
 * El carnet individual se imprime en dos caras porque su dueño lo dobla por la
 * mitad. Para una tanda eso no sirve: imprimir cuatrocientas caras y aparearlas
 * a mano es lo que hace que nadie use la función. Aquí cada persona ocupa una
 * tarjeta con el código dentro, se recorta y ya está.
 *
 * Cada tarjeta es una cara del carnet impreso (components.css, «.impreso»):
 * 6 × 9,5 cm, medida en milímetros, con la foto y el código lado a lado.
 * carnet-imprimir.js ajusta cada texto a su caja; sin él, la talla de partida
 * depende del largo y lo que no cabe se corta con «…». Nada se sale de la
 * tarjeta, que es lo que arruina una tanda: hasta la 3.14, un nombre largo
 * empujaba el código por debajo y se imprimía encima de la tarjeta siguiente.
 *
 * El reparto por hoja lo hace el navegador con «break-inside: avoid» del CSS de
 * impresión. Paginarlo aquí obligaría a saber el tamaño del papel, que es
 * justamente lo que el servidor no sabe.
 *
 * Los carnets salen por tandas (Carnet::TANDA): con más que eso, esta pantalla
 * dice qué tanda es y lleva a la siguiente.
 *
 * @var array $tarjetas @var array $filtros @var array $consulta  filtros y orden
 * @var int $tanda @var int $tandas @var int $conCarnet @var int $porTanda
 * @var array $evento @var array $tema
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Tema;

guiones('carnet-imprimir.js');

$desde = ($tanda - 1) * $porTanda + 1;
$hasta = min($conCarnet, $tanda * $porTanda);

$colores = Tema::delCarnetImpreso($tema, '');
$logo = ($tema['logo'] ?? '') !== '' && !empty($evento['id']) ? u('/medios/logo/' . (int) $evento['id']) : '';
$inicialEvento = mb_strtoupper(mb_substr(trim((string) $evento['nombre']), 0, 1)) ?: 'E';
$dependencia = (string) ($evento['dependencia'] ?? '');

// La talla de partida según el largo, para cuando no hay JavaScript que mida.
$talla = static function (string $texto, int $corto, int $largo): string {
    $n = mb_strlen($texto);
    return $n <= $corto ? '' : ($n <= $largo ? ' is-largo' : ' is-muy-largo');
};
?>
<main class="main" id="contenido" style="padding:24px">
  <div class="view view--wide stack stack--4">

    <div class="row row--between no-print">
      <div class="stack stack--2">
        <span class="kicker">Impresión</span>
        <h1 style="font-size:26px">
          <?= e(numero(count($tarjetas))) ?> carnet<?= count($tarjetas) === 1 ? '' : 's' ?>
          <?php if ($tandas > 1): ?>
            <span class="muted" style="font-size:16px" data-tanda>· del <?= e(numero($desde)) ?> al <?= e(numero($hasta)) ?> de <?= e(numero($conCarnet)) ?></span>
          <?php endif; ?>
        </h1>
        <p class="help">
          Una cara por persona, con el código incluido. Salen a tamaño real, 6 × 9,5 cm:
          imprime en cartulina y recorta por el borde. Caben seis por hoja tamaño carta.
        </p>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="button" data-imprimir>Imprimir</button>
        <?php if ($tanda < $tandas): ?>
          <a class="btn" href="<?= e(u('/carnets/imprimir', $consulta + ['tanda' => $tanda + 1])) ?>">
            Siguiente tanda ›
          </a>
        <?php endif; ?>
        <a class="btn" href="<?= e(u('/carnets', $consulta)) ?>">Volver</a>
      </div>
    </div>

    <?php if ($tandas > 1): ?>
      <div class="notice no-print">
        <span class="notice__icon" aria-hidden="true">◆</span>
        <span>
          Tanda <?= e((string) $tanda) ?> de <?= e((string) $tandas) ?>. Se imprimen en tandas de
          <?= e(numero($porTanda)) ?> para que la página cargue rápido: cuando termines esta,
          pasa a la siguiente.
        </span>
      </div>
    <?php endif; ?>

    <?php if (!$tarjetas): ?>
      <div class="empty">No hay ningún carnet que imprimir con ese filtro</div>
    <?php else: ?>
      <div class="impreso impreso-tanda"
           style="--tinta:<?= e($colores['tinta']) ?>;--tinta-suave:<?= e($colores['suave']) ?>">
        <?php foreach ($tarjetas as $t):
          $p = $t['persona'];
          $rol = (string) $p['rol'];
          $nombreRol = etiquetaRol($rol);
          $foto = (string) ($p['foto'] ?? '') !== '' ? u('/medios/foto/' . (int) $p['id']) : '';
          $identificacion = identificacion($p['tipo_documento'], $t['documento']);
          $entidad = (string) $p['entidad'] !== '' ? (string) $p['entidad'] : 'Independiente';
        ?>
          <section class="impreso__cara impreso__cara--tanda" data-carnet-tanda data-rol="<?= e($rol) ?>"
                   style="--tinta-rol:<?= e(Tema::delCarnetImpreso($tema, $rol)['rol']) ?>"
                   aria-label="Carnet de <?= e($p['nombre']) ?>">
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
                <?php if ($dependencia !== ''): ?>
                  <span class="impreso__evento-sub" data-ajustar="3.8"><?= e($dependencia) ?></span>
                <?php endif; ?>
              </span>
            </div>

            <div class="impreso__rol">
              <span class="<?= trim($talla($nombreRol, 12, 20)) ?>" data-ajustar="5.5"><?= e($nombreRol) ?></span>
            </div>

            <div class="impreso__fila">
              <div class="impreso__foto impreso__foto--chica">
                <?php if ($foto !== ''): ?>
                  <img src="<?= e($foto) ?>" alt="">
                <?php else: ?>
                  <span class="impreso__iniciales"><?= e(iniciales((string) $p['nombre'])) ?></span>
                <?php endif; ?>
              </div>
              <div class="impreso__qr impreso__qr--chico"><?= $t['qr'] ?></div>
            </div>

            <div class="impreso__datos">
              <div class="impreso__dato">
                <span class="impreso__k">Nombre</span>
                <span class="impreso__v impreso__v--nombre<?= $talla((string) $p['nombre'], 30, 44) ?>" data-ajustar="6"><?= e($p['nombre']) ?></span>
              </div>
              <?php if ($identificacion !== ''): ?>
                <div class="impreso__dato">
                  <span class="impreso__k">Identificación</span>
                  <span class="impreso__v impreso__v--linea" data-ajustar="5.5"><?= e($identificacion) ?></span>
                </div>
              <?php endif; ?>
              <div class="impreso__dato">
                <span class="impreso__k">Entidad</span>
                <span class="impreso__v impreso__v--entidad<?= $talla($entidad, 34, 52) ?>" data-ajustar="5.5"><?= e($entidad) ?></span>
              </div>
            </div>

            <div class="impreso__pie impreso__pie--tanda">
              <span class="impreso__pie-leyenda" data-ajustar="3.5">Escanear para acceso / contacto</span>
              <span data-ajustar="4.5"><?= e($t['credencial']['codigo']) ?></span>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</main>
