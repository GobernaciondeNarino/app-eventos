<?php
/**
 * Configuración → Registro: cómo es el formulario de registro de este evento.
 *
 * @var \App\Modelos\Formulario $formulario
 * @var array $edicion  campos, listas y banner tal como están guardados
 * @var array $errores  por lista, y 'banner_imagen'
 * @var array $escrito  lo enviado, para no perder lo que no se pudo guardar
 * @var array $perfilesEnUso cuántas personas tienen cada perfil
 * @var array $evento
 */
defined('EVENTOS_TIC') || exit;

use App\Modelos\Formulario;
use App\Modelos\Persona;

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
$banner = $edicion['banner'];
$estados = [
    Formulario::OBLIGATORIO => 'Obligatorio',
    Formulario::OPCIONAL    => 'Opcional',
    Formulario::OCULTO      => 'Oculto',
];

// Los campos, agrupados como aparecen en el formulario.
$porSeccion = [];
foreach (Formulario::CAMPOS as $clave => $definicion) {
    $porSeccion[$definicion['seccion']][] = $clave;
}

/** Lo que se escribió en una lista con error, o lo guardado. */
$textoDe = static function (string $clave, string $guardado) use ($escrito, $errores): string {
    if (isset($errores[$clave]) && isset($escrito[$clave]) && is_string($escrito[$clave])) {
        return $escrito[$clave];
    }
    return $guardado;
};

