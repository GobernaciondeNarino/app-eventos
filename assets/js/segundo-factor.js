/**
 * Compara la hora del servidor con la de este equipo, en la pantalla del
 * segundo factor.
 *
 * Es solo informativo. Cuando el código correcto no entra, casi siempre es
 * porque el reloj del servidor está corrido —el del teléfono se pone en hora
 * solo—, y decirlo con números ahorra media hora de revisar el teléfono. La
 * plataforma lo compensa por su cuenta: pide un segundo código y aprende el
 * desfase.
 */
(function () {
  'use strict';

  var nodo = document.querySelector('[data-hora-servidor]');
  var aviso = document.querySelector('[data-reloj-aviso]');
  if (!nodo || !aviso) return;

  var servidor = parseInt(nodo.getAttribute('data-hora-servidor'), 10) * 1000;
  if (!servidor) return;

  // El momento en que se pidió la página, no el de ahora: si el navegador la
  // restaura de su caché un rato después, la comparación seguiría siendo justa.
  var pedida = (window.performance && typeof performance.now === 'function')
    ? Date.now() - performance.now()
    : Date.now();
  var diferencia = Math.abs(pedida - servidor);
  if (diferencia < 45000) return;

  var cuanto;
  if (diferencia < 90000) {
    cuanto = Math.round(diferencia / 1000) + ' segundos';
  } else if (diferencia < 5400000) {
    cuanto = Math.round(diferencia / 60000) + ' minutos';
  } else {
    cuanto = (Math.round(diferencia / 360000) / 10) + ' horas';
  }

  aviso.textContent = 'La hora de este servidor y la de este equipo difieren ' + cuanto + '. '
    + 'Si el código correcto no entra, la plataforma te pedirá el siguiente para confirmarlo; '
    + 'conviene que quien administra el servidor active su hora automática.';
  aviso.hidden = false;
})();
