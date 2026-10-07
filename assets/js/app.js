/* =========================================================================
   app.js — Comportamientos compartidos por todas las pantallas.
   -------------------------------------------------------------------------
   La aplicación funciona sin JavaScript: el servidor entrega el HTML completo
   y los formularios se envían por POST. Esto solo agrega comodidad —abrir un
   diálogo, plegar una sección, contar caracteres—, así que si algo de aquí
   falla, la plataforma sigue siendo utilizable.
   ========================================================================= */
(function () {
  'use strict';

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  };

  /* ---- Avisos flotantes -------------------------------------------------- */
  $$('.toast[data-autocerrar]').forEach(function (aviso) {
    var ms = parseInt(aviso.getAttribute('data-autocerrar'), 10) || 5000;
    setTimeout(function () {
      aviso.style.transition = 'opacity .3s';
      aviso.style.opacity = '0';
      setTimeout(function () { aviso.remove(); }, 300);
    }, ms);
  });

  /* ---- Diálogos ----------------------------------------------------------
     Con foco atrapado y cierre por Escape: sin eso el teclado se pierde
     detrás del velo y la pantalla queda inutilizable con lector.          */
  var abierto = null;
  var focoPrevio = null;

  function abrir(el) {
    if (!el) return;
    cerrar();
    focoPrevio = document.activeElement;
    el.hidden = false;
    el.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    abierto = el;

    var primero = el.querySelector('input:not([type=hidden]), select, textarea, button');
    if (primero) primero.focus();
  }

  function cerrar() {
    if (!abierto) return;
    abierto.hidden = true;
    abierto.classList.add('hidden');
    document.body.style.overflow = '';
    if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
    abierto = null;
  }

  document.addEventListener('click', function (e) {
    var abridor = e.target.closest('[data-abrir-modal], [data-abrir-hoja]');
    if (abridor) {
      e.preventDefault();
      var id = abridor.getAttribute('data-abrir-modal') || abridor.getAttribute('data-abrir-hoja');
      abridor.setAttribute('aria-expanded', 'true');
      abrir(document.getElementById(id));
      return;
    }
    if (e.target.closest('[data-cerrar-modal], [data-cerrar-hoja]')) {
      e.preventDefault();
      cerrar();
      return;
    }
    // Clic en el velo, fuera del panel.
    if (abierto && (e.target === abierto)) cerrar();
  });

  document.addEventListener('keydown', function (e) {
    if (!abierto) return;
    if (e.key === 'Escape') { cerrar(); return; }
    if (e.key !== 'Tab') return;

    var focos = $$('button, [href], input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])', abierto)
      .filter(function (el) { return el.offsetParent !== null; });
    if (!focos.length) return;

    var primero = focos[0];
    var ultimo = focos[focos.length - 1];
    if (e.shiftKey && document.activeElement === primero) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primero.focus(); }
  });

  /* ---- Secciones plegables ------------------------------------------------ */
  $$('[data-plegar]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var destino = document.getElementById(boton.getAttribute('data-plegar'));
      if (!destino) return;
      var oculto = destino.classList.toggle('hidden');
      boton.textContent = oculto ? 'Mostrar' : 'Ocultar';
      boton.setAttribute('aria-expanded', String(!oculto));
    });
  });

  /* ---- Casillas que revelan un bloque ------------------------------------- */
  $$('[data-mostrar]').forEach(function (casilla) {
    var destino = document.getElementById(casilla.getAttribute('data-mostrar'));
    if (!destino) return;
    casilla.addEventListener('change', function () {
      destino.classList.toggle('hidden', !casilla.checked);
    });
  });

  /* ---- Grupos de opciones tipo chip --------------------------------------
     El estado real lo lleva el radio; la clase solo lo refleja.           */
  $$('.chip input[type=radio]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      var grupo = document.getElementsByName(radio.name);
      Array.prototype.forEach.call(grupo, function (otro) {
        var chip = otro.closest('.chip');
        if (chip) chip.classList.toggle('is-active', otro.checked);
      });
    });
  });

  /* ---- Contador de caracteres --------------------------------------------- */
  $$('[data-contador]').forEach(function (campo) {
    var salida = document.getElementById(campo.getAttribute('data-contador'));
    if (!salida) return;
    var tope = campo.getAttribute('maxlength') || '';
    campo.addEventListener('input', function () {
      salida.textContent = campo.value.length + (tope ? ' / ' + tope : '');
    });
  });

  /* ---- Departamento → municipio -------------------------------------------
     Si la consulta falla, el selector queda con la lista que el servidor ya
     había entregado; el envío se valida igual del lado del servidor.       */
  var depto = $('[data-municipios]');
  if (depto) {
    var municipio = $('#municipio');
    var cargando = $('#mun-cargando');

    depto.addEventListener('change', function () {
      if (!municipio) return;
      var valor = depto.value;

      municipio.disabled = true;
      municipio.innerHTML = '<option value="">Elige primero el departamento</option>';
      if (!valor) return;

      if (cargando) cargando.classList.remove('hidden');

      fetch(depto.getAttribute('data-municipios') + encodeURIComponent(valor), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (datos) {
          var opciones = ['<option value="">Selecciona…</option>'];
          (datos.municipios || []).forEach(function (m) {
            var seguro = String(m).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            opciones.push('<option value="' + seguro + '">' + seguro + '</option>');
          });
          municipio.innerHTML = opciones.join('');
          municipio.disabled = false;
        })
        .catch(function () {
          municipio.innerHTML = '<option value="">No se pudo cargar la lista</option>';
        })
        .finally(function () {
          if (cargando) cargando.classList.add('hidden');
        });
    });
  }

  /* ---- Fuerza de la contraseña -------------------------------------------
     La longitud pesa más que la variedad de símbolos, que es lo que de
     verdad resiste un ataque por fuerza bruta.                            */
  var campoClave = $('[data-fuerza]');
  if (campoClave) {
    var barra = $('[data-fuerza-barra]');
    var texto = $('[data-fuerza-texto]');

    campoClave.addEventListener('input', function () {
      var v = campoClave.value;
      var puntos = Math.min(60, v.length * 4)
        + (/[a-z]/.test(v) && /[A-Z]/.test(v) ? 10 : 0)
        + (/\d/.test(v) ? 10 : 0)
        + (/[^\w\s]/.test(v) ? 10 : 0)
        + (/\s/.test(v) ? 10 : 0);
      puntos = Math.min(100, puntos);

      if (barra) {
        barra.style.width = puntos + '%';
        barra.style.background = puntos < 40 ? 'var(--c-danger)'
          : (puntos < 70 ? 'var(--c-warn)' : 'var(--c-ok)');
      }
      if (texto) {
        texto.textContent = v.length < 12
          ? 'Faltan ' + (12 - v.length) + ' caracteres para el mínimo.'
          : (puntos < 70 ? 'Aceptable. Una frase con varias palabras la haría más fuerte.' : 'Buena contraseña.');
      }
    });
  }

  /* ---- Copiar al portapapeles ---------------------------------------------
     El botón lleva el texto en data-copiar. Si el navegador no tiene la API
     moderna —o la página no está en HTTPS— se cae al método de siempre, que
     funciona en todas partes.                                             */
  document.addEventListener('click', function (e) {
    var boton = e.target.closest('[data-copiar]');
    if (!boton) return;
    e.preventDefault();

    var texto = boton.getAttribute('data-copiar') || '';
    var previo = boton.textContent;

    var avisar = function (ok) {
      boton.textContent = ok ? 'Copiado' : 'No se pudo copiar';
      setTimeout(function () { boton.textContent = previo; }, 1800);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(texto).then(function () { avisar(true); },
        function () { avisar(false); });
      return;
    }

    var campo = document.createElement('textarea');
    campo.value = texto;
    campo.setAttribute('readonly', '');
    campo.style.position = 'fixed';
    campo.style.opacity = '0';
    document.body.appendChild(campo);
    campo.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    document.body.removeChild(campo);
    avisar(ok);
  });

  /* ---- Un enlace a una sección plegada la abre ----------------------------
     Registro de expositores remite a «Perfiles de asistencia» de la pestaña
     Registro, que viene plegada: llegar y tener que buscar dónde abrirla
     sería medio enlace.                                                   */
  var plegada = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  if (plegada && plegada.tagName === 'DETAILS') plegada.open = true;

  /* ---- Confirmación antes de una acción que no se deshace -----------------
     Va en el formulario, no en el botón: así protege también los envíos
     hechos con Enter desde un campo.                                      */
  $$('form[data-confirmar]').forEach(function (formulario) {
    formulario.addEventListener('submit', function (e) {
      if (!window.confirm(formulario.getAttribute('data-confirmar'))) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    });
  });

  /* ---- Pestañas -----------------------------------------------------------
     Sin JavaScript se ven todos los paneles uno debajo de otro, que sigue
     siendo perfectamente utilizable: la clase que los oculta la pone este
     guion, no el servidor.                                                */
  $$('[data-pestanas]').forEach(function (grupo) {
    var botones = $$('[data-pestana]', grupo);
    if (!botones.length) return;

    var paneles = botones.map(function (b) {
      return document.getElementById(b.getAttribute('data-pestana'));
    });

    var mostrar = function (indice) {
      botones.forEach(function (boton, i) {
        var activo = i === indice;
        boton.classList.toggle('is-active', activo);
        boton.setAttribute('aria-selected', String(activo));
        boton.tabIndex = activo ? 0 : -1;
        if (paneles[i]) paneles[i].hidden = !activo;
      });
    };

    botones.forEach(function (boton, i) {
      boton.addEventListener('click', function () { mostrar(i); });
      boton.addEventListener('keydown', function (e) {
        var salto = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0);
        if (!salto) return;
        e.preventDefault();
        var siguiente = (i + salto + botones.length) % botones.length;
        botones[siguiente].focus();
        mostrar(siguiente);
      });
    });

    // Si algún panel trae un campo con error, esa es la pestaña que se abre.
    var conError = paneles.findIndex(function (p) {
      return p && p.querySelector('.field--error, [aria-invalid="true"]');
    });
    mostrar(conError >= 0 ? conError : 0);
  });

  /* ---- Envíos: evitar el doble clic --------------------------------------
     En la puerta del evento, con conexión lenta, la gente pulsa dos veces y
     se generan registros duplicados.                                      */
  $$('form').forEach(function (formulario) {
    formulario.addEventListener('submit', function (evento) {
      var boton = formulario.querySelector('button[type=submit]:not([formnovalidate])');
      if (!boton || formulario.hasAttribute('data-sin-bloqueo')) return;
      setTimeout(function () {
        // Otro guion pudo frenar el envío —contraseñas que no coinciden, un
        // registro incompleto—. Se mira aquí y no arriba porque esos guiones
        // escuchan después que este. Sin esto el botón se quedaba ocho
        // segundos en «Enviando…» sin que se estuviera enviando nada.
        if (evento.defaultPrevented) return;
        boton.disabled = true;
        boton.dataset.textoPrevio = boton.textContent;
        boton.textContent = 'Enviando…';
      }, 0);
      // Si el navegador cancela el envío (validación nativa), se restaura.
      setTimeout(function () {
        if (boton.disabled && document.body.contains(boton)) {
          boton.disabled = false;
          if (boton.dataset.textoPrevio) boton.textContent = boton.dataset.textoPrevio;
        }
      }, 8000);
    });
  });

  /* ---------------------------------------------------------------------
     Botones de impresión
     ---------------------------------------------------------------------
     Antes eran onclick="window.print()" en el propio HTML, y la política de
     seguridad de la plataforma no admite guiones en línea: el botón del pliego
     del día y el del carnet no hacían absolutamente nada.
     --------------------------------------------------------------------- */
  $$('[data-imprimir]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      window.print();
    });
  });

  window.App = { abrirDialogo: abrir, cerrarDialogo: cerrar, $: $, $$: $$ };
})();
