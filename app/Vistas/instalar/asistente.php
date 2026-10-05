<?php
/**
 * Asistente de instalación.
 * @var int $paso @var array $pasos @var array $estado @var array $errores
 * @var array $requisitos @var array $permisos @var array $bloqueantes
 * @var array $presets @var array $tipografias
 * @var bool $reparacion @var array|null $diagnostico
 */
defined('EVENTOS_TIC') || exit;

use App\Esquema;

$reparacion = $reparacion ?? false;
$diagnostico = $diagnostico ?? null;
$bd = $estado['bd'] ?? [];
$admin = $estado['admin'] ?? [];
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');

$pintarChecks = static function (array $items): void {
    foreach ($items as $r) {
        $icono = $r['estado'] === 'ok' ? '✓' : ($r['estado'] === 'warn' ? '▲' : '✕');
        echo '<div class="check check--' . e($r['estado']) . '">'
            . '<span class="check__icon" aria-hidden="true">' . $icono . '</span>'
            . '<div class="stack" style="gap:2px">'
            . '<span class="check__name">' . e($r['nombre']) . '</span>'
            . '<span class="check__detail">' . e($r['detalle']) . '</span>'
            . '</div>'
            . '<span class="check__value">' . e($r['valor']) . '</span>'
            . '</div>';
    }
};
guiones('instalador.js');
?>
<main class="installer" id="contenido">
  <div class="installer__inner">

    <header class="stack stack--2">
      <div class="row" style="gap:12px">
        <span class="brandmark brandmark--lg" aria-hidden="true">E</span>
        <div class="stack" style="gap:2px">
          <span class="brandtext__name" style="font-size:15px">Plataforma de Eventos TIC</span>
          <span class="brandtext__sub">
            <?= $reparacion ? 'Reparación de la instalación' : 'Asistente de instalación' ?>
            · versión <?= e(APP_VERSION) ?>
          </span>
        </div>
      </div>
    </header>

    <nav class="wizard-steps" aria-label="Pasos de la instalación">
      <?php foreach ($pasos as $i => $titulo): $n = $i + 1; ?>
        <div class="wizard-step<?= $n === $paso ? ' is-active' : ($n < $paso ? ' is-done' : '') ?>"
             <?= $n === $paso ? 'aria-current="step"' : '' ?>>
          <span class="wizard-step__n">Paso <?= $n ?></span>
          <span class="wizard-step__t"><?= e($titulo) ?></span>
        </div>
      <?php endforeach; ?>
    </nav>

    <?php if ($reparacion): ?>
      <div class="notice notice--warn">
        <span class="notice__icon" aria-hidden="true">▲</span>
        <span class="stack" style="gap:6px">
          <strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
            La instalación quedó a medias
          </strong>
          <span><?= e((string) ($diagnostico['motivo'] ?? '')) ?></span>
          <span class="help">
            Por eso el asistente volvió a abrirse: cerrarlo aquí dejaría la plataforma sin ninguna
            forma de entrar. Vuelve a dar los datos de la base de datos —son la única llave de este
            proceso— y crea la cuenta administradora. <strong>No se borrará nada:</strong> la opción
            de instalación limpia está desactivada.
          </span>
        </span>
      </div>
    <?php endif; ?>

    <?php if ($err('general')): ?>
      <div class="notice notice--danger">
        <span class="notice__icon" aria-hidden="true">▲</span>
        <span><?= e($err('general')) ?></span>
      </div>
    <?php endif; ?>

    <?php /* =============== Paso 1 =============== */ if ($paso === 1): ?>
      <form method="post" action="<?= e(u('/instalar')) ?>" class="stack stack--4">
        <?= testigo() ?>
        <input type="hidden" name="accion" value="paso1">

        <div class="stack stack--2">
          <span class="kicker">Paso 1 de 6</span>
          <h1>Comprobación del servidor</h1>
          <p class="lead">
            Antes de tocar la base de datos, el asistente verifica que el servidor cumpla lo
            mínimo. Si algo aparece en rojo, la instalación no continúa.
          </p>
        </div>

        <div class="card">
          <div class="card__head"><span>Requisitos</span></div>
          <div class="card__body"><div class="check-list"><?php $pintarChecks($requisitos); ?></div></div>
        </div>

        <div class="card">
          <div class="card__head"><span>Permisos de escritura</span></div>
          <div class="card__body"><div class="check-list"><?php $pintarChecks($permisos); ?></div></div>
        </div>

        <?php if ($bloqueantes): ?>
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span>
              Hay <?= count($bloqueantes) ?> requisitos sin cumplir. En Plesk, la versión de PHP y
              sus extensiones se cambian en «Configuración de PHP» del dominio; los permisos de
              carpeta, en el administrador de archivos.
            </span>
          </div>
        <?php endif; ?>

        <div class="row row--end">
          <button class="btn btn--primary" type="submit" <?= $bloqueantes ? 'disabled' : '' ?>>Continuar</button>
        </div>
      </form>

    <?php /* =============== Paso 2 =============== */ elseif ($paso === 2): ?>
      <form method="post" action="<?= e(u('/instalar')) ?>" class="stack stack--4" id="form-bd">
        <?= testigo() ?>
        <input type="hidden" name="accion" value="paso2">

        <div class="stack stack--2">
          <span class="kicker">Paso 2 de 6</span>
          <h1>Conexión a la base de datos</h1>
          <p class="lead">
            En Plesk estos datos se crean en «Bases de datos» del dominio. El asistente los
            guarda en <span class="mono accent">config/config.php</span>.
          </p>
        </div>

        <div class="card">
          <div class="card__head"><span>Parámetros de conexión</span></div>
          <div class="card__body stack stack--4">
            <div class="grid-2">
              <div class="field">
                <label class="label" for="bd_host">Servidor</label>
                <input class="input input--mono<?= $err('bd_host') ? ' is-invalid' : '' ?>" id="bd_host"
                       name="bd_host" value="<?= e((string) ($bd['host'] ?? 'localhost')) ?>">
                <span class="help">En Plesk casi siempre <span class="mono">localhost</span>.</span>
                <?php if ($err('bd_host')): ?><span class="error"><?= e($err('bd_host')) ?></span><?php endif; ?>
              </div>
              <div class="field">
                <label class="label" for="bd_puerto">Puerto</label>
                <input class="input input--mono" id="bd_puerto" name="bd_puerto"
                       value="<?= e((string) ($bd['puerto'] ?? 3306)) ?>" inputmode="numeric">
              </div>
            </div>

            <div class="grid-2">
              <div class="field">
                <label class="label" for="bd_nombre">Nombre de la base de datos</label>
                <input class="input input--mono<?= $err('bd_nombre') ? ' is-invalid' : '' ?>" id="bd_nombre"
                       name="bd_nombre" value="<?= e((string) ($bd['nombre'] ?? '')) ?>" placeholder="eventos_tic" required>
                <?php if ($err('bd_nombre')): ?><span class="error"><?= e($err('bd_nombre')) ?></span><?php endif; ?>
              </div>
              <div class="field">
                <label class="label" for="bd_usuario">Usuario</label>
                <input class="input input--mono<?= $err('bd_usuario') ? ' is-invalid' : '' ?>" id="bd_usuario"
                       name="bd_usuario" value="<?= e((string) ($bd['usuario'] ?? '')) ?>"
                       placeholder="eventos_app" autocomplete="off" required>
                <?php if ($err('bd_usuario')): ?><span class="error"><?= e($err('bd_usuario')) ?></span><?php endif; ?>
              </div>
            </div>

            <div class="grid-2">
              <div class="field">
                <label class="label" for="bd_clave">Contraseña</label>
                <input class="input" id="bd_clave" name="bd_clave" type="password" autocomplete="new-password">
              </div>
              <div class="field">
                <label class="label" for="bd_prefijo">Prefijo de tablas</label>
                <input class="input input--mono<?= $err('bd_prefijo') ? ' is-invalid' : '' ?>" id="bd_prefijo"
                       name="bd_prefijo" value="<?= e((string) ($bd['prefijo'] ?? 'evt_')) ?>">
                <span class="help">Permite compartir la base con otras aplicaciones.</span>
                <?php if ($err('bd_prefijo')): ?><span class="error"><?= e($err('bd_prefijo')) ?></span><?php endif; ?>
              </div>
            </div>

            <div class="notice">
              <span class="notice__icon" aria-hidden="true">◆</span>
              <span>
                Usa un usuario dedicado con permisos solo sobre esta base de datos. Si la
                aplicación se ve comprometida, el daño queda acotado a estas tablas.
              </span>
            </div>

            <div class="row">
              <button class="btn" type="button" data-probar-conexion="<?= e(u('/instalar')) ?>">Probar conexión</button>
              <span class="help mono" data-estado-conexion></span>
            </div>
          </div>
        </div>

        <div class="row row--between">
          <button class="btn btn--primary" type="submit">Continuar</button>
          <button class="btn" type="submit" name="accion" value="atras"
                  formnovalidate style="order:-1">Atrás</button>
        </div>
        <input type="hidden" name="a" value="1">
      </form>

    <?php /* =============== Paso 3 =============== */ elseif ($paso === 3): ?>
      <?php
      // Lo mira el controlador, que es quien puede abrir la conexión.
      $conectado = (bool) ($esquema['conectado'] ?? false);
      $existentes = (array) ($esquema['existentes'] ?? []);
      $versionPrevia = $esquema['version'] ?? null;
      $prefijo = (string) ($bd['prefijo'] ?? 'evt_');

      // Solo se sugiere borrar cuando consta que no hay nada que borrar. Con
      // tablas presentes —o sin poder comprobarlo— se sugiere actualizar: crea
      // lo que falte, agrega columnas y no borra nada, así que sirve igual para
      // una base vieja que para una a medias.
      $modoSugerido = ($conectado && !$existentes) ? 'limpio' : 'actualizar';

      $modos = [
        ['limpio', 'Instalación limpia', 'Elimina las tablas con este prefijo y las crea desde cero. Se pierden los datos que hubiera.'],
        ['actualizar', 'Actualizar lo existente', 'Conserva los datos y solo agrega las tablas y columnas que falten. Es lo que se usa al subir una versión nueva.'],
        ['anexar', 'Anexar sin tocar nada', 'Crea únicamente las tablas que falten. Si a las que ya están les faltan columnas de esta versión, la plataforma las agrega sola al abrirse.'],
      ];
      if ($reparacion) {
          // Reparando no se ofrece borrar: es lo contrario de lo que se vino a hacer.
          $modos = array_values(array_filter($modos, static fn(array $m): bool => $m[0] !== 'limpio'));
          $modoSugerido = 'actualizar';
      }
      ?>
      <form method="post" action="<?= e(u('/instalar')) ?>" class="stack stack--4">
        <?= testigo() ?>
        <input type="hidden" name="accion" value="paso3">

        <div class="stack stack--2">
          <span class="kicker">Paso 3 de 6</span>
          <h1>Tablas de la aplicación</h1>
          <p class="lead">
            El asistente comparó lo que hay en la base con lo que la versión
            <?= e(Esquema::VERSION) ?> necesita. Nada se ejecuta hasta que confirmes.
          </p>
        </div>

        <div class="card">
          <div class="card__head">
            <span>Lo que se encontró</span>
            <span class="muted"><?= count($existentes) ?> de <?= count(Esquema::nombres()) ?> tablas presentes</span>
          </div>
          <div class="card__body stack stack--3">
            <?php if (!$conectado): ?>
              <p class="help">
                <strong>No se pudo comprobar qué hay en la base de datos.</strong> Vuelve al paso
                anterior y confirma los datos de conexión. Mientras tanto se propone el modo que
                no borra nada; no elijas la instalación limpia sin saber qué hay al otro lado.
              </p>
            <?php elseif (!$existentes): ?>
              <p class="help">No hay ninguna tabla con el prefijo <span class="mono"><?= e($prefijo) ?></span>. Es una instalación nueva.</p>
            <?php else: ?>
              <p class="help">
                Ya existen <?= count($existentes) ?> tablas con el prefijo <span class="mono"><?= e($prefijo) ?></span>
                <?= $versionPrevia ? ', de una instalación versión ' . e($versionPrevia) : '' ?>.
              </p>
            <?php endif; ?>
          </div>
        </div>

        <?php $llave = (array) ($esquema['llave'] ?? []); ?>
        <?php if (!empty($llave['falta']) || $err('llave_previa')): ?>
          <!-- La base tiene datos cifrados y la configuración no trae la llave.
               Pasa cuando config/config.php se perdió al subir la versión
               nueva. Seguir con una llave nueva deja ilegibles las cédulas y
               el segundo factor de cada cuenta, así que se pide la anterior. -->
          <div class="card" id="llave-anterior">
            <div class="card__head"><span>Llave de cifrado anterior</span></div>
            <div class="card__body stack stack--3">
              <div class="notice notice--warn">
                <span class="notice__icon" aria-hidden="true">▲</span>
                <span>
                  <strong>Falta el archivo config/config.php de la instalación anterior.</strong>
                  La base guarda <?= (int) ($llave['personas'] ?? 0) ?> documentos de identidad y
                  <?= (int) ($llave['cuentas'] ?? 0) ?> configuraciones de verificación en dos pasos
                  cifrados con una llave que estaba en ese archivo. Sin ella no se pueden leer.
                </span>
              </div>
              <div class="field">
                <label class="label" for="llave_previa">Llave anterior</label>
                <input class="input mono<?= $err('llave_previa') ? ' is-invalid' : '' ?>" id="llave_previa"
                       name="llave_previa" autocomplete="off" spellcheck="false"
                       placeholder="'llave_cifrado' => '…'">
                <span class="help">
                  Está en la copia de seguridad del sitio, dentro de
                  <span class="mono">config/config.php</span>: la línea
                  <span class="mono">'llave_cifrado' =&gt; '…'</span>. Puedes pegar la línea entera.
                  Se comprueba contra los datos antes de aceptarla.
                </span>
                <?php if ($err('llave_previa')): ?><span class="error"><?= e($err('llave_previa')) ?></span><?php endif; ?>
              </div>
              <label class="row" style="gap:8px;align-items:flex-start;cursor:pointer">
                <input type="checkbox" name="sin_llave_previa" value="1"
                       style="margin-top:3px;width:16px;height:16px;accent-color:var(--c-danger)">
                <span class="help" style="margin:0">
                  No la tengo. Seguir con una llave nueva: los documentos guardados no se podrán
                  leer, y cada cuenta volverá a configurar su verificación en dos pasos al entrar.
                </span>
              </label>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card__head"><span>Qué hacer con ellas</span></div>
          <div class="card__body stack stack--3">
            <?php foreach ($modos as [$clave, $titulo, $texto]): ?>
              <label class="notice<?= $clave === $modoSugerido ? ' notice--warn' : '' ?>" style="cursor:pointer;align-items:flex-start">
                <input type="radio" name="modo" value="<?= e($clave) ?>" data-modo
                       style="margin-top:3px;width:16px;height:16px;accent-color:var(--c-accent)"
                       <?= $clave === $modoSugerido ? 'checked' : '' ?>>
                <span class="stack" style="gap:3px">
                  <strong style="font-family:var(--f-display);font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
                    <?= e($titulo) ?><?= $clave === $modoSugerido ? ' · sugerido' : '' ?>
                  </strong>
                  <span class="help"><?= e($texto) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($existentes): ?>
          <div class="notice notice--danger" data-aviso-limpio>
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span class="stack stack--2">
              <span>
                <strong>La instalación limpia borra datos.</strong> Hay <?= count($existentes) ?> tablas
                con este prefijo y su contenido se perderá. Haz una copia de seguridad desde Plesk
                antes de continuar.
              </span>
              <label class="row" style="gap:8px;align-items:flex-start;cursor:pointer">
                <input type="checkbox" name="confirmar_limpio" value="1"
                       style="margin-top:3px;width:16px;height:16px;accent-color:var(--c-danger)">
                <span>Entiendo que se borran las <?= count($existentes) ?> tablas y todo lo que guardan.</span>
              </label>
            </span>
          </div>
        <?php endif; ?>

        <details class="card">
          <summary class="card__head" style="cursor:pointer;list-style:none">
            <span>Ver el SQL que se ejecutará</span>
          </summary>
          <div class="card__body" style="padding:0">
            <pre class="sql-preview"><?= e(Esquema::guion($prefijo, $modoSugerido, $existentes)) ?></pre>
          </div>
        </details>

        <div class="row row--between">
          <button class="btn btn--primary" type="submit">Aplicar y continuar</button>
          <button class="btn" type="submit" name="accion" value="atras"
                  formnovalidate style="order:-1">Atrás</button>
        </div>
        <input type="hidden" name="a" value="2">
      </form>

    <?php /* =============== Paso 4 =============== */ elseif ($paso === 4): ?>
      <form method="post" action="<?= e(u('/instalar')) ?>" class="stack stack--4">
        <?= testigo() ?>
        <input type="hidden" name="accion" value="paso4">

        <div class="stack stack--2">
          <span class="kicker">Paso 4 de 6</span>
          <h1>Cuenta administradora</h1>
          <p class="lead">La primera cuenta del sistema. Podrá crear el resto del equipo.</p>
        </div>

        <?php if (!empty($estado['tablas'])): ?>
          <div class="card">
            <div class="card__head">
              <span>Tablas aplicadas</span>
              <span class="muted"><?= count($estado['tablas']) ?> operaciones</span>
            </div>
            <div class="card__body">
              <div class="check-list">
                <?php foreach ($estado['tablas'] as $t): ?>
                  <div class="check check--ok">
                    <span class="check__icon" aria-hidden="true">✓</span>
                    <div class="stack" style="gap:2px">
                      <span class="check__name mono"><?= e($t['tabla']) ?></span>
                      <span class="check__detail"><?= e($t['detalle']) ?></span>
                    </div>
                    <span class="check__value"><?= e($t['accion']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (in_array($estado['modo'] ?? 'limpio', ['actualizar', 'anexar'], true)): ?>
          <div class="notice">
            <span class="notice__icon" aria-hidden="true">i</span>
            <span>
              <strong>Actualizando una instalación con datos.</strong> Escribe el correo de la cuenta
              administradora que ya usas: solo se le cambia la contraseña. Conserva su verificación
              en dos pasos y el código de la aplicación del teléfono sigue sirviendo.
            </span>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card__head"><span>Datos de la cuenta</span></div>
          <div class="card__body stack stack--4">
            <div class="grid-2">
              <div class="field">
                <label class="label" for="ad_nombre">Nombre completo</label>
                <input class="input<?= $err('ad_nombre') ? ' is-invalid' : '' ?>" id="ad_nombre" name="ad_nombre"
                       value="<?= e((string) ($admin['nombre'] ?? '')) ?>" autocomplete="name" required>
                <?php if ($err('ad_nombre')): ?><span class="error"><?= e($err('ad_nombre')) ?></span><?php endif; ?>
              </div>
              <div class="field">
                <label class="label" for="ad_correo">Correo institucional</label>
                <input class="input<?= $err('ad_correo') ? ' is-invalid' : '' ?>" id="ad_correo" name="ad_correo"
                       type="email" value="<?= e((string) ($admin['correo'] ?? '')) ?>" autocomplete="username" required>
                <?php if ($err('ad_correo')): ?><span class="error"><?= e($err('ad_correo')) ?></span><?php endif; ?>
              </div>
            </div>

            <div class="field">
              <label class="label" for="ad_clave">Contraseña</label>
              <input class="input<?= $err('ad_clave') ? ' is-invalid' : '' ?>" id="ad_clave" name="ad_clave"
                     type="password" autocomplete="new-password" minlength="12" required data-fuerza>
              <div class="progress" style="margin-top:6px"><div class="progress__bar" data-fuerza-barra style="width:0"></div></div>
              <span class="help" data-fuerza-texto>Mínimo 12 caracteres. Una frase larga es más segura que un símbolo raro.</span>
              <?php if ($err('ad_clave')): ?><span class="error"><?= e($err('ad_clave')) ?></span><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="ad_clave2">Repite la contraseña</label>
              <input class="input<?= $err('ad_clave2') ? ' is-invalid' : '' ?>" id="ad_clave2" name="ad_clave2"
                     type="password" autocomplete="new-password" required>
              <?php if ($err('ad_clave2')): ?><span class="error"><?= e($err('ad_clave2')) ?></span><?php endif; ?>
            </div>

            <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer">
              <?php // Al volver a pasar por el asistente se respeta lo que ya estaba decidido. ?>
              <input type="checkbox" name="ad_2fa" value="1"
                     <?= \App\Nucleo\Config::obtener('exigir_2fa_admin', true) ? 'checked' : '' ?>
                     style="margin-top:3px;width:18px;height:18px;accent-color:var(--c-accent)">
              <span class="help" style="flex:1">
                Exigir segundo factor a las cuentas administrativas (recomendado). Al primer
                inicio de sesión se mostrará el código para la aplicación de autenticación.
              </span>
            </label>
          </div>
        </div>

        <div class="row row--between">
          <button class="btn btn--primary" type="submit">Continuar</button>
          <button class="btn" type="submit" name="accion" value="atras"
                  formnovalidate style="order:-1">Atrás</button>
        </div>
        <input type="hidden" name="a" value="3">
      </form>

    <?php /* =============== Paso 5 =============== */ elseif ($paso === 5): ?>
      <form method="post" action="<?= e(u('/instalar')) ?>" class="stack stack--4">
        <?= testigo() ?>
        <input type="hidden" name="accion" value="paso5">

        <div class="stack stack--2">
          <span class="kicker">Paso 5 de 6</span>
          <h1>Primer evento</h1>
          <p class="lead">Se puede cambiar después desde el panel; esto solo evita empezar con la pantalla en blanco.</p>
        </div>

        <div class="card">
          <div class="card__head"><span>Datos del evento</span></div>
          <div class="card__body stack stack--4">
            <div class="field">
              <label class="label" for="ev_nombre">Nombre del evento</label>
              <input class="input<?= $err('ev_nombre') ? ' is-invalid' : '' ?>" id="ev_nombre" name="ev_nombre"
                     value="Cumbre Tecnológica CIOS Nariño" maxlength="160" required>
              <?php if ($err('ev_nombre')): ?><span class="error"><?= e($err('ev_nombre')) ?></span><?php endif; ?>
            </div>
            <div class="grid-2">
              <div class="field">
                <label class="label" for="ev_dependencia">Dependencia</label>
                <input class="input" id="ev_dependencia" name="ev_dependencia"
                       value="Secretaría TIC, Innovación y Gobierno Abierto" maxlength="160">
              </div>
              <div class="field">
                <label class="label" for="ev_sede">Sede</label>
                <input class="input" id="ev_sede" name="ev_sede" value="Pasto, Nariño" maxlength="160">
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label class="label" for="ev_inicio">Fecha de inicio</label>
                <input class="input<?= $err('ev_inicio') ? ' is-invalid' : '' ?>" id="ev_inicio" name="ev_inicio"
                       type="date" value="<?= e(date('Y-m-d')) ?>" required>
                <?php if ($err('ev_inicio')): ?><span class="error"><?= e($err('ev_inicio')) ?></span><?php endif; ?>
              </div>
              <div class="field">
                <label class="label" for="ev_dias">Número de jornadas</label>
                <input class="input input--mono" id="ev_dias" name="ev_dias" type="number" min="1" max="30" value="3">
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><span>Identidad visual</span></div>
          <div class="card__body stack stack--4">
            <div class="field">
              <span class="label">Paleta</span>
              <div class="row">
                <?php foreach ($presets as $clave => $p): ?>
                  <label class="chip<?= $clave === 'tic-nocturno' ? ' is-active' : '' ?>" style="display:flex;align-items:center;gap:8px">
                    <input type="radio" name="preset" value="<?= e($clave) ?>" class="sr-only"
                           <?= $clave === 'tic-nocturno' ? 'checked' : '' ?>>
                    <span style="display:flex;gap:2px">
                      <?php foreach (['brand', 'accent', 'bg'] as $c): ?>
                        <span style="width:10px;height:10px;background:<?= e($p['colores'][$c]) ?>;border:1px solid rgba(255,255,255,.15)"></span>
                      <?php endforeach; ?>
                    </span>
                    <?= e($p['nombre']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="field">
              <span class="label">Tipografía</span>
              <div class="row">
                <?php foreach ($tipografias as $clave => $t): ?>
                  <label class="chip<?= $clave === 'tecnologica' ? ' is-active' : '' ?>">
                    <input type="radio" name="tipografia" value="<?= e($clave) ?>" class="sr-only"
                           <?= $clave === 'tecnologica' ? 'checked' : '' ?>>
                    <?= e($t['nombre']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="help">Se puede afinar color por color desde el panel de identidad una vez instalado.</p>
          </div>
        </div>

        <div class="row row--between">
          <button class="btn btn--primary" type="submit">Instalar</button>
          <button class="btn" type="submit" name="accion" value="atras"
                  formnovalidate style="order:-1">Atrás</button>
        </div>
        <input type="hidden" name="a" value="4">
      </form>

    <?php /* =============== Paso 6 =============== */ else:
      $r = $estado['resultado'] ?? []; ?>
      <div class="stack stack--4">
        <div class="stack stack--2">
          <span class="kicker">Paso 6 de 6</span>
          <h1>Instalación terminada</h1>
        </div>

        <div class="card">
          <div class="card__head"><span>Resultado</span></div>
          <div class="card__body">
            <div class="check-list">
              <?php $pintarChecks([
                ['nombre' => 'Archivo de configuración', 'detalle' => 'config/config.php', 'valor' => 'escrito', 'estado' => 'ok'],
                ['nombre' => 'Tablas del esquema', 'detalle' => 'Prefijo ' . ($r['prefijo'] ?? 'evt_'), 'valor' => count(Esquema::nombres()) . ' tablas', 'estado' => 'ok'],
                ['nombre' => 'Cuenta administradora', 'detalle' => (string) ($r['correo'] ?? ''), 'valor' => !empty($r['cuenta_existia']) ? 'contraseña cambiada' : 'creada', 'estado' => 'ok'],
                ['nombre' => 'Segundo factor', 'detalle' => !empty($r['conserva_2fa']) ? 'Se conserva: el código del teléfono sigue sirviendo' : 'Obligatorio para administradores', 'valor' => !empty($r['exigir_2fa']) ? 'activado' : 'desactivado', 'estado' => !empty($r['exigir_2fa']) ? 'ok' : 'warn'],
                !empty($r['evento_existia'])
                  ? ['nombre' => 'Evento activo', 'detalle' => 'Se conservó con sus registros', 'valor' => 'sin cambios', 'estado' => 'ok']
                  : ['nombre' => 'Primer evento', 'detalle' => (string) ($r['evento'] ?? ''), 'valor' => ($r['jornadas'] ?? 0) . ' jornadas', 'estado' => 'ok'],
                !empty($r['evento_existia'])
                  ? ['nombre' => 'Códigos QR de acceso', 'detalle' => 'Los de cada jornada', 'valor' => 'sin cambios', 'estado' => 'ok']
                  : ['nombre' => 'Códigos QR de acceso', 'detalle' => 'Uno por jornada', 'valor' => ($r['jornadas'] ?? 0) . ' generados', 'estado' => 'ok'],
                ['nombre' => 'HTTPS', 'detalle' => 'Sin TLS la plataforma no debe salir a producción', 'valor' => !empty($r['https']) ? 'activo' : 'pendiente', 'estado' => !empty($r['https']) ? 'ok' : 'warn'],
                ['nombre' => 'Envío de correo', 'detalle' => 'Códigos de acceso y carnets', 'valor' => !empty($r['correo_ok']) ? 'disponible' : 'sin configurar', 'estado' => !empty($r['correo_ok']) ? 'ok' : 'warn'],
              ]); ?>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><span>Cómo entrar al panel</span></div>
          <div class="card__body stack stack--3">
            <p class="help">
              Anota esta dirección. El panel no vive en una carpeta <span class="mono">/admin/</span>
              del servidor: es una ruta de la aplicación, y <span class="mono">/admin</span> a secas
              solo redirige aquí.
            </p>
            <div class="check-list">
              <?php $pintarChecks([
                ['nombre' => 'Dirección de acceso', 'detalle' => \App\Nucleo\Url::absoluta('/admin/entrar'), 'valor' => 'guárdala', 'estado' => 'ok'],
                ['nombre' => 'Usuario', 'detalle' => 'El correo con el que creaste la cuenta', 'valor' => (string) ($r['correo'] ?? ''), 'estado' => 'ok'],
                ['nombre' => 'Dónde queda guardada', 'detalle' => 'Tabla de la base de datos, con la contraseña en hash', 'valor' => (string) ($r['tabla_usuario'] ?? (($r['prefijo'] ?? 'evt_') . 'usuario')), 'estado' => 'ok'],
              ]); ?>
            </div>
            <p class="help">
              La contraseña no se guarda en ninguna parte en claro: en esa tabla queda su hash
              Argon2id. Si se pierde, se restablece desde
              <span class="mono">herramientas/cuenta.php</span> por consola.
            </p>
          </div>
        </div>

        <?php if (!empty($r['llave_nueva'])): ?>
          <div class="notice notice--warn">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span>
              <strong>Se generó una llave de cifrado nueva.</strong> Los documentos de identidad
              guardados antes no se pueden leer, y las demás cuentas del equipo configurarán otra vez
              su verificación en dos pasos al entrar, con un código que les llega al correo. Guarda una
              copia de <span class="mono">config/config.php</span>: es lo único que evita esto la
              próxima vez.
            </span>
          </div>
        <?php endif; ?>

        <?php if (!empty($r['permiso_abierto'])): ?>
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span>
              <strong>Borra el archivo <span class="mono">config/permitir-reinstalar</span>.</strong>
              No se pudo borrar solo. Mientras exista, el asistente sigue abierto.
            </span>
          </div>
        <?php else: ?>
          <div class="notice notice--ok">
            <span class="notice__icon" aria-hidden="true">✓</span>
            <span>
              <strong>El asistente ya no es accesible.</strong> Al existir
              <span class="mono">config/config.php</span> con la instalación marcada como
              completa, esta ruta queda cerrada. No hay que borrar ninguna carpeta a mano.
            </span>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card__head"><span>Siguientes pasos</span></div>
          <div class="card__body">
            <div class="steps">
              <?php foreach ([
                ['01', 'Activa HTTPS', 'En Plesk, «Certificados SSL/TLS» del dominio. Sin TLS, las contraseñas del equipo y los tokens de los carnets viajan en claro por la red del recinto.'],
                match (true) {
                  !empty($r['conserva_2fa']) => ['02', 'Entra con tu mismo código', 'Tu verificación en dos pasos se conservó: usa la misma aplicación del teléfono de siempre.'],
                  !empty($r['rehacer_2fa'])  => ['02', 'Vuelve a configurar tu segundo factor', 'La llave de cifrado cambió y la configuración anterior no se puede leer. Al entrar verás un código QR nuevo: escanéalo y borra de la aplicación la entrada vieja.'],
                  default                    => ['02', 'Activa tu segundo factor', 'Al entrar por primera vez se te pedirá vincular la aplicación de autenticación.'],
                },
                ['03', 'Revisa el correo saliente', 'Sin correo, la plataforma no puede enviar códigos de acceso ni carnets. En Plesk se configura en «Correo».'],
                ['04', 'Programa las copias de seguridad', 'Una copia diaria durante la semana del evento y una antes de cada actualización.'],
                ['05', 'Sube el logo y revisa el contraste', 'Desde Identidad del evento. La revisión avisa si el texto quedará ilegible bajo el sol.'],
              ] as [$n, $t, $d]): ?>
                <div class="steps__item">
                  <span class="steps__n"><?= e($n) ?></span>
                  <div class="stack" style="gap:5px">
                    <strong class="steps__t"><?= e($t) ?></strong>
                    <span class="steps__d"><?= e($d) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="row row--end">
          <a class="btn" href="<?= e(u('/')) ?>">Ver el sitio público</a>
          <a class="btn btn--primary" href="<?= e(u('/admin/entrar')) ?>">Entrar al panel</a>
        </div>
      </div>
    <?php endif; ?>

  </div>
</main>
