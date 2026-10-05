/* Registro incompleto: avisarlo en una ventana, no solo en rojo junto al campo.

   Al pulsar «Completar registro» o «Guardar cambios» con algo obligatorio sin
   llenar, el formulario no se envía y se abre una ventana que lo dice con todas
   las letras —tu registro no fue guardado— y enumera lo que falta. El botón de
   la ventana lleva al primer campo pendiente, abriendo la sección plegada si
   estaba ahí dentro.

   Lo mismo cuando el envío vuelve del servidor sin guardarse: la ventana se
   abre sola con los errores que encontró él. Sin JavaScript queda el aviso
   rojo de arriba del formulario y los mensajes junto a cada campo, que dicen
   lo mismo.

   Qué es obligatorio lo decide el servidor —Configuración → Registro— y llega
   como el atributo «required» de cada campo. Los del expositor solo lo llevan
   mientras la casilla «voy a exponer» está marcada (preregistro.js). */
(function () {
  'use strict';

  var formulario = document.querySelector('form[data-registro]');
  var ventana = document.getElementById('modal-registro-incompleto');
  if (!formulario || !ventana || !window.App) return;

  var lista = ventana.querySelector('[data-faltantes]');
  var primero = null;

  /* El nombre del campo, tal como lo ve la persona. */
  function rotulo(campo) {
    if (campo.getAttribute('data-rotulo')) return campo.getAttribute('data-rotulo');
    var grupo = campo.closest('[role=radiogroup], [role=group]');
    var etiqueta = null;
    if (campo.type === 'radio' && grupo && grupo.getAttribute('aria-labelledby')) {
      etiqueta = document.getElementById(grupo.getAttribute('aria-labelledby'));
    } else if (campo.id) {
      etiqueta = formulario.querySelector('label[for="' + campo.id + '"]');
    }
    var texto = etiqueta ? etiqueta.textContent : (campo.name || 'Un campo');
    return texto.replace(/\*/g, '').replace(/\(.*?\)/g, '').replace(/\s+/g, ' ').trim();
  }

  /* ¿Le falta algo a este campo? Devuelve el motivo, o '' si está bien. */
  function falta(campo) {
    if (campo.disabled) return '';
    if (campo.type === 'checkbox') return campo.checked ? '' : 'sin marcar';
    if (campo.type === 'radio') {
      var marcado = formulario.querySelector('input[name="' + campo.name + '"]:checked');
      return marcado ? '' : 'sin elegir';
    }
    if (campo.type === 'file') return campo.files && campo.files.length ? '' : 'sin adjuntar';

    var valor = (campo.value || '').trim();
    if (valor === '') return campo.tagName === 'SELECT' ? 'sin elegir' : 'vacío';

    var minimo = parseInt(campo.getAttribute('minlength') || '0', 10);
    if (minimo && valor.length < minimo) return 'muy corto: al menos ' + minimo + ' caracteres';
    if (campo.type === 'email' && campo.validity && campo.validity.typeMismatch) return 'no es un correo válido';
    return '';
  }

  function faltantes() {
    var vistos = {};
    var salida = [];
    Array.prototype.forEach.call(formulario.querySelectorAll('[required]'), function (campo) {
      if (campo.type === 'radio') {
        if (vistos[campo.name]) return;
        vistos[campo.name] = true;
      }
      var motivo = falta(campo);
      if (motivo) salida.push({ campo: campo, texto: rotulo(campo) + ': ' + motivo });
    });
    return salida;
  }

  function pintar(lista_) {
    lista.textContent = '';
    lista_.forEach(function (item) {
      var li = document.createElement('li');
      li.textContent = item.texto;
      lista.appendChild(li);
    });
  }

  /* Al primer campo pendiente, abriendo la sección plegada si hace falta. */
  function irAlPrimero() {
    var campo = primero || formulario.querySelector('.is-invalid, [aria-invalid="true"]');
    if (!campo) return;
    var plegado = campo.closest('.hidden[id]');
    if (plegado) {
      var boton = formulario.querySelector('[data-plegar="' + plegado.id + '"]');
      if (boton) boton.click();
    }
    var destino = campo.type === 'radio' || campo.classList.contains('sr-only')
      ? (campo.closest('.field') || campo) : campo;
    destino.scrollIntoView({ block: 'center', behavior: 'smooth' });
    try { campo.focus({ preventScroll: true }); } catch (e) { campo.focus(); }
  }

  formulario.addEventListener('submit', function (evento) {
    if (evento.defaultPrevented) return;   // claves.js ya lo frenó por otro motivo
    var pendientes = faltantes();
    if (!pendientes.length) return;

    evento.preventDefault();
    primero = pendientes[0].campo;
    pintar(pendientes);
    window.App.abrirDialogo(ventana);
  });

  ventana.addEventListener('click', function (evento) {
    if (evento.target.closest('[data-ir-al-primero]')) {
      // Después de que app.js cierre la ventana y devuelva el foco al botón.
      setTimeout(irAlPrimero, 0);
    }
  });

  // Volvió del servidor sin guardarse: la ventana se abre sola.
  if (ventana.hasAttribute('data-abrir-al-cargar')) {
    window.App.abrirDialogo(ventana);
  }
})();
