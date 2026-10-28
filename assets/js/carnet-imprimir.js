/* =========================================================================
   carnet-imprimir.js — El carnet para imprimir o descargar (/carnet/imprimir).
   -------------------------------------------------------------------------
   Dos cosas:

   1. Ajusta cada texto del carnet a su caja: baja la letra hasta que quepa en
      sus renglones. Sin JavaScript el carnet se ve igual de bien en casi todos
      los casos —la vista elige la talla de partida según el largo— y lo que no
      cabe se corta con «…»; con él, un nombre largo se ve entero.

   2. «Descargar imágenes»: dibuja cada cara en un lienzo con el mismo diseño
      —medidas, letra, colores, foto, logo y código— y baja dos PNG de
      6 × 9,5 cm a 300 ppp, frente y reverso. Es lo que pide una imprenta, y
      evita los tropiezos de imprimir desde el navegador.

   Las imágenes se preparan apenas carga la página, para que al pulsar el botón
   las descargas salgan en el mismo clic: los navegadores frenan las que llegan
   después. Si aun así solo baja una, el panel deja un enlace para cada cara.
   ========================================================================= */
(function () {
  'use strict';

  // El carnet de una persona (/carnet/imprimir) o la tanda del equipo
  // (/carnets/imprimir): las mismas caras, una o trescientas.
  var raiz = document.querySelector('.impreso');
  if (!raiz) return;

  /* =====================================================================
     1. Ajustar los textos
     ===================================================================== */
  var PX_POR_PT = 96 / 72;

  // Desde aquí mide el guion: fuera el recorte por renglones del CSS, que
  // además esconde a scrollHeight lo que sobra (ver components.css).
  raiz.classList.add('impreso--ajustado');

  function cabe(el) {
    return el.scrollHeight <= el.clientHeight + 0.5 && el.scrollWidth <= el.clientWidth + 0.5;
  }

  /** Corta el texto por palabras —y si hace falta por letras— hasta que quepa, con «…». */
  function recortar(el) {
    var palabras = el.textContent.split(/\s+/).filter(Boolean);
    while (palabras.length > 1 && !cabe(el)) {
      palabras.pop();
      el.textContent = palabras.join(' ').replace(/[\s,.;:·-]+$/, '') + '…';
    }
    var letras = Array.from(el.textContent.replace(/…$/, ''));
    while (letras.length > 1 && !cabe(el)) {
      letras.pop();
      el.textContent = letras.join('') + '…';
    }
  }

  /**
   * Baja la letra de a cuarto de punto hasta que el texto quepa, sin pasar del
   * mínimo, y si ni así cabe lo corta. Parte de la talla grande, como las
   * imágenes de descarga: las clases «is-largo» son para cuando no hay
   * JavaScript que mida. El texto completo se guarda: la letra del tema puede
   * llegar después y hay que volver a medir desde el principio.
   */
  function ajustar(el) {
    if (!el.hasAttribute('data-texto')) el.setAttribute('data-texto', el.textContent.trim());
    el.textContent = el.getAttribute('data-texto');
    el.removeAttribute('title');
    el.classList.remove('is-largo', 'is-muy-largo');
    el.style.fontSize = '';
    var minimo = (parseFloat(el.getAttribute('data-ajustar')) || 5) * PX_POR_PT;
    var tamano = parseFloat(window.getComputedStyle(el).fontSize);
    while (!cabe(el) && tamano > minimo) {
      tamano = Math.max(minimo, tamano - 0.25 * PX_POR_PT);
      el.style.fontSize = tamano + 'px';
    }
    if (!cabe(el)) {
      recortar(el);
      el.title = el.getAttribute('data-texto');
    }
  }

  /**
   * Cada dato cabe en sus renglones, pero los tres juntos tienen que caber
   * además en lo que queda de la cara: si no, se bajan todos un poco más, y
   * como último recurso se corta la entidad.
   */
  function ajustarDatos(caja) {
    var valores = Array.prototype.slice.call(caja.querySelectorAll('.impreso__v'));
    for (var vuelta = 0; vuelta < 60 && caja.scrollHeight > caja.clientHeight + 0.5; vuelta++) {
      var bajo = false;
      valores.forEach(function (v) {
        var minimo = (parseFloat(v.getAttribute('data-ajustar')) || 5) * PX_POR_PT;
        var tamano = parseFloat(window.getComputedStyle(v).fontSize);
        if (tamano > minimo) {
          v.style.fontSize = Math.max(minimo, tamano - 0.25 * PX_POR_PT) + 'px';
          bajo = true;
        }
      });
      if (!bajo) break;
    }
    var ultima = valores[valores.length - 1];
    if (ultima && caja.scrollHeight > caja.clientHeight + 0.5) {
      var palabras = ultima.textContent.replace(/…$/, '').split(/\s+/).filter(Boolean);
      while (palabras.length > 1 && caja.scrollHeight > caja.clientHeight + 0.5) {
        palabras.pop();
        ultima.textContent = palabras.join(' ') + '…';
      }
      ultima.title = ultima.getAttribute('data-texto') || '';
    }
  }

  function ajustarTodo() {
    Array.prototype.forEach.call(raiz.querySelectorAll('[data-ajustar]'), ajustar);
    Array.prototype.forEach.call(raiz.querySelectorAll('.impreso__datos'), ajustarDatos);
  }

  ajustarTodo();
  // Con la letra del tema ya cargada las medidas cambian: se ajusta otra vez.
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(ajustarTodo);
  }

  /* =====================================================================
     2. Las imágenes para descargar
     ===================================================================== */
  var boton = document.querySelector('[data-descargar-carnet]');
  var panel = document.querySelector('[data-imagenes-carnet]');
  var fuenteDatos = document.getElementById('carnet-datos');
  if (!boton || !panel || !fuenteDatos) return;

  var datos;
  try {
    datos = JSON.parse(fuenteDatos.textContent);
  } catch (e) {
    boton.hidden = true;
    return;
  }

  var lista = panel.querySelector('[data-imagenes-lista]');
  var estado = panel.querySelector('[data-imagenes-estado]');

  // 300 ppp: una cara de 60 × 95 mm sale de 709 × 1122 píxeles.
  var PPP = 300;
  var K = PPP / 25.4;            // píxeles por milímetro
  var PT = K * 25.4 / 72;        // píxeles por punto
  var mm = function (x) { return x * K; };

  var ANCHO = 60, ALTO = 95, MARGEN = 4.5, HUECO = 2.5;
  var TEXTO = '#0B1B24', GRIS = '#56656F', BORDE = '#C4C4C4';

  var colores = datos.colores || {};
  var TINTA = colores.tinta || '#0B5F75';
  var TINTA_ROL = colores.rol || TINTA;
  var SUAVE = colores.suave || '#CBE0E8';

  // La letra del tema: la misma que usa la página.
  var familia = function (selector, respaldo) {
    var el = raiz.querySelector(selector);
    return el ? window.getComputedStyle(el).fontFamily : respaldo;
  };
  var DISPLAY = familia('.impreso__v', 'system-ui, sans-serif');
  var MONO = familia('.impreso__k', 'ui-monospace, monospace');

  var ESTILOS = {
    evento:    { familia: DISPLAY, peso: 700, espaciado: 0.06, renglon: 1.2 },
    sub:       { familia: MONO, peso: 400, espaciado: 0.1, renglon: 1.2 },
    inicial:   { familia: DISPLAY, peso: 700, espaciado: 0, renglon: 1 },
    iniciales: { familia: DISPLAY, peso: 700, espaciado: 0.08, renglon: 1 },
    rol:       { familia: DISPLAY, peso: 700, espaciado: 0.14, renglon: 1.2 },
    etiqueta:  { familia: MONO, peso: 400, espaciado: 0.2, renglon: 1.2 },
    valor:     { familia: DISPLAY, peso: 700, espaciado: 0.03, renglon: 1.2 },
    gris:      { familia: DISPLAY, peso: 700, espaciado: 0.03, renglon: 1.2 },
    leyenda:   { familia: MONO, peso: 400, espaciado: 0.18, renglon: 1.2 },
    codigo:    { familia: MONO, peso: 400, espaciado: 0.2, renglon: 1.2 }
  };

  var mayus = function (t) { return String(t || '').toLocaleUpperCase('es-CO'); };

  /* ---- Texto ------------------------------------------------------------ */
  // Donde el navegador sabe espaciar letras se usa eso; donde no, se escribe
  // letra por letra. Las dos dan el mismo ancho.
  var espaciaSolo = typeof CanvasRenderingContext2D !== 'undefined'
    && 'letterSpacing' in CanvasRenderingContext2D.prototype;

  function fuente(ctx, estilo, px) {
    ctx.font = estilo.peso + ' ' + px + 'px ' + estilo.familia;
    if (espaciaSolo) ctx.letterSpacing = (estilo.espaciado * px) + 'px';
  }

  function medir(ctx, texto, estilo, px) {
    var letras = Array.from(texto);
    var espacio = estilo.espaciado * px;
    if (espaciaSolo) {
      return ctx.measureText(texto).width - (letras.length ? espacio : 0);
    }
    return ctx.measureText(texto).width + espacio * Math.max(0, letras.length - 1);
  }

  function escribir(ctx, texto, x, y, estilo, px) {
    if (espaciaSolo || !estilo.espaciado) {
      ctx.fillText(texto, x, y);
      return;
    }
    var espacio = estilo.espaciado * px;
    Array.from(texto).forEach(function (letra) {
      ctx.fillText(letra, x, y);
      x += ctx.measureText(letra).width + espacio;
    });
  }

  /** Reparte el texto en renglones de un ancho; una palabra más larga se parte. */
  function partir(ctx, texto, estilo, px, ancho) {
    var renglones = [];
    var actual = '';
    texto.split(/\s+/).filter(Boolean).forEach(function (palabra) {
      var prueba = actual ? actual + ' ' + palabra : palabra;
      if (medir(ctx, prueba, estilo, px) <= ancho) {
        actual = prueba;
        return;
      }
      if (actual) renglones.push(actual);
      actual = '';
      var letras = Array.from(palabra);
      while (letras.length && medir(ctx, letras.join(''), estilo, px) > ancho) {
        var corte = letras.length - 1;
        while (corte > 1 && medir(ctx, letras.slice(0, corte).join(''), estilo, px) > ancho) corte--;
        renglones.push(letras.slice(0, corte).join(''));
        letras = letras.slice(corte);
      }
      actual = letras.join('');
    });
    if (actual) renglones.push(actual);
    return renglones;
  }

  /**
   * La talla más grande, entre dos, con la que el texto cabe en sus renglones
   * —y en «altoMax», si la caja tiene un alto fijo—. Si ni con la menor cabe,
   * el último renglón termina en «…».
   */
  function encajar(ctx, texto, estilo, ancho, maxRenglones, desdePt, hastaPt, altoMax) {
    var pt, px, renglones;
    for (pt = desdePt; pt >= hastaPt - 0.001; pt -= 0.25) {
      px = pt * PT;
      fuente(ctx, estilo, px);
      renglones = partir(ctx, texto, estilo, px, ancho);
      if (renglones.length <= maxRenglones
          && (!altoMax || renglones.length * px * estilo.renglon <= altoMax)) {
        return { px: px, renglones: renglones };
      }
    }
    px = hastaPt * PT;
    fuente(ctx, estilo, px);
    renglones = partir(ctx, texto, estilo, px, ancho).slice(0, maxRenglones);
    var ultimo = renglones[renglones.length - 1] || '';
    while (ultimo.length > 1 && medir(ctx, ultimo + '…', estilo, px) > ancho) {
      ultimo = ultimo.slice(0, -1).replace(/\s+$/, '');
    }
    renglones[renglones.length - 1] = ultimo + '…';
    return { px: px, renglones: renglones };
  }

  function altoDe(bloque, estilo) {
    return bloque.renglones.length * bloque.px * estilo.renglon;
  }

  /** Pinta un bloque ya encajado desde «arriba»; alinea a la izquierda o al centro. */
  function pintar(ctx, bloque, estilo, x, arriba, ancho, centrado, color) {
    fuente(ctx, estilo, bloque.px);
    ctx.fillStyle = color;
    ctx.textBaseline = 'alphabetic';
    ctx.textAlign = 'left';
    var m = ctx.measureText('ÁÑHgjp');
    var sube = m.fontBoundingBoxAscent || bloque.px * 0.8;
    var baja = m.fontBoundingBoxDescent || bloque.px * 0.2;
    var renglon = bloque.px * estilo.renglon;
    bloque.renglones.forEach(function (linea, i) {
      var base = arriba + i * renglon + (renglon - (sube + baja)) / 2 + sube;
      var xi = centrado ? x + (ancho - medir(ctx, linea, estilo, bloque.px)) / 2 : x;
      escribir(ctx, linea, xi, base, estilo, bloque.px);
    });
    return renglon * bloque.renglones.length;
  }

  /* ---- Imágenes ---------------------------------------------------------- */
  function cargar(url) {
    return new Promise(function (listo) {
      if (!url) return listo(null);
      var img = new Image();
      img.onload = function () { listo(img); };
      img.onerror = function () { listo(null); };
      img.src = url;
    });
  }

  /** Dibuja la imagen dentro del recuadro: «cubrir» recorta, «contener» deja aire. */
  function encuadrar(ctx, img, x, y, w, h, cubrir) {
    var iw = img.naturalWidth || w, ih = img.naturalHeight || h;
    var escala = cubrir ? Math.max(w / iw, h / ih) : Math.min(w / iw, h / ih);
    var dw = iw * escala, dh = ih * escala;
    ctx.save();
    ctx.beginPath();
    ctx.rect(x, y, w, h);
    ctx.clip();
    ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    ctx.restore();
  }

  function lienzo() {
    var c = document.createElement('canvas');
    c.width = Math.round(mm(ANCHO));
    c.height = Math.round(mm(ALTO));
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, c.width, c.height);
    // Un filete gris en el borde, para recortar si se imprime en papel; tan
    // fino que en una tarjeta de plástico no se nota.
    ctx.strokeStyle = BORDE;
    ctx.lineWidth = mm(0.2);
    ctx.strokeRect(mm(0.1), mm(0.1), c.width - mm(0.2), c.height - mm(0.2));
    // El marco interior, como en la página.
    ctx.strokeStyle = SUAVE;
    ctx.strokeRect(mm(1.6), mm(1.6), c.width - mm(3.2), c.height - mm(3.2));
    return { canvas: c, ctx: ctx };
  }

  function marco(ctx, x, y, w, h, color) {
    ctx.strokeStyle = color;
    ctx.lineWidth = mm(0.3);
    ctx.strokeRect(mm(x + 0.15), mm(y + 0.15), mm(w - 0.3), mm(h - 0.3));
  }

  /* ---- Frente ------------------------------------------------------------- */
  function frente(logo, foto) {
    var l = lienzo(), ctx = l.ctx;
    var ancho = ANCHO - 2 * MARGEN;

    // Cabecera: el logo y el nombre del evento, centrados en sus 10,5 mm.
    var y = MARGEN;
    if (logo) {
      encuadrar(ctx, logo, mm(MARGEN), mm(y + 0.25), mm(10), mm(10), false);
    } else {
      marco(ctx, MARGEN, y + 0.25, 10, 10, TINTA);
      var ini = encajar(ctx, mayus(datos.inicial), ESTILOS.inicial, mm(9), 1, 14, 10);
      pintar(ctx, ini, ESTILOS.inicial, mm(MARGEN), mm(y + 5.25) - altoDe(ini, ESTILOS.inicial) / 2, mm(10), true, TINTA);
    }
    var xt = MARGEN + 12.5, wt = ANCHO - MARGEN - xt;
    var nombreEvento = encajar(ctx, mayus(datos.evento), ESTILOS.evento, mm(wt), 2, 6.8, 5);
    var sub = datos.dependencia ? encajar(ctx, mayus(datos.dependencia), ESTILOS.sub, mm(wt), 2, 4.5, 3.8) : null;
    var altoCabeza = altoDe(nombreEvento, ESTILOS.evento) + (sub ? mm(0.5) + altoDe(sub, ESTILOS.sub) : 0);
    var yt = mm(y + 5.25) - altoCabeza / 2;
    yt += pintar(ctx, nombreEvento, ESTILOS.evento, mm(xt), yt, mm(wt), false, TEXTO);
    if (sub) pintar(ctx, sub, ESTILOS.sub, mm(xt), yt + mm(0.5), mm(wt), false, GRIS);
    y += 10.5 + HUECO;

    // La foto, o las iniciales.
    var lado = 32, xf = (ANCHO - lado) / 2;
    marco(ctx, xf, y, lado, lado, TINTA);
    var dentro = 1.1;
    if (foto) {
      encuadrar(ctx, foto, mm(xf + dentro), mm(y + dentro), mm(lado - 2 * dentro), mm(lado - 2 * dentro), true);
    } else {
      ctx.fillStyle = SUAVE;
      ctx.fillRect(mm(xf + dentro), mm(y + dentro), mm(lado - 2 * dentro), mm(lado - 2 * dentro));
      var iniciales = encajar(ctx, mayus(datos.iniciales), ESTILOS.iniciales, mm(lado - 6), 1, 22, 12);
      pintar(ctx, iniciales, ESTILOS.iniciales, mm(xf), mm(y + lado / 2) - altoDe(iniciales, ESTILOS.iniciales) / 2, mm(lado), true, TINTA);
    }
    y += lado + HUECO;

    // El perfil, en su banda de color.
    ctx.fillStyle = TINTA_ROL;
    ctx.fillRect(mm(MARGEN), mm(y), mm(ancho), mm(6.5));
    var rol = encajar(ctx, mayus(datos.rol), ESTILOS.rol, mm(ancho - 4), 2, 10.5, 5.5, mm(6.5));
    pintar(ctx, rol, ESTILOS.rol, mm(MARGEN + 2), mm(y + 3.25) - altoDe(rol, ESTILOS.rol) / 2, mm(ancho - 4), true, '#FFFFFF');
    y += 6.5 + HUECO;

    // Nombre, identificación y entidad, cada uno con su filete a la izquierda.
    // Los tres juntos tienen que caber en lo que queda: si no, bajan un poco.
    var xd = MARGEN + 0.3 + 2, wd = ANCHO - MARGEN - xd;
    var campos = [
      ['Nombre', datos.nombre, 2, 10, 6],
      ['Identificación', datos.identificacion, 1, 9, 5.5],
      ['Entidad', datos.entidad, 2, 8, 5.5]
    ];
    var disponible = mm(ALTO - MARGEN - y);
    var bloques, total, menos = 0;
    do {
      total = mm(1.5) * (campos.length - 1);
      bloques = campos.map(function (campo) {
        var b = {
          etiqueta: encajar(ctx, mayus(campo[0]), ESTILOS.etiqueta, mm(wd), 1, 4.6, 4.6),
          valor: encajar(ctx, mayus(campo[1]), ESTILOS.valor, mm(wd), campo[2], Math.max(campo[4], campo[3] - menos), campo[4])
        };
        total += altoDe(b.etiqueta, ESTILOS.etiqueta) + mm(0.4) + altoDe(b.valor, ESTILOS.valor);
        return b;
      });
      menos += 0.25;
    } while (total > disponible && menos <= 6);

    bloques.forEach(function (b) {
      var arriba = mm(y);
      var yv = arriba + pintar(ctx, b.etiqueta, ESTILOS.etiqueta, mm(xd), arriba, mm(wd), false, GRIS) + mm(0.4);
      var abajo = yv + pintar(ctx, b.valor, ESTILOS.valor, mm(xd), yv, mm(wd), false, TEXTO);
      ctx.fillStyle = TINTA;
      ctx.fillRect(mm(MARGEN), arriba, mm(0.3), abajo - arriba);
      y = (abajo / K) + 1.5;
    });

    return l.canvas;
  }

  /* ---- Reverso ------------------------------------------------------------ */
  function reverso() {
    var l = lienzo(), ctx = l.ctx;
    var ancho = ANCHO - 2 * MARGEN;

    // Cabecera: el evento y el número de la credencial.
    var y = MARGEN;
    var nombreEvento = encajar(ctx, mayus(datos.evento), ESTILOS.evento, mm(ancho), 2, 6.8, 5);
    var credencial = encajar(ctx, mayus('Credencial digital · ' + datos.credencial), ESTILOS.sub, mm(ancho), 1, 4.6, 3.8);
    var altoCabeza = altoDe(nombreEvento, ESTILOS.evento) + mm(0.8) + altoDe(credencial, ESTILOS.sub);
    var yt = mm(y + 5.25) - altoCabeza / 2;
    yt += pintar(ctx, nombreEvento, ESTILOS.evento, mm(MARGEN), yt, mm(ancho), true, TEXTO);
    pintar(ctx, credencial, ESTILOS.sub, mm(MARGEN), yt + mm(0.8), mm(ancho), true, GRIS);
    y += 10.5 + HUECO;

    // El código, módulo por módulo: nítido a cualquier tamaño de impresión.
    var lado = 40, xq = (ANCHO - lado) / 2;
    marco(ctx, xq, y, lado, lado, TINTA);
    var filas = datos.qr || [];
    var n = filas.length, silencio = 2;
    var util = Math.floor(mm(lado - 2.6));
    var modulo = Math.floor(util / (n + 2 * silencio)) || 1;
    var origenX = Math.round(mm(xq + 1.3) + (util - modulo * (n + 2 * silencio)) / 2) + modulo * silencio;
    var origenY = Math.round(mm(y + 1.3) + (util - modulo * (n + 2 * silencio)) / 2) + modulo * silencio;
    ctx.fillStyle = '#000000';
    filas.forEach(function (fila, fy) {
      for (var fx = 0; fx < fila.length; fx++) {
        if (fila.charAt(fx) === '1') ctx.fillRect(origenX + fx * modulo, origenY + fy * modulo, modulo, modulo);
      }
    });
    y += lado + 1.5;

    var leyenda = encajar(ctx, mayus('Escanear para acceso / contacto'), ESTILOS.leyenda, mm(ancho), 1, 4.8, 3.8);
    y += pintar(ctx, leyenda, ESTILOS.leyenda, mm(MARGEN), mm(y), mm(ancho), true, GRIS) / K + HUECO;

    // Quién es: para que se lea aunque el carnet quede del revés en la funda.
    var nombre = encajar(ctx, mayus(datos.nombre), ESTILOS.valor, mm(ancho), 2, 8.5, 5.5);
    var yq = mm(y) + pintar(ctx, nombre, ESTILOS.valor, mm(MARGEN), mm(y), mm(ancho), true, TEXTO) + mm(0.8);
    var entidad = encajar(ctx, mayus(datos.entidad), ESTILOS.gris, mm(ancho), 2, 6.5, 5);
    pintar(ctx, entidad, ESTILOS.gris, mm(MARGEN), yq, mm(ancho), true, GRIS);

    // Al pie, la identificación o el número del carnet.
    var pie = encajar(ctx, mayus(datos.pie), ESTILOS.codigo, mm(ancho), 1, 7, 5);
    var altoPie = altoDe(pie, ESTILOS.codigo);
    var yp = mm(ALTO - MARGEN) - altoPie;
    ctx.fillStyle = SUAVE;
    ctx.fillRect(mm(MARGEN), yp - mm(1.5) - mm(0.2), mm(ancho), mm(0.2));
    pintar(ctx, pie, ESTILOS.codigo, mm(MARGEN), yp, mm(ancho), true, TEXTO);

    return l.canvas;
  }

  /* ---- PNG con su resolución anotada ---------------------------------------- */
  // Sin el bloque pHYs, un PNG no dice a qué tamaño imprimirse y cada programa
  // supone uno. Con él, «tamaño real» son 6 × 9,5 cm.
  var TABLA_CRC = (function () {
    var t = [];
    for (var n = 0; n < 256; n++) {
      var c = n;
      for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
      t[n] = c >>> 0;
    }
    return t;
  })();

  function crc32(bytes) {
    var c = 0xFFFFFFFF;
    for (var i = 0; i < bytes.length; i++) c = TABLA_CRC[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  function conResolucion(blob) {
    return new Response(blob).arrayBuffer().then(function (buffer) {
      var png = new Uint8Array(buffer);
      var vista = new DataView(buffer);
      var trozos = [png.subarray(0, 8)];
      var porMetro = Math.round(PPP / 0.0254);
      var phys = new Uint8Array(21);
      var v = new DataView(phys.buffer);
      v.setUint32(0, 9);
      phys.set([0x70, 0x48, 0x59, 0x73], 4);          // «pHYs»
      v.setUint32(8, porMetro);
      v.setUint32(12, porMetro);
      phys[16] = 1;                                   // la unidad es el metro
      v.setUint32(17, crc32(phys.subarray(4, 17)));
      for (var p = 8; p + 8 <= png.length;) {
        var largo = vista.getUint32(p);
        var tipo = String.fromCharCode(png[p + 4], png[p + 5], png[p + 6], png[p + 7]);
        var fin = p + 12 + largo;
        if (tipo !== 'pHYs') trozos.push(png.subarray(p, fin));
        if (tipo === 'IHDR') trozos.push(phys);
        p = fin;
      }
      return new Blob(trozos, { type: 'image/png' });
    });
  }

  function aPng(canvas) {
    return new Promise(function (listo, falla) {
      canvas.toBlob(function (blob) {
        if (blob) listo(blob); else falla(new Error('El lienzo no se pudo convertir en imagen.'));
      }, 'image/png');
    }).then(conResolucion);
  }

  /* ---- Preparar, mostrar y bajar --------------------------------------------- */
  var preparadas = null;

  function preparar() {
    if (preparadas) return preparadas;
    var muestra = mayus([datos.evento, datos.dependencia, datos.rol, datos.nombre, datos.identificacion,
      datos.entidad, datos.credencial, datos.pie, datos.iniciales, 'Credencial digital · Escanear para acceso / contacto'].join(' '));
    var letras = document.fonts && document.fonts.load
      ? Promise.all([
          document.fonts.load('700 40px ' + DISPLAY, muestra),
          document.fonts.load('400 20px ' + MONO, muestra)
        ]).catch(function () { return null; })
      : Promise.resolve(null);

    preparadas = Promise.all([letras, cargar(datos.logo), cargar(datos.foto)]).then(function (r) {
      return Promise.all([aPng(frente(r[1], r[2])), aPng(reverso())]);
    }).then(function (pngs) {
      raiz.setAttribute('data-imagenes', 'listas');
      return {
        frente: { blob: pngs[0], nombre: datos.archivo + '-frente.png', url: URL.createObjectURL(pngs[0]), rotulo: 'Frente' },
        reverso: { blob: pngs[1], nombre: datos.archivo + '-reverso.png', url: URL.createObjectURL(pngs[1]), rotulo: 'Reverso' }
      };
    });
    preparadas.catch(function () { preparadas = null; });
    return preparadas;
  }

  function bajar(imagen) {
    var a = document.createElement('a');
    a.href = imagen.url;
    a.download = imagen.nombre;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function mostrar(imagenes) {
    lista.textContent = '';
    [imagenes.frente, imagenes.reverso].forEach(function (imagen) {
      var figura = document.createElement('figure');
      var img = document.createElement('img');
      img.src = imagen.url;
      img.alt = imagen.rotulo + ' del carnet';
      var pie = document.createElement('figcaption');
      pie.textContent = imagen.rotulo;
      var enlace = document.createElement('a');
      enlace.className = 'btn btn--sm';
      enlace.href = imagen.url;
      enlace.download = imagen.nombre;
      enlace.textContent = 'Descargar ' + imagen.rotulo.toLowerCase();
      figura.appendChild(img);
      figura.appendChild(pie);
      figura.appendChild(enlace);
      lista.appendChild(figura);
    });
    var nota = document.createElement('p');
    nota.className = 'help';
    nota.style.cssText = 'margin:0;flex-basis:100%;text-align:center';
    nota.textContent = 'Dos imágenes PNG de 6 × 9,5 cm a 300 ppp. Si tu navegador solo bajó una, usa el '
      + 'enlace de la otra. En el celular también puedes mantener presionada cada imagen para guardarla.';
    lista.appendChild(nota);
    estado.textContent = 'Listas';
    panel.hidden = false;
  }

  boton.addEventListener('click', function () {
    var texto = boton.textContent;
    boton.disabled = true;
    boton.textContent = 'Preparando…';
    preparar().then(function (imagenes) {
      mostrar(imagenes);
      bajar(imagenes.frente);
      // La segunda un instante después: algunos navegadores descartan dos
      // descargas que llegan en el mismo momento.
      setTimeout(function () { bajar(imagenes.reverso); }, 700);
    }).catch(function () {
      lista.textContent = 'No se pudieron generar las imágenes en este navegador. Usa «Imprimir», o prueba con otro navegador.';
      estado.textContent = '';
      panel.hidden = false;
    }).then(function () {
      boton.disabled = false;
      boton.textContent = texto;
    });
  });

  // Se preparan en cuanto la página termina de cargar, sin esperar el clic.
  if (document.readyState === 'complete') {
    setTimeout(preparar, 200);
  } else {
    window.addEventListener('load', function () { setTimeout(preparar, 200); });
  }
})();
