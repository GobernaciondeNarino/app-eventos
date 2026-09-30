<?php
/**
 * Formulario de registro. Abierto al público, siempre.
 *
 * @var array $valores @var array $errores @var array $departamentos
 * @var array $municipios @var array $categorias @var array $jornadas @var bool $yaRegistrado
 * @var bool $completo @var bool $pideClave @var int $claveMinima
 * @var bool $tieneClave @var string $foto @var array $documentos @var bool $agendada
 */
defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Nucleo\Documento;

// Si el correo vino por la portada, se precarga. Esto va ANTES de definir $v:
// una función flecha captura las variables por valor, así que $v se quedaba con
// la copia vieja de $valores y el correo del embudo principal nunca aparecía.
if (($valores['correo'] ?? '') === '' && isset($_GET['correo'])) {
    $valores['correo'] = mb_strtolower(trim((string) $_GET['correo']));
}

$v = static fn(string $clave, string $porDefecto = ''): string => (string) ($valores[$clave] ?? $porDefecto);
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
$hayPropuesta = $v('tema') !== '' || !empty($valores['expositor']);

// La caracterización arranca plegada: es opcional, son ocho campos, y puesta
// por delante hace que el formulario parezca el triple de largo de lo que es.
// Se abre sola si algo de dentro quedó con error, para que nadie tenga que
// buscar a ciegas por qué no se guardó.
$erroresOpcionales = ['municipio', 'departamento', 'rango_edad', 'genero', 'etnia', 'discapacidad'];
$abrirOpcional = (bool) array_intersect($erroresOpcionales, array_keys($errores));

guiones('foto.js', 'preregistro.js');
?>
<!-- enctype: sin esto el navegador manda solo los nombres de los archivos y
     $_FILES llega vacío, así que la foto se perdía sin ningún error visible. -->
