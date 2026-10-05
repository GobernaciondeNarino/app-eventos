/* Carga las pantallas en un navegador real, a varios anchos, y verifica que
   no haya errores de consola, que nada desborde en horizontal y que los
   objetivos táctiles tengan un tamaño usable.

   Uso:  node pruebas/pantallas.js [http://127.0.0.1:8900/cumbreAI]
         ANCHO=390 node pruebas/pantallas.js

   Requiere la plataforma instalada y sirviéndose (ver pruebas/extremo-a-extremo.php).
   Las capturas quedan en pruebas/capturas/. */

const { chromium } = require('playwright');
const fs = require('fs');

/* El navegador que trae el entorno puede no ser el que espera esta versión de
   Playwright. Si hay uno instalado en /opt/pw-browsers se usa ese, en vez de
   pedir una descarga que en un servidor sin salida a internet no va a ocurrir. */
const EJECUTABLE = [
  '/opt/pw-browsers/chromium/chrome-linux/chrome',
  '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
].find(r => { try { return fs.existsSync(r); } catch (e) { return false; } });
const LANZAR = EJECUTABLE ? { executablePath: EJECUTABLE } : {};
const path = require('path');

const BASE = (process.argv[2] || 'http://127.0.0.1:8900/cumbreAI').replace(/\/$/, '');
const ANCHO = Number(process.env.ANCHO || 1440);

// Credenciales que deja la prueba de extremo a extremo.
const ADMIN = { correo: 'aerazo@narino.gov.co', clave: 'una frase larga y facil de recordar' };
const ASISTENTE = 'mzambrano@narino.gov.co';

const PUBLICAS = ['/', '/registro', '/entrar/crear', '/agenda', '/entrar', '/admin/entrar'];
const ASISTENTE_RUTAS = ['/carnet', '/checkin', '/contactos'];
const ADMIN_RUTAS = ['/admin', '/admin/escaner', '/admin/registros', '/admin/qr-dias',
                     '/admin/expositores', '/admin/organizadores', '/admin/eventos', '/admin/identidad', '/admin/autenticacion',
                     '/admin/cuenta', '/admin/configuracion/registro'];

let fallos = 0;

async function revisar(page, ruta, etiqueta) {
  const errores = [];
  const onConsola = m => { if (m.type() === 'error') errores.push('consola: ' + m.text()); };
  const onError = e => errores.push('excepción: ' + e.message);
  const onFallo = r => errores.push('petición fallida: ' + r.url());

  page.on('console', onConsola);
  page.on('pageerror', onError);
  page.on('requestfailed', onFallo);

  const resp = await page.goto(BASE + ruta, { waitUntil: 'networkidle', timeout: 20000 })
    .catch(e => { errores.push('goto: ' + e.message); return null; });
  await page.waitForTimeout(350);

  const info = await page.evaluate(() => {
    const chicos = Array.from(document.querySelectorAll('a.btn, button.btn, .navlink, .tabbar__btn'))
      .filter(el => el.offsetParent !== null)
      .filter(el => el.getBoundingClientRect().height < 32).length;

    // Un campo de escritura con menos de 16px hace que iOS acerque la página al
    // enfocarlo. Las casillas y los radios no lo provocan, así que no cuentan.
    const escribibles = 'input[type=text], input[type=email], input[type=tel], input[type=number],'
      + ' input[type=password], input[type=search], input[type=date], input[type=time],'
      + ' input:not([type]), select, textarea';
    const camposChicos = Array.from(document.querySelectorAll(escribibles))
      .filter(el => el.offsetParent !== null && !el.classList.contains('sr-only'))
      .filter(el => parseFloat(getComputedStyle(el).fontSize) < 16).length;

    return {
      titulo: document.title,
      bloques: (document.querySelector('main') || {}).childElementCount || 0,
      alto: document.body.scrollHeight,
      desborde: document.documentElement.scrollWidth > window.innerWidth + 2,
      chicos,
      camposChicos,
    };
  });

  const nombre = (etiqueta + ruta).replace(/[^\w]+/g, '_').replace(/^_|_$/g, '') || 'inicio';
  await page.screenshot({
    path: path.join(__dirname, 'capturas', ANCHO + '-' + nombre + '.png'),
    fullPage: true,
  });

  const zoomIos = ANCHO < 700 && info.camposChicos > 0;
  const mal = errores.length || info.desborde || info.bloques === 0 || zoomIos;
  if (mal) fallos++;

  console.log(
    (resp && resp.ok() ? '·' : '!') + ' ' + (etiqueta + ' ' + ruta).padEnd(30)
    + ' bloques:' + info.bloques
    + ' alto:' + String(info.alto).padStart(5)
    + ' desbordeH:' + (info.desborde ? 'SÍ' : 'no')
    + (ANCHO < 700 ? ' zoomIOS:' + (zoomIos ? 'SÍ' : 'no') : '')
    + (info.chicos ? ' táctilesChicos:' + info.chicos : '')
  );
  errores.slice(0, 4).forEach(e => console.log('     ' + e));

  page.off('console', onConsola);
  page.off('pageerror', onError);
  page.off('requestfailed', onFallo);
}

