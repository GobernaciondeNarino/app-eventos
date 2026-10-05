<?php
/**
 * Configuración de la cuenta propia: contraseña y verificación en dos pasos.
 *
 * @var array       $cuenta
 * @var string      $estado2fa    'ninguno' | 'pendiente' | 'activo' | 'ilegible'
 * @var bool        $obligatorio  el rol exige segundo factor
 * @var string|null $qr           SVG del código nuevo, mientras se da de alta
 * @var string      $secreto      el mismo código, para escribirlo a mano
 * @var bool        $confirmando  se espera el código siguiente de una resincronización
 * @var array       $errores
 * @var array       $avisos
 * @var int         $horaServidor
 */
defined('EVENTOS_TIC') || exit;

guiones('segundo-factor.js');

$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
$roles = ['administrador' => 'Administrador', 'operador' => 'Operador de acceso', 'consulta' => 'Consulta'];
[$etiqueta2fa, $clase2fa] = match ($estado2fa) {
    'activo'   => ['Activa', 'tag--ok'],
    'ilegible' => ['No se puede leer', 'tag--danger'],
    default    => [$obligatorio ? 'Sin configurar' : 'Opcional · sin configurar', $obligatorio ? 'tag--danger' : 'tag--mute'],
};
?>
<div class="view view--wide stack stack--4">

  <div class="stack stack--2">
    <span class="kicker">Mi cuenta</span>
    <h1>Configuración</h1>
    <p class="help">Tu acceso al panel: la verificación en dos pasos y la contraseña.</p>
  </div>

  <div class="split" style="align-items:start">
    <div class="stack stack--4">

      <div class="card" id="segundo-factor">
        <div class="card__head">
          <span>Verificación en dos pasos</span>
          <span class="tag <?= e($clase2fa) ?>"><?= e($etiqueta2fa) ?></span>
        </div>
        <div class="card__body stack stack--4">

          <?php if ($qr !== null): ?>
            <!-- Alta en curso. La aplicación anterior sigue valiendo hasta
                 que esta se confirme. -->
            <p class="help">
              Escanea este código con la aplicación de autenticación del teléfono —Google
              Authenticator, Authy, FreeOTP o el gestor de contraseñas de la entidad— y escribe
              el código que te muestre. Hasta que lo confirmes, sigue valiendo el anterior.
            </p>
            <div class="row" style="gap:24px;align-items:flex-start;flex-wrap:wrap">
              <div class="qr-frame" style="width:200px"><?= $qr ?></div>
              <div class="stack stack--2" style="flex:1;min-width:220px">
                <span class="help">¿No puedes escanear? Escribe este código a mano:</span>
                <span class="mono" style="font-size:14px;letter-spacing:.14em;color:var(--c-title);word-break:break-all">
                  <?= e($secreto) ?>
                </span>
              </div>
            </div>

            <?php foreach ($avisos as $texto): ?>
              <div class="notice notice--warn" role="status">
                <span class="notice__icon" aria-hidden="true">i</span>
                <span><?= e($texto) ?></span>
              </div>
            <?php endforeach; ?>

            <form method="post" action="<?= e(u('/admin/cuenta')) ?>" class="stack stack--3" novalidate>
              <?= testigo() ?>
              <input type="hidden" name="accion" value="confirmar">
              <div class="field">
                <label class="label" for="codigo">
                  <?= $confirmando ? 'El código siguiente que muestra la aplicación' : 'Código que muestra la aplicación' ?>
                </label>
                <input class="input campo-otp<?= $err('codigo') ? ' is-invalid' : '' ?>" id="codigo" name="codigo"
                       inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                       placeholder="000000" autofocus required>
                <?php if ($err('codigo')): ?><span class="error"><?= e($err('codigo')) ?></span><?php endif; ?>
              </div>
              <button class="btn btn--primary" type="submit">Confirmar y usar esta aplicación</button>
            </form>
            <form method="post" action="<?= e(u('/admin/cuenta')) ?>">
              <?= testigo() ?>
              <input type="hidden" name="accion" value="cancelar">
              <button class="btn btn--sm" type="submit">Cancelar y dejarlo como estaba</button>
            </form>
            <p class="help" data-hora-servidor="<?= (int) $horaServidor ?>">
              Hora de este servidor: <span class="mono"><?= e(date('H:i:s', (int) $horaServidor)) ?></span>.
            </p>
            <p class="help" data-reloj-aviso hidden></p>

          <?php else: ?>
            <?php if ($estado2fa === 'activo'): ?>
              <p class="help">
                Al entrar, además de la contraseña se pide el código de la aplicación del teléfono.
                Si cambiaste de teléfono, borraste la aplicación o el código no te funciona, genera uno
                nuevo aquí: el anterior sigue valiendo hasta que confirmes el nuevo.
              </p>
            <?php elseif ($estado2fa === 'ilegible'): ?>
              <div class="notice notice--danger">
                <span class="notice__icon" aria-hidden="true">▲</span>
                <span>
                  La configuración guardada no se puede leer: la llave de cifrado de la instalación
                  cambió. Genera un código nuevo y escanéalo; borra de la aplicación la entrada vieja.
                </span>
              </div>
            <?php else: ?>
              <p class="help">
                <?= $obligatorio
                    ? 'Tu rol la exige. Genera el código y escanéalo con la aplicación del teléfono.'
                    : 'Es opcional para tu rol y muy recomendable: con ella, la contraseña sola no basta para entrar.' ?>
              </p>
            <?php endif; ?>

            <form method="post" action="<?= e(u('/admin/cuenta')) ?>" class="stack stack--3" novalidate>
              <?= testigo() ?>
              <input type="hidden" name="accion" value="nuevo">
              <div class="field">
                <label class="label" for="clave">Tu contraseña actual</label>
                <input class="input<?= $err('clave') ? ' is-invalid' : '' ?>" id="clave" name="clave"
                       type="password" autocomplete="current-password" required>
                <span class="help">Se pide para que una sesión abierta en un equipo ajeno no sirva para esto.</span>
                <?php if ($err('clave')): ?><span class="error"><?= e($err('clave')) ?></span><?php endif; ?>
              </div>
              <button class="btn btn--primary" type="submit">
                <?= in_array($estado2fa, ['activo', 'ilegible'], true) ? 'Restablecer: generar un código QR nuevo' : 'Generar el código QR' ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><span>Contraseña</span></div>
        <div class="card__body row row--between" style="gap:12px;flex-wrap:wrap">
          <span class="help" style="margin:0">Cámbiala si alguien más pudo verla, o si te la dio otra persona.</span>
          <a class="btn btn--sm" href="<?= e(u('/admin/clave')) ?>">Cambiar mi contraseña</a>
        </div>
      </div>
    </div>

    <div class="card is-sticky">
      <div class="card__head"><span>Datos de la cuenta</span></div>
      <div class="card__body stack stack--3">
        <div class="stack" style="gap:2px">
          <span class="label">Nombre</span>
          <strong style="font-weight:500;color:var(--c-title)"><?= e((string) $cuenta['nombre']) ?></strong>
        </div>
        <div class="stack" style="gap:2px">
          <span class="label">Correo</span>
          <span class="mono"><?= e((string) $cuenta['correo']) ?></span>
        </div>
        <div class="stack" style="gap:2px">
          <span class="label">Rol</span>
          <span><?= e($roles[$cuenta['rol']] ?? (string) $cuenta['rol']) ?></span>
        </div>
        <?php if (!empty($cuenta['ultimo_acceso'])): ?>
          <div class="stack" style="gap:2px">
            <span class="label">Último acceso</span>
            <span class="mono"><?= e(date('d/m/Y H:i', (int) strtotime((string) $cuenta['ultimo_acceso']))) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
