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
  var perfil = document.getElementById('rol');
  if (!casilla || !perfil) return;

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

  casilla.addEventListener('change', function () {
    if (casilla.checked && perfil.value === 'participante') {
      perfil.value = 'expositor';
    } else if (!casilla.checked && perfil.value === 'expositor') {
      perfil.value = 'participante';
    }
    sincronizar();
  });

  perfil.addEventListener('change', function () {
    if (perfil.value === 'expositor' && !casilla.checked) {
      casilla.checked = true;
      casilla.dispatchEvent(new Event('change'));
    }
  });

  // Al cargar: el bloque puede venir abierto porque la propuesta ya existe o
  // porque algo del envío anterior falló.
  sincronizar();
})();
