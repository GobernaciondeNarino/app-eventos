/* =========================================================================
   foto.js — Encuadrar la fotografía del carnet antes de subirla.
   -------------------------------------------------------------------------
   El carnet lleva la foto en un cuadrado, y el servidor no puede adivinar qué
   parte de la imagen importa: recortando el centro, media plaza sale con la
   cara cortada por la frente o de medio lado. Esto deja que cada quien mueva y
   acerque su propia foto hasta que quede como la quiere.

   Lo que se envía NO es la imagen recortada: es el archivo original más el
   rectángulo elegido, en las medidas con las que este navegador vio la imagen.
   El recorte lo hace el servidor (App\Nucleo\Imagen), que reescala esos números
   a las medidas reales y los encaja dentro de la foto. Así:

     · el campo sigue siendo un <input type=file> normal, que funciona en
       cualquier navegador y sin este guion;
     · quien decide qué se guarda sigue siendo el servidor, que es donde tiene
       que decidirse;
     · y no hace falta DataTransfer ni convertir a blob, que es justo lo que
       falla en los navegadores viejos de los teléfonos de gama baja.

   Sin JavaScript no pasa nada raro: se ve el campo de archivo de siempre y el
   servidor recorta el centro, como antes.
   ========================================================================= */
(function () {
  'use strict';

  var raiz = document.querySelector('[data-foto]');
  if (!raiz) return;

  var q = function (sel) { return raiz.querySelector(sel); };

  var campo = q('#foto');
  var visor = q('[data-foto-visor]');
  var lienzo = q('[data-foto-lienzo]');
  var marco = q('[data-foto-marco]');
  var mandos = q('[data-foto-mandos]');
  var zoom = q('[data-foto-zoom]');
  var centrar = q('[data-foto-centrar]');
  var elegir = q('[data-foto-elegir]');
  var descartar = q('[data-foto-descartar]');
  var contenedorCampo = q('[data-foto-campo]');
  var error = q('[data-foto-error]');
  var actual = q('[data-foto-actual]');
  var vacia = q('[data-foto-vacia]');
  // Solo si ya tiene una: el aviso de que la nueva la reemplaza al guardar, y
  // la casilla de quitarla, que con una nueva en el editor no aplica.
  var reemplaza = q('[data-foto-reemplaza]');
  var quitar = q('[data-foto-quitar]');

  if (!campo || !visor || !lienzo || !mandos || !zoom) return;

  var salida = {
    x: q('[data-foto-x]'), y: q('[data-foto-y]'), lado: q('[data-foto-lado]'),
    ancho: q('[data-foto-ancho]'), alto: q('[data-foto-alto]')
  };

  // El botón sustituye al campo, que se esconde. Si algo de aquí fallara, el
  // campo seguiría en el HTML; por eso se oculta al final y no en el servidor.
  if (contenedorCampo) contenedorCampo.classList.add('hidden');
  if (elegir) {
    elegir.addEventListener('click', function () { campo.click(); });
  }

  var TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
  var PESO_MAXIMO = 6 * 1024 * 1024;

  var imagen = null;      // la Image cargada
  var urlObjeto = null;
  var lado = 0;           // lado del cuadro visible, en píxeles de la imagen
  var cx = 0, cy = 0;     // centro del cuadro, en píxeles de la imagen
  var ladoMaximo = 0;     // el cuadro más grande que cabe en la imagen
  var contexto = lienzo.getContext('2d');

  /* ---------------------------------------------------------------------
     Mensajes
     --------------------------------------------------------------------- */

  function avisar(texto) {
    if (!error) return;
    error.textContent = texto || '';
    error.classList.toggle('hidden', !texto);
  }

  /* ---------------------------------------------------------------------
     Cargar el archivo elegido
     --------------------------------------------------------------------- */

  campo.addEventListener('change', function () {
    var archivo = campo.files && campo.files[0];
    if (!archivo) { limpiar(); return; }

    // Se comprueba aquí para poder decirlo en el acto, sin esperar a subir seis
    // megas por una conexión de la puerta del recinto. El servidor lo comprueba
    // otra vez, y por el contenido real y no por lo que declare el navegador.
    if (TIPOS.indexOf(archivo.type) === -1 && archivo.type !== '') {
      limpiar();
      avisar('Ese archivo no es una foto que podamos usar. Sirven JPG, PNG y WEBP.');
      return;
    }
    if (archivo.size > PESO_MAXIMO) {
      limpiar();
      avisar('La foto pesa más de 6 MB. Toma una con menos resolución o recórtala antes.');
      return;
    }

    avisar('');
    cargar(archivo);
  });

  function cargar(archivo) {
    soltarUrl();
    urlObjeto = URL.createObjectURL(archivo);

    var img = new Image();
    img.onload = function () {
      if (!img.naturalWidth || !img.naturalHeight) { limpiar(); return; }
      imagen = img;
      encuadrarPorOmision();
      mostrarEditor();
      dibujar();
    };
    img.onerror = function () {
      limpiar();
      avisar('No se pudo abrir esa imagen. Prueba con otra.');
    };
    img.src = urlObjeto;
  }

  function soltarUrl() {
    if (urlObjeto) { URL.revokeObjectURL(urlObjeto); urlObjeto = null; }
  }

  function limpiar() {
    imagen = null;
    soltarUrl();
    mandos.classList.add('hidden');
    lienzo.hidden = true;
    if (marco) marco.hidden = true;
    if (descartar) descartar.classList.add('hidden');
    if (actual) actual.hidden = false;
    if (vacia) vacia.hidden = false;
    if (reemplaza) reemplaza.classList.add('hidden');
    if (quitar) quitar.classList.remove('hidden');
    for (var k in salida) { if (salida[k]) salida[k].value = ''; }
  }

  if (descartar) {
    descartar.addEventListener('click', function () {
      campo.value = '';
      limpiar();
      avisar('');
    });
  }

  function mostrarEditor() {
    if (actual) actual.hidden = true;
    if (vacia) vacia.hidden = true;
    lienzo.hidden = false;
    if (marco) marco.hidden = false;
    mandos.classList.remove('hidden');
    if (descartar) descartar.classList.remove('hidden');
    // Una foto nueva reemplaza a la actual (y el servidor le da la razón:
    // Publico::guardarFoto). La casilla de quitarla se desmarca y se esconde
    // para que nadie crea que hay que marcarla para cambiarla.
    if (reemplaza) reemplaza.classList.remove('hidden');
    if (quitar) {
      quitar.classList.add('hidden');
      var casilla = quitar.querySelector('input');
      if (casilla) casilla.checked = false;
    }
  }

  /* ---------------------------------------------------------------------
     El encuadre
     --------------------------------------------------------------------- */

  function encuadrarPorOmision() {
    ladoMaximo = Math.min(imagen.naturalWidth, imagen.naturalHeight);
    lado = ladoMaximo;
    cx = imagen.naturalWidth / 2;
    // Algo por encima del centro: en un retrato la cara está en el tercio
    // superior. Es el mismo criterio que usa el servidor cuando no hay editor.
    cy = (imagen.naturalHeight - ladoMaximo) * 0.35 + ladoMaximo / 2;
    zoom.value = '100';
    ajustar();
  }

  if (centrar) {
    centrar.addEventListener('click', function () {
      if (!imagen) return;
      encuadrarPorOmision();
      dibujar();
    });
  }

  /** Encaja el cuadro dentro de la imagen. */
  function ajustar() {
    lado = Math.max(ladoMaximo / 4, Math.min(lado, ladoMaximo));
    var mitad = lado / 2;
    cx = Math.max(mitad, Math.min(cx, imagen.naturalWidth - mitad));
    cy = Math.max(mitad, Math.min(cy, imagen.naturalHeight - mitad));
  }

  zoom.addEventListener('input', function () {
    if (!imagen) return;
    aplicarZoom(parseInt(zoom.value, 10) / 100);
  });

  /** $factor 1 = la foto entera; 4 = cuatro veces más cerca. */
  function aplicarZoom(factor) {
    factor = Math.max(1, Math.min(factor, 4));
    lado = ladoMaximo / factor;
    ajustar();
    dibujar();
  }

  /* ---------------------------------------------------------------------
     Arrastrar y pellizcar
     -------------------------------------------------------------------------
     Con eventos de puntero, que unifican ratón, dedo y lápiz. touch-action:none
     en el visor es lo que impide que arrastrar la foto arrastre la página.
     --------------------------------------------------------------------- */

  var punteros = {};
  var separacionPrevia = 0;

  visor.addEventListener('pointerdown', function (e) {
    if (!imagen) return;
    visor.setPointerCapture(e.pointerId);
    punteros[e.pointerId] = { x: e.clientX, y: e.clientY };
    separacionPrevia = 0;
  });

  visor.addEventListener('pointermove', function (e) {
    if (!imagen || !punteros[e.pointerId]) return;
    e.preventDefault();

    var ids = Object.keys(punteros);

    if (ids.length >= 2) {
      // Dos dedos: la distancia entre ellos manda el zoom.
      punteros[e.pointerId] = { x: e.clientX, y: e.clientY };
      var a = punteros[ids[0]];
      var b = punteros[ids[1]];
      var separacion = Math.hypot(a.x - b.x, a.y - b.y);

      if (separacionPrevia > 0 && separacion > 0) {
        var factorActual = ladoMaximo / lado;
        aplicarZoom(factorActual * (separacion / separacionPrevia));
        zoom.value = String(Math.round((ladoMaximo / lado) * 100));
      }
      separacionPrevia = separacion;
      return;
    }

    // Un dedo: se mueve la foto. Un píxel de pantalla son «lado/tamaño» píxeles
    // de la imagen, así que acercada se mueve más despacio, como se espera.
    var previo = punteros[e.pointerId];
    var escala = lado / visor.clientWidth;
    cx -= (e.clientX - previo.x) * escala;
    cy -= (e.clientY - previo.y) * escala;
    punteros[e.pointerId] = { x: e.clientX, y: e.clientY };

    ajustar();
    dibujar();
  });

  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (evento) {
    visor.addEventListener(evento, function (e) {
      delete punteros[e.pointerId];
      separacionPrevia = 0;
    });
  });

  visor.addEventListener('wheel', function (e) {
    if (!imagen) return;
    e.preventDefault();
    var factor = (ladoMaximo / lado) * (e.deltaY < 0 ? 1.12 : 1 / 1.12);
    aplicarZoom(factor);
    zoom.value = String(Math.round((ladoMaximo / lado) * 100));
  }, { passive: false });

  /* ---------------------------------------------------------------------
     Pintar y publicar el encuadre
     --------------------------------------------------------------------- */

  function dibujar() {
    if (!imagen) return;

    var tamano = visor.clientWidth || 160;
    var dpr = Math.min(window.devicePixelRatio || 1, 3);

    if (lienzo.width !== Math.round(tamano * dpr)) {
      lienzo.width = Math.round(tamano * dpr);
      lienzo.height = Math.round(tamano * dpr);
    }

    contexto.imageSmoothingQuality = 'high';
    contexto.clearRect(0, 0, lienzo.width, lienzo.height);
    contexto.drawImage(
      imagen,
      cx - lado / 2, cy - lado / 2, lado, lado,
      0, 0, lienzo.width, lienzo.height
    );

    publicar();
  }

  function publicar() {
    if (!salida.lado) return;
    salida.x.value = String(Math.round(cx - lado / 2));
    salida.y.value = String(Math.round(cy - lado / 2));
    salida.lado.value = String(Math.round(lado));
    salida.ancho.value = String(imagen.naturalWidth);
    salida.alto.value = String(imagen.naturalHeight);
  }

  // El visor cambia de tamaño al girar el teléfono.
  window.addEventListener('resize', function () { if (imagen) dibujar(); });
  window.addEventListener('pagehide', soltarUrl);
})();
