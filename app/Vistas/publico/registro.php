<?php
/**
 * Formulario de registro. Abierto al público, siempre.
 *
 * Qué campos lleva y qué opciones trae cada lista lo decide Configuración →
 * Registro, evento por evento (App\Modelos\Formulario).
 *
 * La misma vista sirve al formulario privado de expositores (3.9), al que se
 * llega por un enlace que la organización envía aparte: $accion es a dónde se
 * envía cada uno, y $deExpositores cambia el encabezado, el perfil por
 * omisión y deja marcada la propuesta para quien llega nuevo.
 *
 * @var string $accion @var bool $deExpositores @var bool $tienePropuesta
 * @var array $valores @var array $errores @var array $departamentos
 * @var array $municipios @var array $categorias @var array $jornadas @var bool $yaRegistrado
 * @var bool $completo @var bool $pideClave @var int $claveMinima
 * @var bool $tieneClave @var string $foto @var array $documentos @var bool $agendada
 * @var bool $reelegir @var string $perfilFijo
 * @var \App\Modelos\Formulario $formulario @var array $actual
 */
defined('EVENTOS_TIC') || exit;

use App\Nucleo\Documento;

$f = $formulario;
/** El asterisco de los obligatorios, y el atributo que lee el aviso de registro incompleto. */
$req = static fn(string $campo): string => $f->obligatorio($campo) ? ' <span class="req">*</span>' : '';
$exige = static fn(string $campo): string => $f->obligatorio($campo) ? 'required' : '';

// Si el correo vino por la portada, se precarga. Esto va ANTES de definir $v:
// una función flecha captura las variables por valor, así que $v se quedaba con
// la copia vieja de $valores y el correo del embudo principal nunca aparecía.
if (($valores['correo'] ?? '') === '' && isset($_GET['correo'])) {
    $valores['correo'] = mb_strtolower(trim((string) $_GET['correo']));
}

$v = static fn(string $clave, string $porDefecto = ''): string => (string) ($valores[$clave] ?? $porDefecto);
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
// En el de expositores, «Voy a exponer» viene marcado para quien todavía no
// tiene una propuesta: a eso llegó por ese enlace. Si el envío volvió con
// errores, manda lo que se envió.
$hayPropuesta = $v('tema') !== '' || !empty($valores['expositor'])
    || ($deExpositores && $errores === [] && !$tienePropuesta);

// La caracterización arranca plegada: es opcional, son ocho campos, y puesta
// por delante hace que el formulario parezca el triple de largo de lo que es.
// Se abre sola si algo de dentro quedó con error, para que nadie tenga que
// buscar a ciegas por qué no se guardó, y si el evento hizo obligatorio alguno
// de sus campos: lo que se exige no puede estar escondido.
// La entidad ya no va aquí sino en los datos principales: es lo que sale en el
// carnet debajo del nombre.
$deCaracterizacion = ['rango_edad', 'ubicacion', 'genero', 'etnia', 'discapacidad'];
$caracterizacionVisible = array_filter($deCaracterizacion, static fn(string $c): bool => $f->visible($c)) !== [];
$caracterizacionExige = array_filter($deCaracterizacion, static fn(string $c): bool => $f->obligatorio($c)) !== [];
$erroresOpcionales = ['municipio', 'departamento', 'rango_edad', 'genero', 'etnia', 'discapacidad'];
$abrirOpcional = $caracterizacionExige || (bool) array_intersect($erroresOpcionales, array_keys($errores));

// Los errores que se le enseñan a la persona en el aviso de «no se guardó».
$erroresVisibles = array_diff_key($errores, ['ofrecer_acceso' => true]);
$banner = $f->banner();

guiones('foto.js', 'preregistro.js', 'claves.js', 'registro-incompleto.js');
?>
<!-- enctype: sin esto el navegador manda solo los nombres de los archivos y
     $_FILES llega vacío, así que la foto se perdía sin ningún error visible. -->