<form class="view view--narrow stack stack--4" method="post" action="<?= e(u('/registro')) ?>"
      enctype="multipart/form-data" novalidate>
  <?= testigo() ?>

  <div class="stack stack--2">
    <span class="kicker">Fase 01 · Datos del participante</span>
    <h1><?= $yaRegistrado ? ($completo ? 'Mis datos' : 'Completa tu registro') : 'Formulario de registro' ?></h1>
    <p class="help">
      <?php if (!$yaRegistrado): ?>
        Solo nombre e identificación son obligatorios.
      <?php elseif ($completo): ?>
        Puedes corregir lo que haga falta; el carnet se actualiza solo.
      <?php else: ?>
        Ya tienes acceso. Con el nombre y la identificación te emitimos el carnet.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($yaRegistrado && !$completo): ?>
    <div class="notice">
      <span class="notice__icon" aria-hidden="true">◆</span>
      <span>
        Tu acceso ya está creado: puedes volver cuando quieras con tu correo y tu contraseña.
        El carnet con el código QR se emite en cuanto guardes estos datos.
      </span>
    </div>
  <?php endif; ?>

  <?php if ($err('general')): ?>
    <div class="notice notice--danger"><span class="notice__icon">▲</span><span><?= e($err('general')) ?></span></div>
  <?php endif; ?>

  <?php if ($err('ofrecer_acceso')): ?>
    <div class="notice notice--warn">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:6px">
        <span><?= e($err('correo')) ?></span>
        <span class="help">
          Te enviaremos un código de seis dígitos a ese buzón. Es la forma de comprobar que la
          cuenta es tuya antes de dejar cambiar nada.
        </span>
        <span><a href="<?= e(u('/entrar', ['destino' => '/registro'])) ?>">Entrar con mi código ›</a></span>
      </span>
    </div>
  <?php endif; ?>

  <!-- ================= Datos obligatorios ================= -->
  <section class="card">
    <div class="card__head"><span>Datos obligatorios</span></div>
    <div class="card__body stack stack--4">

      <div class="field">
        <label class="label" for="correo">Correo electrónico <span class="req">*</span></label>
        <input class="input<?= $err('correo') ? ' is-invalid' : '' ?>" type="email" id="correo" name="correo"
               value="<?= e($v('correo')) ?>" placeholder="nombre@entidad.gov.co"
               autocomplete="email" inputmode="email" <?= $yaRegistrado ? 'readonly' : 'required' ?>>
        <?php if ($err('correo')): ?><span class="error"><?= e($err('correo')) ?></span><?php endif; ?>
        <?php if ($yaRegistrado): ?><span class="help">El correo no se puede cambiar: es tu forma de entrar.</span><?php endif; ?>
      </div>

      <div class="field">
        <label class="label" for="nombre">Nombre completo <span class="req">*</span></label>
        <input class="input<?= $err('nombre') ? ' is-invalid' : '' ?>" id="nombre" name="nombre"
               value="<?= e($v('nombre')) ?>" autocomplete="name"
               placeholder="Ej. María Fernanda Zambrano" maxlength="160" required>
        <?php if ($err('nombre')): ?><span class="error"><?= e($err('nombre')) ?></span><?php endif; ?>
      </div>

      <div class="grid-2" style="grid-template-columns:190px 1fr">
        <div class="field">
          <label class="label" for="tipo_documento">Tipo de documento <span class="req">*</span></label>
          <select class="select" id="tipo_documento" name="tipo_documento">
            <?php foreach (Datos::TIPOS_DOCUMENTO as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('tipo_documento', 'CC') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="documento">Número de identificación <span class="req">*</span></label>
          <input class="input input--mono<?= $err('documento') ? ' is-invalid' : '' ?>" id="documento" name="documento"
                 value="<?= e($v('documento')) ?>" inputmode="numeric" placeholder="Sin puntos ni comas" required>
          <?php if ($err('documento')): ?><span class="error"><?= e($err('documento')) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label class="label" for="telefono">Teléfono de contacto</label>
          <input class="input input--mono<?= $err('telefono') ? ' is-invalid' : '' ?>" id="telefono" name="telefono"
                 type="tel" value="<?= e($v('telefono')) ?>" autocomplete="tel" placeholder="+57 300 000 0000">
          <?php if ($err('telefono')): ?><span class="error"><?= e($err('telefono')) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label class="label" for="rol">Perfil de asistencia</label>
          <select class="select" id="rol" name="rol">
            <?php foreach (['participante', 'visitante', 'expositor', 'prensa'] as $rol): ?>
              <option value="<?= e($rol) ?>" <?= $v('rol', 'participante') === $rol ? 'selected' : '' ?>><?= e(etiquetaRol($rol)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

    </div>
  </section>

  <!-- ================= Fotografía del carnet =================
       El campo va SIN el atributo capture a propósito. Con capture, el celular
       abre la cámara y ya: no hay forma de elegir una foto que ya se tiene. Sin
       él, tanto Android como iPhone muestran su propio menú con «Cámara»,
       «Fotos» y «Archivos», que es justamente poder escoger.

       El recorte lo ajusta la persona en el editor de abajo, que solo aparece
       si hay JavaScript. Si no lo hay, el campo funciona igual y el servidor
       recorta el centro. -->
  <section class="card">
    <div class="card__head">
      <span>Fotografía del carnet (opcional)</span>
      <?php if ($foto !== ''): ?><span class="tag tag--ok">Ya tienes una</span><?php endif; ?>
    </div>
    <div class="card__body foto-campo" data-foto>

      <div class="stack stack--3" style="align-items:center">
        <!-- El visor. Con una foto cargada se convierte en el editor: se
             arrastra para centrar y se acerca con la barra o con dos dedos. -->
        <div class="foto-campo__vista" data-foto-visor>
          <?php if ($foto !== ''): ?>
            <img src="<?= e($foto) ?>" alt="Tu fotografía actual" data-foto-actual>
          <?php else: ?>
            <span class="foto-campo__vacia" data-foto-vacia aria-hidden="true">Sin foto</span>
          <?php endif; ?>
          <canvas class="foto-campo__lienzo" data-foto-lienzo hidden></canvas>
          <span class="foto-campo__marco" data-foto-marco hidden aria-hidden="true"></span>
        </div>

        <div class="foto-campo__mandos hidden" data-foto-mandos>
          <div class="row" style="flex-wrap:nowrap;gap:10px;width:100%">
            <span class="muted" aria-hidden="true">−</span>
            <label class="sr-only" for="foto-zoom">Acercar o alejar la foto</label>
            <input type="range" id="foto-zoom" data-foto-zoom
                   min="100" max="400" value="100" step="1" style="flex:1">
            <span class="muted" aria-hidden="true">+</span>
          </div>
          <button class="btn btn--sm" type="button" data-foto-centrar>Volver a centrar</button>
        </div>
      </div>

      <div class="stack stack--3">
        <p class="help" style="margin:0">
          Aparecerá en tu carnet digital y en el impreso. Puedes <strong>tomarla en el
          momento con la cámara</strong> o <strong>elegir una de tu galería</strong>; después
          arrástrala para centrar la cara y usa la barra para acercarla.
        </p>

        <div class="row">
          <button class="btn btn--sm btn--primary" type="button" data-foto-elegir>
            Tomar foto o elegir de la galería
          </button>
          <button class="btn btn--sm hidden" type="button" data-foto-descartar>
            Descartar
          </button>
        </div>

        <!-- El campo real. Va oculto para el ojo pero sigue siendo un campo de
             archivo normal: sin JavaScript se muestra y funciona solo. -->
        <div class="field" data-foto-campo style="margin:0">
          <label class="label" for="foto">Foto (JPG, PNG o WEBP, máximo 6 MB)</label>
          <input class="input" type="file" id="foto" name="foto" accept="image/*">
        </div>

        <p class="help hidden" data-foto-error style="margin:0;color:var(--c-danger)"></p>

        <!-- El encuadre elegido, en las medidas con las que el navegador vio la
             imagen. El servidor lo reescala a las suyas y lo encaja dentro de
             la foto: nunca se confía en estos números. -->
        <input type="hidden" name="foto_x" data-foto-x>
        <input type="hidden" name="foto_y" data-foto-y>
        <input type="hidden" name="foto_lado" data-foto-lado>
        <input type="hidden" name="foto_ancho" data-foto-ancho>
        <input type="hidden" name="foto_alto" data-foto-alto>

        <?php if ($foto !== ''): ?>
          <label class="row" style="gap:10px;cursor:pointer;flex-wrap:nowrap;align-items:flex-start">
            <input type="checkbox" name="quitar_foto" value="1"
                   style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--c-accent)">
            <span class="help">Quitar la foto actual y dejar el carnet con mis iniciales</span>
          </label>
        <?php endif; ?>

        <p class="help" style="margin:0">
          La foto solo la ves tú y el equipo organizador. No viaja en ningún código QR.
        </p>
      </div>
    </div>
  </section>

  <!-- ================= Contraseña ================= -->
  <?php if ($pideClave): ?>
    <section class="card">
      <div class="card__head">
        <span>Contraseña de acceso</span>
        <?php if ($tieneClave): ?><span class="tag tag--ok">Ya tienes una</span><?php endif; ?>
      </div>
      <div class="card__body stack stack--4">
        <p class="help">
          <?= $tieneClave
            ? 'Déjalo en blanco si no quieres cambiarla.'
            : 'Con ella entras a la plataforma escribiendo tu correo y esta contraseña, '
              . 'sin esperar ningún código.' ?>
        </p>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="clave">
              <?= $tieneClave ? 'Nueva contraseña' : 'Contraseña' ?>
            </label>
            <input class="input<?= $err('clave') ? ' is-invalid' : '' ?>" type="password"
                   id="clave" name="clave" autocomplete="new-password"
                   minlength="<?= e((string) $claveMinima) ?>" maxlength="200">
            <?php if ($err('clave')): ?><span class="error"><?= e($err('clave')) ?></span><?php endif; ?>
            <span class="help">Mínimo <?= e((string) $claveMinima) ?> caracteres.</span>
          </div>
          <div class="field">
            <label class="label" for="clave2">Repítela</label>
            <input class="input<?= $err('clave2') ? ' is-invalid' : '' ?>" type="password"
                   id="clave2" name="clave2" autocomplete="new-password" maxlength="200">
            <?php if ($err('clave2')): ?><span class="error"><?= e($err('clave2')) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= Caracterización ================= -->
  <section class="card">
    <div class="card__head">
      <span>Fase 02 · Caracterización (opcional)</span>
      <button class="btn btn--sm" type="button" data-plegar="bloque-opcional"
              aria-expanded="<?= $abrirOpcional ? 'true' : 'false' ?>">
        <?= $abrirOpcional ? 'Ocultar' : 'Mostrar' ?>
      </button>
    </div>
    <div class="card__body stack stack--5<?= $abrirOpcional ? '' : ' hidden' ?>" id="bloque-opcional">
      <p class="help">Nos permite reportar cobertura territorial y enfoque diferencial. Puedes omitirla por completo.</p>

      <div class="grid-2">
        <div class="field">
          <label class="label" for="genero">Género</label>
          <select class="select" id="genero" name="genero">
            <?php foreach (Datos::GENEROS as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('genero') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="discapacidad">¿Tiene alguna discapacidad?</label>
          <select class="select" id="discapacidad" name="discapacidad">
            <?php foreach (Datos::DISCAPACIDADES as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('discapacidad', 'No') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="etnia">Grupo étnico</label>
          <select class="select" id="etnia" name="etnia">
            <?php foreach (Datos::ETNIAS as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('etnia', 'Ninguno') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="entidad">Entidad u organización</label>
          <input class="input" id="entidad" name="entidad" value="<?= e($v('entidad')) ?>"
                 autocomplete="organization" placeholder="Ej. Alcaldía de Ipiales" maxlength="160">
        </div>
      </div>

      <div class="field">
        <span class="label" id="rotulo-edad">Rango de edad</span>
        <div class="row" role="group" aria-labelledby="rotulo-edad">
          <?php foreach (Datos::RANGOS_EDAD as $rango): ?>
            <label class="chip<?= $v('rango_edad') === $rango ? ' is-active' : '' ?>">
              <input type="radio" name="rango_edad" value="<?= e($rango) ?>" class="sr-only"
                     <?= $v('rango_edad') === $rango ? 'checked' : '' ?>>
              <?= e($rango) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid-2" style="align-items:end">
        <div class="field">
          <label class="label" for="departamento">Departamento</label>
          <select class="select" id="departamento" name="departamento"
                  data-municipios="<?= e(u('/municipios/')) ?>">
            <option value="">Selecciona…</option>
            <?php foreach ($departamentos as $d): ?>
              <option value="<?= e($d) ?>" <?= $v('departamento') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <div class="row" style="min-height:15px;gap:9px">
            <label class="label" for="municipio">Municipio</label>
            <span class="kicker hidden" id="mun-cargando" style="font-size:10px"><span class="pulse"></span> consultando…</span>
          </div>
          <select class="select<?= $err('municipio') ? ' is-invalid' : '' ?>" id="municipio" name="municipio"
                  <?= $municipios ? '' : 'disabled' ?>>
            <option value=""><?= $municipios ? 'Selecciona…' : 'Elige primero el departamento' ?></option>
            <?php foreach ($municipios as $m): ?>
              <option value="<?= e($m) ?>" <?= $v('municipio') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('municipio')): ?><span class="error"><?= e($err('municipio')) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Perfil expositor ================= -->
  <section class="card">
    <div class="card__head">
      <span>Fase 03 · Perfil expositor</span>
      <label class="row" style="gap:8px;cursor:pointer;text-transform:none;letter-spacing:normal">
        <input type="checkbox" name="expositor" id="expositor" value="1"
               style="width:18px;height:18px;accent-color:var(--c-accent)"
               data-mostrar="bloque-expositor" <?= $hayPropuesta ? 'checked' : '' ?>>
        <span class="help">Voy a exponer</span>
      </label>
    </div>
    <div class="card__body stack stack--4">
      <p class="help">Actívalo si vas a presentar una charla, stand o demostración. La Secretaría revisa cada propuesta y confirma horario y espacio.</p>

      <?php if ($agendada): ?>
        <!-- Con la propuesta ya aprobada, el tema y el día están publicados en
             la agenda y el formulario deja de poder cambiarlos. Decirlo importa:
             si no, se edita el detalle, se guarda, y no pasa nada visible. -->
        <div class="notice">
          <span class="notice__icon" aria-hidden="true">◆</span>
          <span>
            Tu propuesta ya está aprobada y publicada en la agenda, así que el tema, la
            categoría y el día ya no se cambian desde aquí: escríbele a la Secretaría si
            necesitas mover algo. Los <strong>documentos sí</strong> puedes reemplazarlos
            cuando quieras.
          </span>
        </div>
      <?php endif; ?>

      <div class="stack stack--4<?= $hayPropuesta ? '' : ' hidden' ?>" id="bloque-expositor">
        <div class="grid-2" style="grid-template-columns:1.4fr 1fr">
          <div class="field">
            <label class="label" for="tema">Tema de la exposición <span class="req">*</span></label>
            <input class="input<?= $err('tema') ? ' is-invalid' : '' ?>" id="tema" name="tema"
                   value="<?= e($v('tema')) ?>" maxlength="200" placeholder="Ej. Datos abiertos para decidir mejor">
            <?php if ($err('tema')): ?><span class="error"><?= e($err('tema')) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="categoria">Categoría <span class="req">*</span></label>
            <select class="select<?= $err('categoria') ? ' is-invalid' : '' ?>" id="categoria" name="categoria">
              <option value="">Selecciona…</option>
              <?php foreach ($categorias as $c): ?>
                <option value="<?= e($c) ?>" <?= $v('categoria') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($err('categoria')): ?><span class="error"><?= e($err('categoria')) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field">
          <label class="label" for="detalle">Detalle de lo que vas a exponer <span class="req">*</span></label>
          <textarea class="textarea<?= $err('detalle') ? ' is-invalid' : '' ?>" id="detalle" name="detalle"
                    rows="4" maxlength="600" data-contador="contador-detalle"
                    placeholder="Resumen que verán los asistentes en la agenda (máx. 600 caracteres)"><?= e($v('detalle')) ?></textarea>
          <div class="row row--between">
            <?php if ($err('detalle')): ?><span class="error"><?= e($err('detalle')) ?></span><?php else: ?><span></span><?php endif; ?>
            <span class="help mono" id="contador-detalle"><?= e((string) mb_strlen($v('detalle'))) ?> / 600</span>
          </div>
        </div>

        <div class="grid-3">
          <div class="field">
            <label class="label" for="dia_preferido">Día preferido</label>
            <select class="select" id="dia_preferido" name="dia_preferido">
              <?php foreach ($jornadas as $j): ?>
                <option value="<?= e((string) $j['numero']) ?>" <?= (int) $v('dia_preferido', '1') === (int) $j['numero'] ? 'selected' : '' ?>>
                  Día <?= e((string) $j['numero']) ?> — <?= e(fecha((string) $j['fecha'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="duracion">Duración</label>
            <select class="select" id="duracion" name="duracion">
              <?php foreach ([20 => '20 minutos', 40 => '40 minutos', 60 => '1 hora'] as $min => $etiqueta): ?>
                <option value="<?= $min ?>" <?= (int) $v('duracion', '40') === $min ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="requerimientos">Requerimientos</label>
            <input class="input" id="requerimientos" name="requerimientos"
                   value="<?= e($v('requerimientos')) ?>" maxlength="255" placeholder="HDMI, internet…">
          </div>
        </div>

        <!-- ---------- Los dos adjuntos ----------
             Van al final del bloque a propósito: son lo más pesado de
             diligenciar y lo único que puede obligar a buscar un archivo en
             otro lado. Quien llena esto desde el celular en la fila de la
             puerta puede dejarlos para después sin que se le trabe el registro.

             Son campos de archivo normales: sin JavaScript funcionan igual. -->
        <div class="stack stack--3" style="border-top:1px solid var(--hair-soft);padding-top:18px">
          <p class="help" style="margin:0">
            <strong>Documentos de respaldo.</strong> Son opcionales para registrarte, pero la
            Secretaría los usa para evaluar la propuesta: sin ellos la revisión se demora.
            Puedes volver a este formulario y subirlos cuando los tengas.
          </p>

          <?php
          $ayudas = [
            'hoja_vida'  => 'Tu perfil profesional. Es lo que sustenta que eres quien puede dar esta charla.',
            'exposicion' => 'La presentación que vas a proyectar. Puedes subir un borrador y reemplazarlo después.',
          ];
          foreach (Documento::CLASES as $clase => $regla):
            $ya = $documentos[$clase] ?? null;
            $formatos = Documento::formatosLegibles($clase);
          ?>
            <div class="field">
              <label class="label" for="<?= e($clase) ?>">
                <?= e($regla['etiqueta']) ?>
                <span class="muted" style="text-transform:none;letter-spacing:normal">
                  (<?= e($formatos) ?>, máximo <?= e(Documento::pesoLegible($clase)) ?>)
                </span>
              </label>

              <?php if ($ya !== null): ?>
                <div class="row" style="gap:10px;margin-bottom:8px">
                  <span class="tag tag--ok">Ya la subiste</span>
                  <a href="<?= e($ya['url']) ?>">Descargar<?= $ya['peso'] !== '' ? ' (' . e($ya['peso']) . ')' : '' ?></a>
                </div>
              <?php endif; ?>

              <input class="input" type="file" id="<?= e($clase) ?>" name="<?= e($clase) ?>"
                     accept="<?= e(Documento::aceptados($clase)) ?>">
              <span class="help">
                <?= e($ayudas[$clase]) ?>
                <?= $ya !== null ? ' Si eliges otro archivo, reemplaza al anterior.' : '' ?>
              </span>

              <?php if ($ya !== null): ?>
                <label class="row" style="gap:10px;cursor:pointer;flex-wrap:nowrap;align-items:flex-start;margin-top:8px">
                  <input type="checkbox" name="quitar_<?= e($clase) ?>" value="1"
                         style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--c-accent)">
                  <span class="help">Quitar el archivo que tengo subido</span>
                </label>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <p class="help" style="margin:0">
            Los dos archivos los ve solo el equipo que revisa las propuestas. No se publican
            en la agenda ni se comparten con los demás asistentes.
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Autorización ================= -->
  <?php if (!$yaRegistrado): ?>
    <section class="card">
      <div class="card__head"><span>Tratamiento de datos</span></div>
      <div class="card__body stack stack--3">
        <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer">
          <input type="checkbox" id="habeas" name="habeas" value="1" style="margin-top:3px;width:18px;height:18px;accent-color:var(--c-accent)">
          <span class="help" style="flex:1">
            Autorizo el tratamiento de mis datos personales por parte de la Gobernación de
            Nariño para la gestión de este evento, conforme a la Ley 1581 de 2012 y a la
            política de tratamiento de datos de la entidad. <span class="req">*</span>
          </span>
        </label>
        <?php if ($err('habeas')): ?><span class="error"><?= e($err('habeas')) ?></span><?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row row--between" style="padding-top:4px">
    <span class="help mono">Los campos con <span class="req">*</span> son obligatorios.</span>
    <div class="row">
      <a class="btn" href="<?= e(u($yaRegistrado ? '/carnet' : '/')) ?>">Cancelar</a>
      <button class="btn btn--primary" type="submit"><?= $yaRegistrado ? 'Guardar cambios' : 'Completar registro' ?></button>
    </div>
  </div>
</form>
