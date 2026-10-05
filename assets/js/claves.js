/* Las dos contraseñas, comprobadas mientras se escriben.

   Antes había que llenar el formulario entero, pulsar guardar y esperar la
   recarga para enterarse de que la repetición no coincidía —y en el registro
   eso significa volver a elegir la foto y los adjuntos, porque el navegador
   vacía los campos de archivo al repintar—. El error más barato de cometer
   salía carísimo de corregir.

   Esto no sustituye la comprobación del servidor, que sigue donde estaba: un
   envío puede llegar sin pasar por ninguna pantalla. Es para que nadie llegue
   hasta el botón sin saberlo.

   Sin JavaScript no cambia nada: el aviso nace vacío y el servidor valida igual. */
(function () {
  'use strict';

  var formularios = document.querySelectorAll('form');

  Array.prototype.forEach.call(formularios, function (form) {
    var nueva = form.querySelector('[data-clave-nueva]');
    var repetir = form.querySelector('[data-clave-repetir]');
    var estado = form.querySelector('[data-clave-estado]');
    if (!nueva || !repetir || !estado) return;

    var minimo = parseInt(nueva.getAttribute('minlength') || '0', 10) || 0;
    var ultimo = null;

    /* Qué decir, en una sola función para que no haya dos verdades.
       Devuelve null cuando no toca decir nada todavía. */
    function revisar() {
      var a = nueva.value;
      var b = repetir.value;

      // Las dos vacías: en «mis datos» eso significa «no la cambies», así que
      // no es un error ni hay nada que avisar.
      if (a === '' && b === '') return null;

      if (a.length < minimo) {
        var faltan = minimo - a.length;
        return {
          ok: false,
          // Mientras escribe la primera no se le dice que «no coincide»: no ha
          // llegado todavía a repetirla.
          texto: 'Faltan ' + faltan + (faltan === 1 ? ' caracter' : ' caracteres')
                 + ' para el mínimo de ' + minimo + '.'
        };
      }

      if (b === '') return null;
      if (a !== b) return { ok: false, texto: 'Las dos contraseñas todavía no coinciden.' };
      return { ok: true, texto: 'Las dos contraseñas coinciden.' };
    }

    function pintar() {
      var r = revisar();
      var texto = r ? r.texto : '';

      // Solo se toca el DOM cuando cambia el mensaje: con aria-live, reescribirlo
      // en cada tecla hace que el lector de pantalla hable sin parar.
      if (texto === ultimo) return;
      ultimo = texto;

      estado.textContent = texto;
      estado.className = 'campo-estado'
        + (r ? (r.ok ? ' campo-estado--ok' : ' campo-estado--mal') : '');

      var mal = !!r && !r.ok && repetir.value !== '';
      repetir.classList.toggle('is-invalid', mal);
      if (mal) {
        repetir.setAttribute('aria-invalid', 'true');
      } else {
        repetir.removeAttribute('aria-invalid');
      }
    }

    nueva.addEventListener('input', pintar);
    repetir.addEventListener('input', pintar);

    /* Y no se deja enviar con dos contraseñas que ya se sabe que no coinciden:
       es el viaje al servidor que esta pantalla existe para ahorrar.

       Se corta SOLO en ese caso —las dos escritas y distintas—, que no admite
       otra lectura. Cualquier otra duda se deja pasar y la resuelve el
       servidor: un guion demasiado listo que se equivoque deja a alguien sin
       poder enviar el formulario, y eso es peor que un viaje de más. */
    form.addEventListener('submit', function (evento) {
      if (nueva.value === '' || repetir.value === '' || nueva.value === repetir.value) return;

      evento.preventDefault();
      pintar();
      repetir.focus();
      repetir.select();
    });

    // Al cargar: el formulario pudo volver del servidor con valores puestos.
    pintar();
  });
})();