$pestana = 'registro';
?>
<div class="view view--wide stack stack--4">

  <?php require __DIR__ . '/../parciales/pestanas-configuracion.php'; ?>

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Configuración · <?= e((string) $evento['nombre']) ?></span>
      <h1>Formulario de registro</h1>
      <p class="help" style="max-width:70ch">
        Qué se le pide a quien se registra en este evento, qué opciones trae cada lista y si arriba
        va un banner. Se aplica al guardar. Lo que ya está registrado no cambia: una opción que
        quites sigue apareciendo en los datos de quien la eligió.
      </p>
    </div>
    <a class="btn btn--sm" href="<?= e(u('/registro')) ?>" target="_blank" rel="noopener">Ver el formulario ›</a>
  </div>

  <?php if ($errores): ?>
    <div class="notice notice--warn" role="alert">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span>
        <strong>Se guardó todo menos lo marcado abajo.</strong> Corrígelo y vuelve a guardar; lo
        demás ya está aplicado.
      </span>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e(u('/admin/configuracion/registro')) ?>" enctype="multipart/form-data"
        class="stack stack--4" novalidate>
    <?= testigo() ?>
    <input type="hidden" name="accion" value="guardar">

    <!-- ================= Banner ================= -->
    <section class="card" id="banner">
      <div class="card__head">
        <span>Banner al inicio del formulario</span>
        <label class="row" style="gap:8px;cursor:pointer;text-transform:none;letter-spacing:normal">
          <input type="checkbox" name="banner_activo" value="1" <?= $banner['activo'] ? 'checked' : '' ?>
                 style="width:18px;height:18px;accent-color:var(--c-accent)">
          <span class="help" style="margin:0">Mostrar el banner</span>
        </label>
      </div>
      <div class="card__body stack stack--4">
        <p class="help" style="margin:0">
          Una imagen ancha —1600 × 400 px se ve bien en el computador y en el celular— y, si
          quieres, un título y un texto corto. Va arriba de todo, antes del primer campo. Apagado,
          no se muestra aunque tenga imagen.
        </p>

        <?php if ($banner['imagen'] !== ''): ?>
          <div class="stack stack--2">
            <img class="config-banner__vista" src="<?= e(u('/medios/banner/' . (int) $evento['id'], ['v' => substr(md5($banner['imagen']), 0, 10)])) ?>"
                 alt="El banner que está cargado">
            <label class="row" style="gap:8px;cursor:pointer">
              <input type="checkbox" name="quitar_banner" value="1" style="width:18px;height:18px;accent-color:var(--c-accent)">
              <span class="help" style="margin:0">Quitar esta imagen</span>
            </label>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="banner_imagen">
            <?= $banner['imagen'] !== '' ? 'Cambiar la imagen' : 'Imagen' ?>
            <span class="muted" style="text-transform:none;letter-spacing:normal">(JPG, PNG o WEBP, máximo 6 MB)</span>
          </label>
          <input class="input<?= $err('banner_imagen') ? ' is-invalid' : '' ?>" type="file" id="banner_imagen"
                 name="banner_imagen" accept="image/jpeg,image/png,image/webp">
          <?php if ($err('banner_imagen')): ?><span class="error"><?= e($err('banner_imagen')) ?></span><?php endif; ?>
        </div>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="banner_titulo">Título <span class="muted" style="text-transform:none;letter-spacing:normal">(opcional)</span></label>
            <input class="input" id="banner_titulo" name="banner_titulo" maxlength="160"
                   value="<?= e($banner['titulo']) ?>" placeholder="Ej. Inscripciones abiertas">
          </div>
          <div class="field">
            <label class="label" for="banner_alt">Descripción de la imagen</label>
            <input class="input" id="banner_alt" name="banner_alt" maxlength="200"
                   value="<?= e($banner['alt']) ?>" placeholder="Lo que muestra, para quien no la ve">
          </div>
        </div>
        <div class="field">
          <label class="label" for="banner_texto">Texto <span class="muted" style="text-transform:none;letter-spacing:normal">(opcional)</span></label>
          <textarea class="textarea" id="banner_texto" name="banner_texto" rows="3" maxlength="600"
                    placeholder="Fechas, lugar, a quién va dirigido…"><?= e($banner['texto']) ?></textarea>
        </div>
      </div>
    </section>

    <!-- ================= Campos ================= -->
    <section class="card" id="campos">
      <div class="card__head"><span>Campos del formulario</span></div>
      <div class="card__body stack stack--3">
        <p class="help" style="margin:0">
          Siempre se piden <strong>el correo, el nombre completo y la autorización de tratamiento de
          datos</strong>: sin correo no hay forma de entrar, sin nombre no hay carnet, y sin
          autorización la Ley 1581 no deja guardar nada. La contraseña aparece si el acceso con
          contraseña está encendido en <a href="<?= e(u('/admin/autenticacion')) ?>">Acceso y correo</a>.
        </p>

        <?php foreach ($porSeccion as $seccion => $claves): ?>
          <div class="stack" style="gap:0">
            <span class="kicker" style="padding-top:10px"><?= e($seccion) ?></span>
            <?php foreach ($claves as $clave):
              $definicion = Formulario::CAMPOS[$clave];
              $actual = $edicion['campos'][$clave];
              $dosEstados = count($definicion['estados']) === 2;
            ?>
              <div class="config-campo">
                <div class="stack" style="gap:3px;min-width:0">
                  <strong style="font-weight:500;color:var(--c-title)" id="rotulo-<?= e($clave) ?>"><?= e($definicion['etiqueta']) ?></strong>
                  <?php if (!empty($definicion['sensible'])): ?>
                    <span class="help" style="margin:0">Dato sensible: se puede pedir, nunca exigir (Ley 1581, art. 6).</span>
                  <?php endif; ?>
                  <?php if (!empty($definicion['nota'])): ?>
                    <span class="help" style="margin:0"><?= e($definicion['nota']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="config-campo__estados" role="radiogroup" aria-labelledby="rotulo-<?= e($clave) ?>">
                  <?php foreach ($definicion['estados'] as $estado): ?>
                    <label class="chip<?= $actual === $estado ? ' is-active' : '' ?>">
                      <input type="radio" class="sr-only" name="campos[<?= e($clave) ?>]" value="<?= e($estado) ?>"
                             <?= $actual === $estado ? 'checked' : '' ?>>
                      <?= e($dosEstados && $estado === Formulario::OPCIONAL ? 'Visible' : $estados[$estado]) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ================= Listas ================= -->
    <section class="card" id="listas">
      <div class="card__head"><span>Opciones de las listas</span></div>
      <div class="card__body stack stack--3">
        <p class="help" style="margin:0">
          Ábrela para cambiarla. Las opciones de fábrica no se borran —hay registros que las tienen
          guardadas—: se apagan. Las nuevas se agregan al final.
        </p>

        <?php foreach (Formulario::LISTAS as $clave => $definicion):
          $valor = $edicion['listas'][$clave];
          $oculta = !$formulario->visible($definicion['campo']);
          $resumen = match ($definicion['clase']) {
              'codigos'    => count(array_filter($valor, static fn(array $o): bool => !empty($o['activo']) && $o['valor'] !== '')) . ' activas',
              'perfiles'   => count(array_filter($valor, static fn(array $o): bool => !empty($o['activo']))) . ' de ' . count($valor) . ' activos',
              'territorio' => count($valor) . ' departamentos · ' . array_sum(array_map('count', $valor)) . ' municipios',
              'minutos'    => implode(', ', $valor) . ' minutos',
              default      => count($valor) . ' opciones',
          };
        ?>
          <details class="config-lista" id="lista-<?= e($clave) ?>" <?= $err($clave) ? 'open' : '' ?>>
            <summary>
              <strong><?= e($definicion['etiqueta']) ?></strong>
              <span class="muted mono" style="font-size:12px"><?= e($resumen) ?></span>
              <?php if ($oculta): ?><span class="tag tag--mute">Campo oculto</span><?php endif; ?>
              <?php if ($err($clave)): ?><span class="tag tag--danger">Revisar</span><?php endif; ?>
            </summary>
            <div class="config-lista__cuerpo stack stack--3">
              <?php if ($err($clave)): ?><span class="error"><?= e($err($clave)) ?></span><?php endif; ?>

              <?php if ($definicion['clase'] === 'codigos'): ?>
                <div class="config-opciones">
                  <div class="config-opciones__fila config-opciones__cabeza">
                    <span>Activa</span><span>Se guarda como</span><span>Se muestra como</span>
                  </div>
                  <?php foreach ($valor as $i => $opcion): ?>
                    <div class="config-opciones__fila">
                      <input type="hidden" name="listas[<?= e($clave) ?>][<?= (int) $i ?>][valor]" value="<?= e($opcion['valor']) ?>">
                      <input type="checkbox" name="listas[<?= e($clave) ?>][<?= (int) $i ?>][activo]" value="1"
                             aria-label="Activa: <?= e($opcion['etiqueta']) ?>"
                             <?= !empty($opcion['activo']) ? 'checked' : '' ?> <?= $opcion['valor'] === '' ? 'disabled' : '' ?>
                             style="width:18px;height:18px;accent-color:var(--c-accent)">
                      <span class="mono" style="font-size:12px;word-break:break-word"><?= $opcion['valor'] !== '' ? e($opcion['valor']) : '<span class="muted">sin respuesta</span>' ?></span>
                      <input class="input" name="listas[<?= e($clave) ?>][<?= (int) $i ?>][etiqueta]" maxlength="120"
                             value="<?= e($opcion['etiqueta']) ?>" aria-label="Cómo se muestra «<?= e($opcion['valor']) ?>»">
                    </div>
                  <?php endforeach; ?>
                  <?php foreach (['nueva1', 'nueva2'] as $nueva): ?>
                    <div class="config-opciones__fila">
                      <input type="hidden" name="listas[<?= e($clave) ?>][<?= $nueva ?>][activo]" value="1">
                      <span class="muted" aria-hidden="true">＋</span>
                      <?php if ($clave === 'tipo_documento'): ?>
                        <input class="input input--mono" name="listas[<?= e($clave) ?>][<?= $nueva ?>][codigo]" maxlength="8"
                               placeholder="PPT" aria-label="Sigla del nuevo tipo de documento">
                      <?php else: ?>
                        <span class="muted" style="font-size:12px">igual al texto</span>
                      <?php endif; ?>
                      <input class="input" name="listas[<?= e($clave) ?>][<?= $nueva ?>][etiqueta]" maxlength="120"
                             placeholder="<?= $clave === 'tipo_documento' ? 'Permiso por Protección Temporal' : 'Nueva opción' ?>"
                             aria-label="Nueva opción">
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($clave === 'tipo_documento'): ?>
                  <span class="help" style="margin:0">
                    La cédula y la tarjeta de identidad se validan como solo números; los demás
                    tipos admiten letras. La sigla es lo que sale en el carnet.
                  </span>
                <?php endif; ?>

              <?php elseif ($definicion['clase'] === 'perfiles'): ?>
                <div class="config-opciones config-opciones--perfiles">
                  <div class="config-opciones__fila config-opciones__cabeza">
                    <span class="cp-activo">Activo</span><span class="cp-nombre">Nombre</span>
                    <span class="cp-uso">Lo tienen</span><span class="cp-borrar">Eliminar</span>
                  </div>
                  <?php foreach ($valor as $i => $opcion):
                    $clave = (string) $opcion['valor'];
                    $delSistema = in_array($clave, Persona::PERFILES_DEL_SISTEMA, true);
                    $usan = (int) ($perfilesEnUso[$clave] ?? 0);
                  ?>
                    <div class="config-opciones__fila">
                      <input type="hidden" name="listas[perfil][<?= (int) $i ?>][valor]" value="<?= e($clave) ?>">
                      <input type="checkbox" class="cp-activo" name="listas[perfil][<?= (int) $i ?>][activo]" value="1"
                             aria-label="Ofrecer «<?= e($opcion['etiqueta']) ?>» en el formulario"
                             <?= !empty($opcion['activo']) ? 'checked' : '' ?> <?= $clave === 'participante' ? 'disabled' : '' ?>
                             style="width:18px;height:18px;accent-color:var(--c-accent)">
                      <input class="input cp-nombre" name="listas[perfil][<?= (int) $i ?>][etiqueta]" maxlength="30"
                             value="<?= e($opcion['etiqueta']) ?>" aria-label="Nombre del perfil «<?= e($opcion['etiqueta']) ?>»">
                      <span class="cp-uso mono muted" style="font-size:12px"
                            aria-label="<?= $usan === 1 ? 'Lo tiene 1 persona' : 'Lo tienen ' . $usan . ' personas' ?>"><?= $usan ?></span>
                      <span class="cp-borrar">
                        <?php if ($delSistema): ?>
                          <span class="muted" style="font-size:11px" title="La plataforma lo usa: se puede renombrar, no eliminar">—</span>
                        <?php elseif ($usan > 0): ?>
                          <span class="muted" style="font-size:11px" title="Lo tiene alguien: apágalo para que no se ofrezca más">En uso</span>
                        <?php else: ?>
                          <label class="row" style="gap:6px;cursor:pointer">
                            <input type="checkbox" name="listas[perfil][<?= (int) $i ?>][eliminar]" value="1"
                                   aria-label="Eliminar el perfil «<?= e($opcion['etiqueta']) ?>»"
                                   style="width:18px;height:18px;accent-color:var(--c-danger)">
                            <span class="cp-borrar__texto muted" style="font-size:11px">Eliminar</span>
                          </label>
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                  <?php foreach (['nueva1', 'nueva2'] as $nueva): ?>
                    <div class="config-opciones__fila">
                      <span class="cp-activo muted" aria-hidden="true">＋</span>
                      <input class="input cp-nombre" name="listas[perfil][<?= $nueva ?>][etiqueta]" maxlength="30"
                             placeholder="Nuevo perfil" aria-label="Nombre de un perfil nuevo">
                    </div>
                  <?php endforeach; ?>
                </div>
                <span class="help" style="margin:0">
                  Apagado, un perfil no se ofrece en el formulario, pero quien ya lo tiene lo conserva y
                  un administrador lo puede seguir poniendo desde la ficha de la persona. Solo se elimina
                  uno que no tenga nadie. «Participante» y «Expositor» se renombran, pero no se eliminan.
                  Staff y Organizador no están aquí: los pone un administrador desde la ficha.
                </span>

              <?php elseif ($definicion['clase'] === 'territorio'): ?>
                <textarea class="textarea input--mono" name="listas[ubicacion]" rows="14" spellcheck="false"
                          aria-label="Departamentos y municipios"><?= e($textoDe('ubicacion', Formulario::territorioComoTexto($valor))) ?></textarea>
                <span class="help" style="margin:0">
                  Un departamento por línea y, debajo, sus municipios empezando con un guion:
                  «Nariño» y en la siguiente «- Pasto». Se ordenan solos en el formulario.
                </span>

              <?php elseif ($definicion['clase'] === 'minutos'): ?>
                <input class="input input--mono" name="listas[duracion]"
                       value="<?= e($textoDe('duracion', implode(', ', $valor))) ?>" aria-label="Duraciones en minutos">
                <span class="help" style="margin:0">En minutos, separadas por comas: «20, 40, 60».</span>

              <?php else: ?>
                <textarea class="textarea" name="listas[<?= e($clave) ?>]" rows="<?= max(4, min(12, count($valor) + 1)) ?>"
                          aria-label="<?= e($definicion['etiqueta']) ?>, una por línea"><?= e($textoDe($clave, implode("\n", $valor))) ?></textarea>
                <span class="help" style="margin:0">Una por línea, en el orden en que se muestran.</span>
              <?php endif; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="row row--between" style="gap:12px">
      <span class="help mono" style="margin:0">
        <?= $formulario->personalizado() ? 'Este evento tiene un formulario propio.' : 'Este evento usa el formulario de fábrica.' ?>
      </span>
      <button class="btn btn--primary btn--lg" type="submit">Guardar el formulario</button>
    </div>
  </form>

  <?php if ($formulario->personalizado()): ?>
    <form method="post" action="<?= e(u('/admin/configuracion/registro')) ?>" class="row row--end"
          data-confirmar="Los campos y las listas vuelven a ser los de fábrica. Se conservan el banner y los perfiles propios que alguien ya tenga. ¿Continuar?">
      <?= testigo() ?>
      <input type="hidden" name="accion" value="restablecer">
      <button class="btn btn--sm" type="submit">Volver al formulario de fábrica</button>
    </form>
  <?php endif; ?>

</div>