(async () => {
  const navegador = await chromium.launch(LANZAR);
  console.log('Ancho ' + ANCHO + 'px · ' + BASE + '\n');

  // ---- Público ----
  const ctxPublico = await navegador.newContext({
    viewport: { width: ANCHO, height: 900 },
    isMobile: ANCHO < 700,
    hasTouch: ANCHO < 700,
  });
  const pub = await ctxPublico.newPage();
  for (const ruta of PUBLICAS) await revisar(pub, ruta, 'público');

  // ---- Asistente ----
  await pub.goto(BASE + '/entrar', { waitUntil: 'networkidle' });
  await pub.fill('#correo', ASISTENTE);
  await pub.click('button[type=submit]');
  const pidioCodigo = await pub.locator('#codigo').count() > 0;
  console.log('\n  (el acceso del asistente pide código por correo: ' + (pidioCodigo ? 'sí' : 'no') + ')\n');
  await ctxPublico.close();

  // ---- Equipo organizador ----
  const ctxAdmin = await navegador.newContext({
    viewport: { width: ANCHO, height: 900 },
    isMobile: ANCHO < 700,
    hasTouch: ANCHO < 700,
  });
  const adm = await ctxAdmin.newPage();
  await adm.goto(BASE + '/admin/entrar', { waitUntil: 'networkidle' });
  await adm.fill('#correo', ADMIN.correo);
  await adm.fill('#clave', ADMIN.clave);
  await adm.click('button[type=submit]');
  await adm.waitForLoadState('networkidle');

  // Llegar a una URL bajo /admin no basta: /admin/verificar y /admin/activar-2fa
  // también lo son, y quedándose ahí este guion revisaba ocho veces la misma
  // pantalla creyendo que revisaba el backoffice entero. Se comprueba que el
  // panel esté de verdad delante.
  await adm.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  const entro = /\/admin\/?$/.test(new URL(adm.url()).pathname);
  console.log((entro ? '·' : '!') + ' el equipo entró: ' + (entro ? 'sí' : 'NO — ' + adm.url()) + '\n');
  if (!entro) {
    console.log('  El acceso no llegó al panel. Si pide el segundo factor, quítalo con:');
    console.log('    php herramientas/cuenta.php sin-2fa --correo=' + ADMIN.correo + '\n');
    process.exitCode = 1;
  }
  if (!entro) fallos++;

  for (const ruta of ADMIN_RUTAS) await revisar(adm, ruta, 'admin');
  await ctxAdmin.close();

  await navegador.close();
  console.log('\n' + (fallos ? fallos + ' pantalla(s) con problemas' : 'Todas las pantallas se ven limpias'));
  process.exit(fallos ? 1 : 0);
})();
