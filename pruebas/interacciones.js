/* Lo que solo se puede comprobar en un navegador de verdad.

   extremo-a-extremo.php verifica lo que responde el servidor; esto verifica lo
   que pasa después: si las pestañas cambian de panel, si la ficha se abre en un
   diálogo sin recargar, si el lector de QR decodifica el código que la propia
   plataforma pinta, y si la portada del celular queda en una sola columna.

   Uso:  node pruebas/interacciones.js [http://127.0.0.1:8900/cumbreAI]

   Requiere la plataforma instalada y sirviéndose:
     BASE=/cumbreAI php -S 127.0.0.1:8900 -t . pruebas/servidor.php &
     php pruebas/extremo-a-extremo.php     (deja los datos de prueba) */

const { chromium } = require('playwright');
const fs = require('fs');

const EJECUTABLE = [
  '/opt/pw-browsers/chromium/chrome-linux/chrome',
  '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
].find(r => { try { return fs.existsSync(r); } catch (e) { return false; } });
const LANZAR = EJECUTABLE ? { executablePath: EJECUTABLE } : {};

const BASE = (process.argv[2] || 'http://127.0.0.1:8900/cumbreAI').replace(/\/$/, '');
const ADMIN = { correo: 'aerazo@narino.gov.co', clave: 'una frase larga y facil de recordar' };

/* Para entrar como asistente hace falta su QR de acceso: no se puede pedir un
   código por correo desde aquí. extremo-a-extremo.php lo deja escrito al
   terminar, en la carpeta de salidas de las pruebas. */
const RUTA_TOKEN = 'pruebas/capturas/token-asistente.txt';
const TOKEN = (() => {
  try { return fs.readFileSync(RUTA_TOKEN, 'utf8').trim(); } catch (e) { return ''; }
})();

let ok = 0;
const fallos = [];

function comprobar(nombre, condicion, extra = '') {
  if (condicion) {
    ok++;
    console.log('  ✓ ' + nombre);
  } else {
    fallos.push(nombre);
    console.log('  ✗ ' + nombre + (extra ? '  → ' + extra : ''));
  }
}

function titulo(t) {
  console.log('\n' + t + '\n' + '─'.repeat(58));
}

