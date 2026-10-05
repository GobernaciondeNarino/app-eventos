/* Preregistro: el perfil «expositor» y la casilla de exposición van juntos.

   La fotografía tiene su propio guion, foto.js: allí está el editor con el que
   se centra y se acerca antes de subirla.

   Quien marca que va a exponer casi siempre quiere el perfil de expositor, y
   al revés. Mantenerlos sincronizados evita el caso —frecuente en la fase de
   pruebas— de alguien que llena la propuesta pero queda registrado como
   participante y luego no entiende por qué su carnet no dice «expositor». */
(function () {
  'use strict';

  var casilla = document.getElementById('expositor');

  /* El selector de perfil puede no estar: a quien tiene un perfil que solo pone
     un administrador —Staff, Organizador— se le enseña su etiqueta y no una
     lista. Antes el guion se iba aquí mismo si faltaba, y con él se iba también
     lo de abajo: los adjuntos del expositor dejaban de marcarse obligatorios. */
  var perfil = document.getElementById('rol');

  /* El teclado del celular sigue al tipo de documento: solo números para la
     cédula y la tarjeta de identidad, letras y números para el pasaporte, los
     permisos de permanencia y los demás. Con el numérico fijo, un pasaporte no
     se podía escribir desde el teléfono. */
  var tipo = document.getElementById('tipo_documento');
  var numero = document.querySelector('[data-documento]');
  if (tipo && numero) {
    tipo.addEventListener('change', function () {
      numero.inputMode = (tipo.value === 'CC' || tipo.value === 'TI') ? 'numeric' : 'text';
    });
  }

  if (!casilla) return;

  /* Los dos adjuntos son obligatorios, pero solo para quien expone.

     No llevan «required» puesto en el HTML a propósito: un campo obligatorio
     dentro de un bloque oculto hace que el navegador se niegue a enviar el
     formulario y no pueda decir por qué —«An invalid form control is not
     focusable»—, así que quien NO va a exponer se quedaría atascado sin ver
     ningún error. Se marca y se desmarca con la casilla.

     Sin JavaScript no pasa nada: la regla de verdad está en el servidor, en
     Publico::validarRegistro(). */
  var exigidos = document.querySelectorAll('[data-exige-expositor]');

  function sincronizar() {
    Array.prototype.forEach.call(exigidos, function (campo) {
      campo.required = casilla.checked;
    });
  }

  /* El evento puede no ofrecer el perfil «expositor» —se configura en
     Configuración → Registro—. Ponérselo al selector sin esa opción lo deja en
     blanco, y el envío rebota con «perfil no válido». */
  function tieneOpcion(valor) {
    return !!perfil && !!perfil.querySelector('option[value="' + valor + '"]');
  }

  casilla.addEventListener('change', function () {
    if (perfil) {
      if (casilla.checked && perfil.value === 'participante' && tieneOpcion('expositor')) {
        perfil.value = 'expositor';
      } else if (!casilla.checked && perfil.value === 'expositor') {
        perfil.value = 'participante';
      }
    }
    sincronizar();
  });

  if (perfil) {
    perfil.addEventListener('change', function () {
      if (perfil.value === 'expositor' && !casilla.checked) {
        casilla.checked = true;
        casilla.dispatchEvent(new Event('change'));
      }
    });
  }

  // Al cargar: el bloque puede venir abierto porque la propuesta ya existe o
  // porque algo del envío anterior falló.
  sincronizar();
})();