<form class="view view--narrow stack stack--4" method="post" action="<?= e(u($accion)) ?>"
      enctype="multipart/form-data" novalidate data-registro>
  <?= testigo() ?>

  <?php if ($banner !== null): ?>
    <!-- El banner lo pone el evento desde Configuración → Registro. -->
    <div class="registro-banner">
      <?php if ($banner['imagen'] !== ''): ?>
        <img class="registro-banner__imagen" src="<?= e(u($banner['imagen'], ['v' => $banner['version']])) ?>"
             alt="<?= e($banner['alt']) ?>">
      <?php endif; ?>
      <?php if (trim($banner['titulo']) !== '' || trim($banner['texto']) !== ''): ?>
        <div class="registro-banner__texto">
          <?php /* Un párrafo y no un h2: el primer encabezado de la página es su h1. */ ?>
          <?php if (trim($banner['titulo']) !== ''): ?><p class="registro-banner__titulo"><?= e($banner['titulo']) ?></p><?php endif; ?>
          <?php if (trim($banner['texto']) !== ''): ?><p><?= nl2br(e($banner['texto'])) ?></p><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="stack stack--2">
    <span class="kicker"><?= $deExpositores ? 'Expositores · Datos y propuesta' : 'Fase 01 · Datos del participante' ?></span>
    <h1><?= $yaRegistrado
        ? ($completo ? 'Mis datos' : 'Completa tu registro')
        : ($deExpositores ? 'Registro de expositores' : 'Formulario de registro') ?></h1>
    <p class="help">
      <?php if (!$yaRegistrado):
        $palabras = $f->obligatoriosEnPalabras();
        $ultima = array_pop($palabras);
      ?>
        Son obligatorios <?= e(implode(', ', $palabras) . ' y ' . $ultima) ?>.
      <?php elseif ($completo): ?>
        Puedes corregir lo que haga falta; el carnet se actualiza solo.
      <?php else: ?>
        Ya tienes acceso. <?= $f->obligatorio('documento')
            ? 'Con el nombre y la identificación te emitimos el carnet.'
            : 'Con tu nombre te emitimos el carnet.' ?>
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

  <?php if ($erroresVisibles !== []): ?>
    <!-- Sin JavaScript esto es todo el aviso; con él, además se abre la
         ventana de abajo. Lo que no se guardó no puede pasar inadvertido. -->
    <div class="notice notice--danger" role="alert">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        <strong>Tu registro no fue guardado.</strong>
        <?= e($err('general') !== '' ? $err('general') : 'Revisa los campos marcados en rojo y vuelve a enviarlo.') ?>
      </span>
    </div>
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
        <span><a href="<?= e(u('/entrar', ['destino' => $accion])) ?>">Entrar con mi código ›</a></span>
      </span>
    </div>
  <?php endif; ?>

  <!-- ================= Datos obligatorios ================= -->
  <section class="card">
    <div class="card__head"><span>Datos principales</span></div>
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

      <?php if ($f->visible('documento')):
        $tipos = $f->opciones('tipo_documento', (string) ($actual['tipo_documento'] ?? ''));
        $tipoElegido = $v('tipo_documento', (string) array_key_first($tipos));
      ?>
        <div class="grid-2" style="grid-template-columns:190px 1fr">
          <div class="field">
            <label class="label" for="tipo_documento">Tipo de documento<?= $req('documento') ?></label>
            <select class="select<?= $err('tipo_documento') ? ' is-invalid' : '' ?>" id="tipo_documento" name="tipo_documento">
              <?php foreach ($tipos as $clave => $etiqueta): ?>
                <option value="<?= e($clave) ?>" <?= $tipoElegido === (string) $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($err('tipo_documento')): ?><span class="error"><?= e($err('tipo_documento')) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="documento">Número de identificación<?= $req('documento') ?></label>
            <input class="input input--mono<?= $err('documento') ? ' is-invalid' : '' ?>" id="documento" name="documento"
                   value="<?= e($v('documento')) ?>" placeholder="Sin puntos ni comas" <?= $exige('documento') ?>
                   inputmode="<?= in_array($tipoElegido, ['CC', 'TI'], true) ? 'numeric' : 'text' ?>" data-documento>
            <?php if ($err('documento')): ?><span class="error"><?= e($err('documento')) ?></span><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($f->visible('entidad')): ?>
        <div class="field">
          <label class="label" for="entidad">Entidad u organización<?= $req('entidad') ?></label>
          <input class="input<?= $err('entidad') ? ' is-invalid' : '' ?>" id="entidad" name="entidad" value="<?= e($v('entidad')) ?>"
                 autocomplete="organization" placeholder="Ej. Alcaldía de Ipiales" maxlength="160" <?= $exige('entidad') ?>>
          <?php if ($err('entidad')): ?><span class="error"><?= e($err('entidad')) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($f->visible('telefono') || $f->visible('rol')): ?>
      <div class="grid-2">
        <?php if ($f->visible('telefono')): ?>
        <div class="field">
          <label class="label" for="telefono">Teléfono de contacto<?= $req('telefono') ?></label>
          <input class="input input--mono<?= $err('telefono') ? ' is-invalid' : '' ?>" id="telefono" name="telefono"
                 type="tel" value="<?= e($v('telefono')) ?>" autocomplete="tel" placeholder="+57 300 000 0000" <?= $exige('telefono') ?>>
          <?php if ($err('telefono')): ?><span class="error"><?= e($err('telefono')) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($f->visible('rol')): ?>
        <div class="field">
          <label class="label" for="rol">Perfil de asistencia</label>
          <?php
          // Hay perfiles que solo pone un administrador —«Staff»,
          // «Organizador»—. A quien tenga uno no se le puede enseñar una lista
          // que no lo contiene: el navegador mandaría la primera opción y, de
          // guardar sus datos, se quedaría sin su perfil. Se le dice cuál tiene
          // y no se le ofrece cambiarlo; el servidor lo conserva igual.
          //
          // $perfilFijo sale de lo guardado y no de $valores: con lo enviado,
          // a cualquiera que mandara «rol=staff» a mano se le pintaba esta
          // etiqueta como si ya lo fuera.
          $suyo = $v('rol', $deExpositores ? 'expositor' : 'participante');
          ?>
          <?php if ($perfilFijo !== ''): ?>
            <div class="row" style="gap:10px;min-height:42px;align-items:center">
              <span class="tag <?= e(claseRol($perfilFijo)) ?>"><?= e(etiquetaRol($perfilFijo)) ?></span>
            </div>
            <span class="help">
              Te lo asignó la organización del evento, así que no se cambia desde aquí.
            </span>
          <?php else: ?>
            <!-- La lista sale de los perfiles públicos que ofrece este evento,
                 que es contra la que valida el servidor: escrita a mano aquí,
                 una y otra podían separarse. -->
            <select class="select" id="rol" name="rol">
              <?php foreach ($f->perfiles((string) ($actual['rol'] ?? '')) as $rol): ?>
                <option value="<?= e($rol) ?>" <?= $suyo === $rol ? 'selected' : '' ?>><?= e(etiquetaRol($rol)) ?></option>
              <?php endforeach; ?>
            </select>
            <?php /* El error del perfil se pintaba en ninguna parte: un envío
                     con un valor que no está en la lista rebotaba sin decir por
                     qué, y eso es exactamente lo que pasa al intentar colarse
                     como «staff». Vale más decirlo que dejar el formulario mudo. */ ?>
            <?php if ($err('rol')): ?><span class="error"><?= e($err('rol')) ?></span><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

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
  <?php if ($f->visible('foto')): ?>
  <section class="card">
    <div class="card__head">
      <span>Fotografía del carnet<?= $f->obligatorio('foto') ? ' <span class="req">*</span>' : ' (opcional)' ?></span>
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
          <input class="input<?= $err('foto') ? ' is-invalid' : '' ?>" type="file" id="foto" name="foto" accept="image/*"
                 <?= $f->obligatorio('foto') && $foto === '' ? 'required' : '' ?>>
          <?php if ($err('foto')): ?><span class="error"><?= e($err('foto')) ?></span><?php endif; ?>
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

        <?php if ($foto !== '' && !$f->obligatorio('foto')): ?>
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
  <?php endif; ?>

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
                   minlength="<?= e((string) $claveMinima) ?>" maxlength="200" data-clave-nueva>
            <?php if ($err('clave')): ?><span class="error"><?= e($err('clave')) ?></span><?php endif; ?>
            <span class="help">Mínimo <?= e((string) $claveMinima) ?> caracteres.</span>
          </div>
          <div class="field">
            <label class="label" for="clave2">Repítela</label>
            <input class="input<?= $err('clave2') ? ' is-invalid' : '' ?>" type="password"
                   id="clave2" name="clave2" autocomplete="new-password" maxlength="200" data-clave-repetir>
            <?php if ($err('clave2')): ?><span class="error"><?= e($err('clave2')) ?></span><?php endif; ?>
            <!-- Lo escribe claves.js mientras se teclea. Nace vacío: sin
                 JavaScript no aparece nada y el servidor valida igual. -->
            <span class="campo-estado" data-clave-estado role="status" aria-live="polite"></span>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= Caracterización ================= -->
  <?php if ($caracterizacionVisible): ?>
  <section class="card">
    <div class="card__head">
      <span>Fase 02 · Caracterización<?= $caracterizacionExige ? '' : ' (opcional)' ?></span>
      <button class="btn btn--sm" type="button" data-plegar="bloque-opcional"
              aria-expanded="<?= $abrirOpcional ? 'true' : 'false' ?>">
        <?= $abrirOpcional ? 'Ocultar' : 'Mostrar' ?>
      </button>
    </div>
    <div class="card__body stack stack--5<?= $abrirOpcional ? '' : ' hidden' ?>" id="bloque-opcional">
      <p class="help">
        Nos permite reportar cobertura territorial y enfoque diferencial.
        <?= $caracterizacionExige
            ? 'Los marcados con * son obligatorios; el resto puedes omitirlo.'
            : 'Puedes omitirla por completo.' ?>
      </p>

      <div class="grid-2">
        <?php foreach ([
          'genero'       => ['Género', ''],
          'discapacidad' => ['¿Tiene alguna discapacidad?', 'No'],
          'etnia'        => ['Grupo étnico', 'Ninguno'],
        ] as $campo => [$rotulo, $porDefecto]):
          if (!$f->visible($campo)) {
              continue;
          }
          $opciones = $f->opciones($campo, (string) ($actual[$campo] ?? ''));
          $elegido = $v($campo, array_key_exists($porDefecto, $opciones) ? $porDefecto : (string) array_key_first($opciones));
        ?>
          <div class="field">
            <label class="label" for="<?= e($campo) ?>"><?= e($rotulo) ?></label>
            <select class="select<?= $err($campo) ? ' is-invalid' : '' ?>" id="<?= e($campo) ?>" name="<?= e($campo) ?>">
              <?php foreach ($opciones as $clave => $etiqueta): ?>
                <option value="<?= e((string) $clave) ?>" <?= $elegido === (string) $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($err($campo)): ?><span class="error"><?= e($err($campo)) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($f->visible('rango_edad')): ?>
        <div class="field">
          <span class="label" id="rotulo-edad">Rango de edad<?= $req('rango_edad') ?></span>
          <div class="row" role="radiogroup" aria-labelledby="rotulo-edad">
            <?php foreach ($f->texto('rango_edad', (string) ($actual['rango_edad'] ?? '')) as $rango): ?>
              <label class="chip<?= $v('rango_edad') === $rango ? ' is-active' : '' ?>">
                <input type="radio" name="rango_edad" value="<?= e($rango) ?>" class="sr-only"
                       <?= $v('rango_edad') === $rango ? 'checked' : '' ?> <?= $exige('rango_edad') ?>>
                <?= e($rango) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if ($err('rango_edad')): ?><span class="error"><?= e($err('rango_edad')) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($f->visible('ubicacion')): ?>
      <div class="grid-2" style="align-items:end">
        <div class="field">
          <label class="label" for="departamento">Departamento<?= $req('ubicacion') ?></label>
          <select class="select<?= $err('departamento') ? ' is-invalid' : '' ?>" id="departamento" name="departamento"
                  data-municipios="<?= e(u($deExpositores ? '/municipios/expositores/' : '/municipios/')) ?>" <?= $exige('ubicacion') ?>>
            <option value="">Selecciona…</option>
            <?php foreach ($departamentos as $d): ?>
              <option value="<?= e($d) ?>" <?= $v('departamento') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('departamento')): ?><span class="error"><?= e($err('departamento')) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <div class="row" style="min-height:15px;gap:9px">
            <label class="label" for="municipio">Municipio<?= $req('ubicacion') ?></label>
            <span class="kicker hidden" id="mun-cargando" style="font-size:10px"><span class="pulse"></span> consultando…</span>
          </div>
          <select class="select<?= $err('municipio') ? ' is-invalid' : '' ?>" id="municipio" name="municipio"
                  <?= $municipios ? '' : 'disabled' ?> <?= $exige('ubicacion') ?>>
            <option value=""><?= $municipios ? 'Selecciona…' : 'Elige primero el departamento' ?></option>
            <?php foreach ($municipios as $m): ?>
              <option value="<?= e($m) ?>" <?= $v('municipio') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('municipio')): ?><span class="error"><?= e($err('municipio')) ?></span><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= Perfil expositor ================= -->
  <?php if ($f->visible('expositor')): ?>
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
                   value="<?= e($v('tema')) ?>" maxlength="200" placeholder="Ej. Datos abiertos para decidir mejor"
                   minlength="5" data-exige-expositor>
            <?php if ($err('tema')): ?><span class="error"><?= e($err('tema')) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="categoria">Categoría <span class="req">*</span></label>
            <select class="select<?= $err('categoria') ? ' is-invalid' : '' ?>" id="categoria" name="categoria" data-exige-expositor>
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
                    rows="4" maxlength="600" minlength="30" data-exige-expositor data-contador="contador-detalle"
                    placeholder="Resumen que verán los asistentes en la agenda (máx. 600 caracteres)"><?= e($v('detalle')) ?></textarea>
          <div class="row row--between">
            <?php if ($err('detalle')): ?><span class="error"><?= e($err('detalle')) ?></span><?php else: ?><span></span><?php endif; ?>
            <span class="help mono" id="contador-detalle"><?= e((string) mb_strlen($v('detalle'))) ?> / 600</span>
          </div>
        </div>

        <?php if ($f->visible('dia_preferido') || $f->visible('duracion') || $f->visible('requerimientos')):
          $duraciones = $f->duraciones((int) ($actual['duracion'] ?? 0));
          $duracionElegida = (int) $v('duracion', in_array(40, $duraciones, true) ? '40' : (string) ($duraciones[0] ?? 40));
        ?>
        <div class="grid-3">
          <?php if ($f->visible('dia_preferido')): ?>
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
          <?php endif; ?>
          <?php if ($f->visible('duracion')): ?>
          <div class="field">
            <label class="label" for="duracion">Duración</label>
            <select class="select<?= $err('duracion') ? ' is-invalid' : '' ?>" id="duracion" name="duracion">
              <?php foreach ($duraciones as $min): ?>
                <option value="<?= $min ?>" <?= $duracionElegida === $min ? 'selected' : '' ?>>
                  <?= e($min === 60 ? '1 hora' : ($min % 60 === 0 ? ($min / 60) . ' horas' : $min . ' minutos')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($err('duracion')): ?><span class="error"><?= e($err('duracion')) ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($f->visible('requerimientos')): ?>
          <div class="field">
            <label class="label" for="requerimientos">Requerimientos<?= $req('requerimientos') ?></label>
            <input class="input<?= $err('requerimientos') ? ' is-invalid' : '' ?>" id="requerimientos" name="requerimientos"
                   value="<?= e($v('requerimientos')) ?>" maxlength="255" placeholder="HDMI, internet…"
                   <?= $f->obligatorio('requerimientos') ? 'data-exige-expositor' : '' ?>>
            <?php if ($err('requerimientos')): ?><span class="error"><?= e($err('requerimientos')) ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ---------- Los dos adjuntos ----------
             Van al final del bloque a propósito: son lo más pesado de
             diligenciar y lo único que puede obligar a buscar un archivo en
             otro lado.

             Son obligatorios para exponer, y la salida de quien no los tiene a
             mano no es dejarlos en blanco: es registrarse sin marcar «voy a
             exponer» y volver aquí después. Eso se dice arriba, antes de los
             campos, y no en el error.

             Son campos de archivo normales: sin JavaScript funcionan igual. -->
        <div class="stack stack--3" style="border-top:1px solid var(--hair-soft);padding-top:18px">
          <p class="help" style="margin:0">
            <strong>Documentos de respaldo.</strong> Los dos son obligatorios para exponer: son
            con lo que la Secretaría evalúa la propuesta. Si no los tienes a mano ahora,
            regístrate sin marcar «voy a exponer» y vuelve a este formulario cuando los tengas.
          </p>

          <?php if ($reelegir): ?>
            <!-- El navegador vacía los campos de archivo al repintar el
                 formulario, y no hay forma de evitarlo desde el servidor. Lo
                 que sí se puede es no dejar que alguien corrija otro campo,
                 guarde, y descubra después que sus PDF no llegaron. -->
            <div class="notice notice--warn">
              <span class="notice__icon" aria-hidden="true">▲</span>
              <span>
                Vuelve a elegir los archivos: el navegador los descarta cada vez que el
                formulario se repinta con un error, así que no llegaron al servidor.
              </span>
            </div>
          <?php endif; ?>

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
                <?= e($regla['etiqueta']) ?> <span class="req">*</span>
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

              <!-- required solo si todavía no hay archivo: a quien ya lo subió
                   el navegador no puede pedirle que lo vuelva a elegir para
                   cambiar un teléfono. -->
              <input class="input<?= $err($clase) ? ' is-invalid' : '' ?>" type="file"
                     id="<?= e($clase) ?>" name="<?= e($clase) ?>"
                     accept="<?= e(Documento::aceptados($clase)) ?>"
                     <?= $ya === null ? 'data-exige-expositor' : '' ?>>
              <?php if ($err($clase)): ?><span class="error"><?= e($err($clase)) ?></span><?php endif; ?>
              <span class="help">
                <?= e($ayudas[$clase]) ?>
                <?= $ya !== null ? ' Si eliges otro archivo, reemplaza al anterior.' : '' ?>
              </span>
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
  <?php endif; ?>

  <!-- ================= Autorización ================= -->
  <?php if (!$yaRegistrado): ?>
    <section class="card">
      <div class="card__head"><span>Tratamiento de datos</span></div>
      <div class="card__body stack stack--3">
        <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer">
          <input type="checkbox" id="habeas" name="habeas" value="1" required
                 style="margin-top:3px;width:18px;height:18px;accent-color:var(--c-accent)"
                 data-rotulo="La autorización de tratamiento de datos">
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

<!-- ================= Registro sin guardar =================
     La ventana que se abre cuando faltan datos: antes de enviar, si el
     navegador ya ve que falta algo obligatorio (registro-incompleto.js), y
     al volver del servidor, si el envío no se guardó. Con la lista de lo que
     falta, y un botón que lleva al primer campo. -->
<div class="modal hidden" id="modal-registro-incompleto" hidden
     <?= $erroresVisibles !== [] ? 'data-abrir-al-cargar' : '' ?>>
  <div class="modal__panel" role="alertdialog" aria-modal="true"
       aria-labelledby="titulo-incompleto" aria-describedby="texto-incompleto">
    <div class="modal__head">
      <span>Registro sin guardar</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body">
      <h2 id="titulo-incompleto" style="font-size:22px;margin:0">Tu registro no fue guardado</h2>
      <p id="texto-incompleto" style="margin:0">
        Por favor registra los datos completos. Falta o hay que corregir:
      </p>
      <ul class="faltantes" data-faltantes>
        <?php foreach ($erroresVisibles as $mensaje): ?>
          <li><?= e((string) $mensaje) ?></li>
        <?php endforeach; ?>
      </ul>
      <button class="btn btn--primary btn--block" type="button" data-cerrar-modal data-ir-al-primero>
        Completar los datos
      </button>
    </div>
  </div>
</div>