(async () => {
  const navegador = await chromium.launch(LANZAR);

  /* =====================================================================
     Portada en un teléfono
     ===================================================================== */
  titulo('Portada en 390 px');

  const movil = await navegador.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
  });
  const p1 = await movil.newPage();
  const erroresPortada = [];
  p1.on('console', m => { if (m.type() === 'error') erroresPortada.push(m.text()); });

  await p1.goto(BASE + '/', { waitUntil: 'networkidle' });

  const columnas = await p1.evaluate(() => {
    const split = document.querySelector('.split--balanced');
    if (!split) return null;
    const hijos = Array.from(split.children).map(el => Math.round(el.getBoundingClientRect().left));
    return { columnas: getComputedStyle(split).gridTemplateColumns, izquierdas: hijos };
  });
  comprobar('la portada usa .split--balanced', columnas !== null);
  comprobar('en el celular queda en una sola columna',
    columnas && !/\s/.test(columnas.columnas.trim()), columnas && columnas.columnas);
  comprobar('las dos secciones quedan una debajo de otra',
    columnas && new Set(columnas.izquierdas).size === 1,
    columnas && JSON.stringify(columnas.izquierdas));

  const anchoBoton = await p1.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button, .btn'))
      .find(el => /preregistrarme|registrarme/i.test(el.textContent.trim()));
    return b ? Math.round(b.getBoundingClientRect().width) : 0;
  });
  comprobar('el botón principal ocupa el ancho de la tarjeta', anchoBoton > 250, String(anchoBoton));

  comprobar('«Entrar y ver mi carnet» es un botón',
    (await p1.locator('a.btn', { hasText: 'Entrar y ver mi carnet' }).count()) === 1);

  comprobar('sin errores de consola en la portada', erroresPortada.length === 0,
    erroresPortada.slice(0, 2).join(' | '));

  /* =====================================================================
     El editor de la fotografía
     ---------------------------------------------------------------------
     La prueba de verdad no es que el editor se vea: es que el recorte que
     elige la persona sea el que acaba en el carnet. Se sube una imagen con
     una esquina roja inconfundible, se encuadra esa esquina, se guarda, y se
     lee el color del centro de la foto que sirve el servidor.
     ===================================================================== */
  titulo('Editor de la fotografía');

  if (!TOKEN) {
    console.log('  · sin ' + RUTA_TOKEN + ': ejecuta antes php pruebas/extremo-a-extremo.php');
    fallos.push('no se pudo entrar como asistente');
  } else {
    const p2 = await movil.newPage();
    const erroresFoto = [];
    p2.on('console', m => { if (m.type() === 'error') erroresFoto.push(m.text()); });

    await p2.goto(BASE + '/entrar/qr/' + TOKEN, { waitUntil: 'networkidle' });
    await p2.goto(BASE + '/preregistro', { waitUntil: 'networkidle' });

    comprobar('el campo de archivo queda oculto tras el botón',
      await p2.locator('[data-foto-campo]').isHidden());
    comprobar('el editor todavía no aparece',
      await p2.locator('[data-foto-mandos]').isHidden());

    // Imagen de prueba: 900×600, esquina superior izquierda roja de 150 px.
    const ruta = 'pruebas/capturas/foto-de-prueba.png';
    fs.writeFileSync(ruta, Buffer.from(await p2.evaluate(() => {
      const l = document.createElement('canvas');
      l.width = 900; l.height = 600;
      const c = l.getContext('2d');
      c.fillStyle = '#f0f0f0'; c.fillRect(0, 0, 900, 600);
      c.fillStyle = '#dc1e1e'; c.fillRect(0, 0, 150, 150);
      return l.toDataURL('image/png').split(',')[1];
    }), 'base64'));

    await p2.setInputFiles('#foto', ruta);
    await p2.waitForSelector('[data-foto-lienzo]:not([hidden])', { timeout: 5000 });

    comprobar('al elegir una foto aparece el editor',
      await p2.locator('[data-foto-mandos]').isVisible());
    comprobar('y el botón de descartar',
      await p2.locator('[data-foto-descartar]').isVisible());

    const inicial = await p2.evaluate(() => ({
      x: document.querySelector('[data-foto-x]').value,
      y: document.querySelector('[data-foto-y]').value,
      lado: document.querySelector('[data-foto-lado]').value,
      ancho: document.querySelector('[data-foto-ancho]').value,
      alto: document.querySelector('[data-foto-alto]').value,
    }));
    comprobar('el encuadre se publica en el formulario',
      inicial.lado === '600' && inicial.ancho === '900' && inicial.alto === '600',
      JSON.stringify(inicial));

    // Acercar: el lado del cuadro tiene que encogerse.
    await p2.locator('[data-foto-zoom]').fill('300');
    await p2.locator('[data-foto-zoom]').dispatchEvent('input');
    const acercado = await p2.evaluate(() =>
      Number(document.querySelector('[data-foto-lado]').value));
    comprobar('la barra de zoom encoge el cuadro', acercado === 200, String(acercado));

    // Arrastrar hasta la esquina superior izquierda. Se hace en varios gestos
    // cortos y no en uno largo: el ratón no puede salirse de la ventana, y en
    // un teléfono de 390 px un solo arrastre no alcanza a recorrer la foto.
    const antesDeArrastrar = await p2.evaluate(() =>
      Number(document.querySelector('[data-foto-x]').value));

    const caja = await p2.locator('[data-foto-visor]').boundingBox();
    const desde = { x: caja.x + 20, y: caja.y + 20 };
    for (let gesto = 0; gesto < 5; gesto++) {
      await p2.mouse.move(desde.x, desde.y);
      await p2.mouse.down();
      for (let i = 1; i <= 6; i++) {
        await p2.mouse.move(desde.x + i * 30, desde.y + i * 30);
      }
      await p2.mouse.up();
    }

    const arrastrado = await p2.evaluate(() => ({
      x: Number(document.querySelector('[data-foto-x]').value),
      y: Number(document.querySelector('[data-foto-y]').value),
    }));
    comprobar('arrastrar lleva el encuadre a la esquina superior izquierda',
      arrastrado.x === 0 && arrastrado.y === 0,
      'venía de x=' + antesDeArrastrar + ' y quedó en ' + JSON.stringify(arrastrado));

    // Guardar y comprobar qué quedó en el carnet.
    await p2.locator('button[type=submit]', { hasText: 'Guardar cambios' }).click();
    await p2.waitForLoadState('networkidle');
    comprobar('se guarda y vuelve al carnet',
      (await p2.locator('h1').first().innerText()).includes('carnet'),
      p2.url());

    const color = await p2.evaluate(async () => {
      const img = document.querySelector('.carnet__photo img');
      if (!img) return null;
      await img.decode();
      const l = document.createElement('canvas');
      l.width = l.height = 8;
      const c = l.getContext('2d');
      c.drawImage(img, 0, 0, 8, 8);
      const d = c.getImageData(4, 4, 1, 1).data;
      return [d[0], d[1], d[2]];
    });
    comprobar('la foto quedó en el carnet', color !== null);
    comprobar('y el recorte es el que eligió la persona, no el centro',
      color && color[0] > 150 && color[1] < 110 && color[2] < 110,
      color ? color.join(',') : 'sin imagen');

    comprobar('sin errores de consola en el editor', erroresFoto.length === 0,
      erroresFoto.slice(0, 2).join(' | '));

    fs.unlinkSync(ruta);
  }

  /* =====================================================================
     El formulario de registro
     ===================================================================== */
  titulo('Formulario de registro');

  // Contexto limpio: las páginas anteriores dejaron abierta la sesión de María
  // y con sesión estas dos pantallas son otra cosa —«/entrar» lleva al carnet y
  // el registro enseña sus datos—. Lo que se está probando aquí es lo que ve
  // alguien de fuera.
  const anonimo = await navegador.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  const p4 = await anonimo.newPage();
  const erroresRegistro = [];
  p4.on('console', m => { if (m.type() === 'error') erroresRegistro.push(m.text()); });
  await p4.goto(BASE + '/registro', { waitUntil: 'networkidle' });

  comprobar('la caracterización no se ve al abrir',
    await p4.locator('#bloque-opcional').isHidden());

  const rotulo = (await p4.locator('[data-plegar="bloque-opcional"]').innerText()).trim();
  comprobar('y su botón invita a mostrarla', /mostrar/i.test(rotulo), rotulo);

  await p4.locator('[data-plegar="bloque-opcional"]').click();
  comprobar('al pulsarlo se abre', await p4.locator('#bloque-opcional').isVisible());
  comprobar('y el botón pasa a decir «Ocultar»',
    /ocultar/i.test((await p4.locator('[data-plegar="bloque-opcional"]').innerText()).trim()));

  comprobar('el formulario completo está abierto al público sin sesión',
    (await p4.locator('#documento').count()) === 1
    && (await p4.locator('button[type=submit]').count()) >= 1);

  comprobar('sin errores de consola en el registro', erroresRegistro.length === 0,
    erroresRegistro.slice(0, 2).join(' | '));

  /* =====================================================================
     Crear el acceso con correo y contraseña
     ===================================================================== */
  titulo('Crear el acceso');

  await p4.goto(BASE + '/entrar', { waitUntil: 'networkidle' });
  comprobar('la pantalla de ingreso ofrece crear el acceso como botón',
    (await p4.locator('a.btn[href*="/entrar/crear"]').count()) === 1);

  await p4.locator('a.btn[href*="/entrar/crear"]').first().click();
  await p4.waitForLoadState('networkidle');
  comprobar('el formulario corto tiene tres campos y la autorización',
    (await p4.locator('#correo').count()) === 1
    && (await p4.locator('#clave').count()) === 1
    && (await p4.locator('#clave2').count()) === 1
    && (await p4.locator('#habeas').count()) === 1);

  /* =====================================================================
     Panel: pestañas y ficha
     ===================================================================== */
  titulo('Panel');

  const escritorio = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const p3 = await escritorio.newPage();
  const erroresPanel = [];
  p3.on('console', m => { if (m.type() === 'error') erroresPanel.push(m.text()); });

  await p3.goto(BASE + '/admin/entrar', { waitUntil: 'networkidle' });
  await p3.fill('#correo', ADMIN.correo);
  await p3.fill('#clave', ADMIN.clave);
  await p3.click('button[type=submit]');
  await p3.waitForLoadState('networkidle');

  /* ---------------------------------------------------------------------
     El lector de QR, sobre un código que pinta la propia plataforma
     ---------------------------------------------------------------------
     Se hace en /admin/escaner porque esa pantalla ya carga qr-lector.js: la
     política de seguridad de la plataforma no admite guiones inyectados, y
     está bien que no los admita.                                          */
  titulo('Lector de QR en el navegador');

  // Qué jornada existe. No se da por hecho que sea la 1: las pruebas de
  // eliminación de jornadas corren antes y el evento puede haber quedado con
  // otros números, que es justamente lo que pasa en un evento real al que se
  // le quita un día.
  await p3.goto(BASE + '/admin/qr-dias', { waitUntil: 'networkidle' });
  const jornada = await p3.evaluate(() => {
    const a = document.querySelector('a[href*="/qr-dias/"][href$="/imprimir"]');
    const m = a && a.getAttribute('href').match(/qr-dias\/(\d+)\/imprimir/);
    return m ? m[1] : '';
  });
  comprobar('el panel tiene al menos una jornada con código', jornada !== '', jornada);

  await p3.goto(BASE + '/admin/escaner', { waitUntil: 'networkidle' });

  const leido = await p3.evaluate(async ([base, dia]) => {
    const salida = { hayLector: !!window.LectorQr, svg: '', leido: null };
    if (!window.LectorQr) return salida;

    const svg = await (await fetch(base + '/medios/qr/dia/' + dia + '.svg', {
      credentials: 'same-origin'
    })).text().catch(() => '');
    salida.svg = svg.slice(0, 4);
    if (!svg.startsWith('<svg')) return salida;

    const imagen = await new Promise((resolver, rechazar) => {
      const blob = new Blob([svg], { type: 'image/svg+xml' });
      const url = URL.createObjectURL(blob);
      const img = new Image();
      img.onload = () => {
        const lienzo = document.createElement('canvas');
        lienzo.width = 420;
        lienzo.height = 420;
        const cx = lienzo.getContext('2d');
        cx.fillStyle = '#fff';
        cx.fillRect(0, 0, 420, 420);
        // Con margen alrededor, como cuando se fotografía un pliego pegado.
        cx.drawImage(img, 34, 34, 352, 352);
        URL.revokeObjectURL(url);
        resolver(cx.getImageData(0, 0, 420, 420));
      };
      img.onerror = () => { URL.revokeObjectURL(url); rechazar(new Error('svg')); };
      img.src = url;
    });

    try { salida.leido = window.LectorQr.desdeImagen(imagen); }
    catch (e) { salida.leido = 'ERROR: ' + e.message; }
    return salida;
  }, [BASE, jornada]);

  comprobar('el escáner carga el lector propio', leido.hayLector === true);
  comprobar('el servidor entrega el SVG del código del día', leido.svg === '<svg', leido.svg);
  comprobar('y el lector decodifica ese mismo código sobre un lienzo',
    typeof leido.leido === 'string' && leido.leido.includes('/d/'),
    String(leido.leido).slice(0, 70));

  comprobar('el escáner ofrece el «Lector desde cámara»',
    (await p3.locator('[data-escaner-foto]').count()) === 1);

  // ---- Pestañas de autenticación ----
  await p3.goto(BASE + '/admin/autenticacion', { waitUntil: 'networkidle' });

  const visiblesAlAbrir = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('solo se ve un panel al abrir', visiblesAlAbrir.length === 1,
    JSON.stringify(visiblesAlAbrir));

  await p3.click('[data-pestana="panel-whatsapp"]');
  const trasClic = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('al pulsar «WhatsApp» se cambia de panel',
    trasClic.length === 1 && trasClic[0] === 'panel-whatsapp', JSON.stringify(trasClic));

  comprobar('el campo de WhatsApp queda visible y utilizable',
    await p3.locator('#wa-cuenta').isVisible());

  // Con el teclado también: flecha derecha pasa a la siguiente.
  await p3.locator('[data-pestana="panel-whatsapp"]').focus();
  await p3.keyboard.press('ArrowRight');
  const conTeclado = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('las flechas del teclado también cambian de pestaña',
    conTeclado[0] === 'panel-sms', JSON.stringify(conTeclado));

  // ---- Ficha de una persona ----
  await p3.goto(BASE + '/admin/registros', { waitUntil: 'networkidle' });

  const antesDeAbrir = p3.url();
  // Se abre la de una persona concreta, no «la primera de la tabla»: el orden
  // del listado depende de los filtros y la prueba tiene que saber a quién mira.
  await p3.locator('[data-ficha][aria-label*="Zambrano"]').first().click();
  await p3.waitForSelector('#modal-ficha [data-ficha-contenido]', { timeout: 5000 });

  comprobar('la ficha se abre sin salir de la tabla', p3.url() === antesDeAbrir, p3.url());
  comprobar('el diálogo muestra el nombre de la persona',
    (await p3.locator('#modal-ficha').innerText()).includes('Zambrano'),
    (await p3.locator('#modal-ficha').innerText()).slice(0, 90).replace(/\n/g, ' | '));
  comprobar('y dice que la contraseña no se puede ver',
    (await p3.locator('#modal-ficha').innerText()).includes('no se puede ver'));

  await p3.keyboard.press('Escape');
  comprobar('se cierra con Escape',
    await p3.locator('#modal-ficha').isHidden());

  comprobar('sin errores de consola en el panel', erroresPanel.length === 0,
    erroresPanel.slice(0, 2).join(' | '));

  /* =====================================================================
     Eventos: editar, desactivar y eliminar
     ===================================================================== */
  titulo('Eventos');

  await p3.goto(BASE + '/admin/eventos', { waitUntil: 'networkidle' });

  comprobar('cada evento tiene botón de editar',
    (await p3.locator('[data-abrir-modal^="modal-editar-"]').count()) >= 1);
  comprobar('y de eliminar',
    (await p3.locator('[data-abrir-modal^="modal-borrar-"]').count()) >= 1);

  await p3.locator('[data-abrir-modal^="modal-editar-"]').first().click();
  await p3.waitForTimeout(200);
  comprobar('el diálogo de editar se abre con los datos puestos',
    (await p3.locator('.modal:not([hidden]) input[name=nombre]').inputValue()).length > 3);
  comprobar('y ofrece mover las jornadas con la fecha',
    await p3.locator('.modal:not([hidden]) input[name=mover_jornadas]').isVisible());
  await p3.keyboard.press('Escape');

  await p3.locator('[data-abrir-modal^="modal-borrar-"]').first().click();
  await p3.waitForTimeout(200);
  // innerText devuelve el texto como se ve, y la hoja de estilos pone los
  // rótulos en mayúsculas: la comprobación no puede depender de eso.
  const textoBorrar = (await p3.locator('.modal:not([hidden])').innerText()).toLowerCase();
  comprobar('el de eliminar dice qué se pierde',
    textoBorrar.includes('personas registradas') && textoBorrar.includes('ingresos sellados'),
    textoBorrar.slice(0, 90).replace(/\n/g, ' | '));
  comprobar('y exige escribir ELIMINAR',
    await p3.locator('.modal:not([hidden]) input[name=confirmacion]').isVisible());
  await p3.keyboard.press('Escape');

  /* =====================================================================
     QR por día: agregar y eliminar
     ===================================================================== */
  titulo('Jornadas');

  await p3.goto(BASE + '/admin/qr-dias', { waitUntil: 'networkidle' });
  comprobar('la tarjeta de agregar un día está a la vista',
    await p3.locator('form[action$="/qr-dias/agregar"]').isVisible());
  comprobar('cada jornada ofrece cambiar fecha y horario',
    (await p3.locator('form[action$="/qr-dias/ajustar"]').count()) >= 1);

  await navegador.close();

  console.log('\n' + '─'.repeat(58));
  console.log(ok + ' comprobaciones correctas · ' + fallos.length + ' fallidas');
  fallos.forEach(f => console.log('  ✗ ' + f));
  process.exit(fallos.length ? 1 : 0);
})();
