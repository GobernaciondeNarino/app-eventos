<?php
/**
 * Ficha de una persona registrada.
 *
 * Se pinta en dos sitios con el mismo archivo: como pantalla propia en
 * /admin/registros/{id}, y como contenido del diálogo que abre el botón de
 * perfil de la tabla. Por eso no trae ningún armazón: solo el bloque.
 *
 * @var array $persona @var string $documento @var array|null $credencial
 * @var array $historial @var int $contactos @var array $caracterizacion
 * @var bool $esAdministrador @var bool $tieneClave @var bool $qrActivo
 * @var string $claveNueva @var string $urlAcceso
 */
defined('EVENTOS_TIC') || exit;

$id = (int) $persona['id'];
$foto = (string) ($persona['foto'] ?? '') !== '' ? u('/medios/foto/' . $id) : '';
$qrAcceso = $qrAcceso ?? '';
?>
<div class="stack stack--4" data-ficha-contenido>

  <div class="ficha">
    <div class="ficha__foto">
      <?php if ($foto !== ''): ?>
        <img src="<?= e($foto) ?>" alt="Fotografía de <?= e($persona['nombre']) ?>">
      <?php else: ?>
        <span class="ficha__iniciales" aria-hidden="true"><?= e(iniciales((string) $persona['nombre'])) ?></span>
      <?php endif; ?>
    </div>

    <div class="stack stack--2" style="min-width:0">
      <h2 style="font-size:23px;margin:0;line-height:1.15">
        <?= $persona['nombre'] !== '' ? e($persona['nombre']) : 'Registro sin completar' ?>
      </h2>
      <div class="row">
        <?php if (!\App\Modelos\Persona::registroCompleto($persona)): ?>
          <span class="tag tag--warn">Creó su acceso, falta el formulario</span>
        <?php endif; ?>
        <span class="tag <?= e(claseRol((string) $persona['rol'])) ?>"><?= e(etiquetaRol((string) $persona['rol'])) ?></span>
        <?php if ($credencial): ?>
          <span class="tag tag--mute"><?= e((string) $credencial['codigo']) ?></span>
        <?php endif; ?>
      </div>
      <span class="mono muted" style="font-size:12px;word-break:break-all"><?= e($persona['correo']) ?></span>
    </div>
  </div>

  <?php if ($claveNueva !== ''): ?>
    <!-- Se muestra una sola vez: no queda en ninguna parte donde volver a
         leerla, porque en la base solo hay el hash. -->
    <div class="notice notice--warn">
      <span class="notice__icon" aria-hidden="true">▲</span>
      <span class="stack" style="gap:8px;flex:1;min-width:0">
        <strong>Contraseña nueva de <?= e($persona['nombre']) ?></strong>
        <span class="sql-preview" style="max-height:none;font-size:20px;letter-spacing:.14em;text-align:center;white-space:normal"><?= e($claveNueva) ?></span>
        <span class="help">
          Anótala ahora o cópiala: esta es la única vez que aparece. Entra con su correo y
          esta contraseña, y que la cambie desde «Mis datos». La anterior ya no sirve y sus
          sesiones abiertas se cerraron.
        </span>
        <span class="row">
          <button class="btn btn--sm" type="button" data-copiar="<?= e($claveNueva) ?>">Copiar</button>
        </span>
      </span>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head"><span>Datos del registro</span></div>
    <div class="card__body--tight">
      <?php
      $filas = [
        ['Identificación', $documento !== ''
            ? $persona['tipo_documento'] . ' ' . documento($documento)
            : (\App\Modelos\Persona::registroCompleto($persona) ? 'Reservada' : 'Todavía no la ha dado')],
        ['Teléfono', ((int) $persona['comparte_telefono'] === 1 || $esAdministrador)
            ? ($persona['telefono'] ?: 'Sin registrar') : 'No lo comparte'],
        ['Entidad', $persona['entidad'] ?: 'Independiente'],
        ['Territorio', trim((string) $persona['municipio'] . ' · ' . (string) $persona['departamento'], ' ·') ?: 'Sin registrar'],
        ['Contactos intercambiados', numero($contactos)],
        ['Registrado', fecha((string) $persona['creado_en'])],
      ];
      foreach ($filas as [$k, $v]): ?>
        <div class="kv">
          <span class="kv__k"><?= e($k) ?></span>
          <strong class="kv__v"><?= e((string) $v) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <span>Ingresos</span>
      <span class="muted"><?= e((string) count(array_filter($historial, static fn($h) => $h['registrado_en']))) ?> de <?= e((string) count($historial)) ?></span>
    </div>
    <div class="card__body--tight">
      <?php if (!$historial): ?>
        <div class="empty" style="margin:8px 0">Este evento todavía no tiene jornadas</div>
      <?php else: foreach ($historial as $h): ?>
        <div class="kv">
          <span class="kv__k">Día <?= e((string) $h['numero']) ?> · <?= e(fecha((string) $h['fecha'])) ?></span>
          <span class="mono" style="font-size:11px;text-transform:uppercase;color:<?= $h['registrado_en'] ? 'var(--c-accent)' : 'var(--c-muted)' ?>">
            <?= $h['registrado_en'] ? 'Ingresó ' . e(hora((string) $h['registrado_en'])) : 'Sin ingreso' ?>
          </span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php if ($esAdministrador && $caracterizacion): ?>
    <div class="card">
      <div class="card__head">
        <span>Caracterización</span>
        <span class="tag tag--warn">Dato sensible</span>
      </div>
      <div class="card__body--tight">
        <?php foreach ([
          'Género' => $caracterizacion['genero'] ?? '',
          'Rango de edad' => $caracterizacion['rango_edad'] ?? '',
          'Grupo étnico' => $caracterizacion['etnia'] ?? '',
          'Discapacidad' => $caracterizacion['discapacidad'] ?? '',
        ] as $k => $v): ?>
          <div class="kv">
            <span class="kv__k"><?= e($k) ?></span>
            <strong class="kv__v"><?= e($v !== '' ? (string) $v : 'Sin declarar') ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($esAdministrador): ?>
    <!-- El perfil de asistencia, y con él el acceso del Staff.
         Solo aparece para el administrador porque «Staff» no es una etiqueta
         del carnet: deja ver los carnets de todo el evento, con la cédula de
         cada quien, y sellar ingresos. -->
    <div class="card">
      <div class="card__head"><span>Perfil de asistencia</span></div>
      <div class="card__body stack stack--3">
        <form class="row" method="post" action="<?= e(u('/admin/registros/perfil')) ?>"
              data-ficha-accion style="flex-wrap:nowrap;gap:10px">
          <?= testigo() ?>
          <input type="hidden" name="persona" value="<?= e((string) $id) ?>">
          <label class="sr-only" for="perfil-<?= e((string) $id) ?>">Perfil de asistencia</label>
          <select class="select" id="perfil-<?= e((string) $id) ?>" name="rol" style="flex:1">
            <?php
            // Los del evento de esta persona. Si el suyo ya no está en la lista
            // —uno que se eliminó a mano en la base—, igual se muestra: si no,
            // el navegador marcaría el primero y «Cambiar» se lo cambiaría.
            $perfilesFicha = perfilesDelEvento((int) $persona['evento_id']);
            $perfilesFicha += [(string) $persona['rol'] => etiquetaRol((string) $persona['rol'], (int) $persona['evento_id'])];
            foreach ($perfilesFicha as $unRol => $nombreRol): ?>
              <option value="<?= e((string) $unRol) ?>" <?= (string) $persona['rol'] === (string) $unRol ? 'selected' : '' ?>>
                <?= e($nombreRol) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--sm btn--primary" type="submit">Cambiar</button>
        </form>

        <p class="help" style="margin:0">
          <strong>Staff</strong> es el único que cambia algo más que el rótulo del carnet: quien
          lo tenga entra con su propio acceso de asistente y puede <strong>ver e imprimir los
          carnets del evento y acreditar ingresos</strong>. Los demás perfiles son solo
          descriptivos. Cada cambio queda en la bitácora.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><span>Cómo entra esta persona</span></div>
      <div class="card__body stack stack--4">

        <!-- Esto es lo que se puede responder cuando alguien dice que no
             recuerda su contraseña. Enseñarla es imposible: en la base hay un
             hash Argon2id, que es de un solo sentido a propósito. -->
        <div class="notice">
          <span class="notice__icon" aria-hidden="true">◆</span>
          <span>
            <strong>Su contraseña no se puede ver.</strong> La plataforma guarda solo una
            huella irreversible, así que nadie —tampoco un administrador, tampoco quien
            copie la base— puede leerla. Lo que sí se puede es darle una nueva o dejarla
            entrar con su QR.
          </span>
        </div>

        <div class="kv">
          <span class="kv__k">Contraseña</span>
          <strong class="kv__v"><?= $tieneClave ? 'Tiene una puesta' : 'Todavía no tiene' ?></strong>
        </div>

        <div class="row">
          <form method="post" action="<?= e(u('/admin/registros/clave')) ?>" data-ficha-accion
                data-confirmar="Se generará una contraseña nueva y la anterior dejará de servir. ¿Continuar?">
            <?= testigo() ?>
            <input type="hidden" name="persona" value="<?= e((string) $id) ?>">
            <button class="btn btn--sm btn--primary" type="submit">
              <?= $tieneClave ? 'Generar una contraseña nueva' : 'Asignarle una contraseña' ?>
            </button>
          </form>

          <?php if ($qrActivo): ?>
            <a class="btn btn--sm" href="<?= e(u('/admin/registros/' . $id, ['qr' => '1'])) ?>"
               data-ficha-ver="<?= e(u('/admin/registros/' . $id, ['qr' => '1'])) ?>">
              Ver su QR de acceso
            </a>
          <?php endif; ?>
        </div>

        <?php if ($qrAcceso !== ''): ?>
          <div class="qr-acceso">
            <div class="qr-acceso__code"><?= $qrAcceso /* SVG generado por el servidor */ ?></div>
            <div class="stack stack--3">
              <p class="help" style="margin:0">
                Enséñaselo para que lo escanee con su teléfono: entra sin escribir nada. No lo
                envíes por un canal compartido —un grupo de WhatsApp, un correo con copias—
                porque quien lo escanee entra como esta persona.
              </p>
              <div class="row">
                <button class="btn btn--sm" type="button" data-copiar="<?= e($urlAcceso) ?>">Copiar el enlace</button>
                <form method="post" action="<?= e(u('/admin/registros/qr')) ?>" data-ficha-accion
                      data-confirmar="El QR anterior dejará de funcionar. ¿Continuar?">
                  <?= testigo() ?>
                  <input type="hidden" name="persona" value="<?= e((string) $id) ?>">
                  <button class="btn btn--sm btn--danger" type="submit">Anular y generar otro</button>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  <?php endif; ?>

  <?php if ($credencial): ?>
    <div class="row no-print">
      <a class="btn btn--sm" href="<?= e(u('/c/' . $credencial['token'])) ?>">Abrir la acreditación</a>
      <a class="btn btn--sm" href="<?= e(u('/admin/registros')) ?>" data-cerrar-modal>Volver a registros</a>
    </div>
  <?php endif; ?>

</div>
