# Administrar el evento

Qué se puede cambiar una vez creado, quién puede tocar qué, y qué no tiene vuelta atrás:
eventos y jornadas, las propuestas de los expositores, el perfil Staff, la impresión de
carnets y la acreditación en la puerta.

Todo lo de aquí está en **Administración → Eventos**, **QR por día**, **Expositores** y
**Registros**, y exige el rol **administrador** salvo donde se diga otra cosa. Las dos
secciones del final —imprimir carnets y acreditar— las comparten el equipo y el Staff.

---

## 1. Por qué existe esta pantalla

La plataforma se prueba antes de usarse: se crean dos o tres eventos de mentira, se registran
personas inventadas, se escanean códigos para ver si el lector funciona. Todo eso se queda en
la base para siempre si no hay forma de borrarlo, y el listado de eventos acaba con tres
«Prueba 1» que nadie se atreve a tocar.

Lo mismo con las jornadas: un evento se alarga un día, se acorta otro, o la fecha se tecleó
mal. Hasta la versión 3.2 el número de días se fijaba al crear el evento y corregirlo obligaba
a instalarlo otra vez.

---

## 2. Eventos

### 2.1 Corregir los datos

El botón del lápiz, en la última columna. Se puede cambiar el nombre, la dependencia, la sede,
la fecha de inicio y el estado.

**La casilla «mover también las jornadas»** es la que importa al cambiar la fecha:

| Casilla | Qué pasa con los días |
|---|---|
| Sin marcar | Se quedan donde están. Es lo que se quiere al corregir un nombre o un estado |
| Marcada | Se desplazan **todos los mismos días** que la fecha de inicio |

Se conserva la separación entre jornadas a propósito. Un evento puede tener dos días seguidos
y uno de cierre la semana siguiente; recolocarlas una detrás de otra le destrozaría el
calendario a quien solo quería mover el arranque.

Cambiar la fecha **no** regenera los códigos QR. El código pertenece a la jornada, no a la
fecha, así que un pliego ya impreso sigue sirviendo. Si el pliego se filtró, se regenera
aparte desde *QR por día*.

### 2.2 Los estados

| Estado | Qué significa |
|---|---|
| **Borrador** | Todavía no se anuncia |
| **Abierto** | El registro está en marcha |
| **En curso** | El evento está pasando |
| **Cerrado** | Terminó |

El estado es informativo: lo que de verdad decide qué ven los asistentes es cuál está
**activo**.

### 2.3 Activar y desactivar

Solo hay un evento activo a la vez: es el que ven los asistentes en la portada, el que usan
los códigos QR y al que se suman los registros nuevos. Activar uno desactiva el anterior.

**Desactivar no borra nada** y se deshace con un clic. Mientras no haya ninguno activo:

- los asistentes ven la pantalla de «todavía no hay nada publicado»;
- el equipo organizador ve la invitación a crear o activar uno;
- nada de lo guardado se toca.

Es lo que conviene hacer con un evento que terminó y con uno de prueba que se quiere conservar
por si acaso.

### 2.4 Eliminar

El botón rojo. **No tiene vuelta atrás.** Antes de confirmar, el diálogo dice exactamente
cuánto se va a perder:

- jornadas
- personas registradas
- ingresos sellados
- propuestas de exposición
- contactos intercambiados

Y hay que **escribir ELIMINAR**. Se pide porque un botón suelto en una fila de tabla se pulsa
por error, y esto se lleva los datos personales de gente real.

Lo que se borra, por las claves foráneas de la base:

```
evento
 ├─ evento_tema        identidad visual y logo
 ├─ evento_dia         jornadas y sus códigos QR
 │   └─ asistencia     ingresos sellados
 │   └─ charla         agenda publicada
 └─ persona            registros
     ├─ credencial     carnets
     ├─ contacto       intercambios, en las dos direcciones
     ├─ propuesta      lo que enviaron los expositores, con sus adjuntos
     ├─ dispositivo    teléfonos recordados
     └─ codigo_acceso  códigos pendientes
```

Además se borran del disco las **fotografías** de esas personas, las **hojas de vida y
exposiciones** que adjuntaron los expositores, y el **logo** del evento. Sin eso, cada evento de
prueba dejaría sus archivos ocupando espacio para siempre sin que nadie supiera de quién eran
—y una hoja de vida trae teléfono, dirección y trayectoria laboral.

Lo que **no** se toca: el equipo organizador y sus roles, la bitácora de auditoría y la
configuración del servidor. Son de la instalación, no del evento.

La eliminación queda anotada en la bitácora con el nombre del evento y las cuentas de lo que
se llevó.

---

## 3. Jornadas

En **Administración → QR por día**.

### 3.1 Agregar

La tarjeta punteada al final de la rejilla. La fecha propuesta es el día siguiente al último,
que es lo que se agrega casi siempre, pero se puede poner cualquiera: un evento puede tener
dos jornadas seguidas y una de cierre la semana entrante.

La jornada nueva nace con su propio código QR y con el horario que se le dé.

### 3.2 Cambiar fecha u horario

Dentro de cada tarjeta, en «Cambiar fecha u horario». El horario es la ventana en la que el
código acepta ingresos: fuera de ella el escaneo se rechaza y queda en la bitácora.

No se admiten dos jornadas en la misma fecha.

### 3.3 Eliminar

Hay dos caminos según lo que tenga dentro:

**Sin ingresos** — se elimina con una confirmación normal.

**Con ingresos o charlas** — se puede igual, pero el botón dice cuántos registros se van con
ella y la confirmación lo repite. Los ingresos se borran en cascada; las **propuestas de los
expositores no**, y así tiene que ser: siguen aprobadas y se les puede asignar otro día.

Esto existe porque los días de prueba se llenan de escaneos justamente probando, y sin esta
salida la única forma de limpiar era entrar a la base de datos a mano.

**Lo que no se puede** es quedarse sin ninguna jornada: un evento sin días no tiene dónde
registrar un ingreso. Si el evento entero sobra, elimínalo desde *Eventos*.

**Los números no se renumeran.** Al borrar el día 2 de tres, quedan el 1 y el 3. Es
deliberado: el número está impreso en el pliego de la puerta y sale en el historial de cada
asistente; corregirlo haría que el «día 3» de un carnet señalara otra fecha.

---

## 4. Revisar las propuestas de los expositores

En **Administración → Expositores**. Exige rol **administrador**, y eso es lo que permite que
en esta pantalla aparezcan el teléfono y la cédula de quien propone.

### 4.1 La hoja de revisión

Pulsar una propuesta abre todo lo que hace falta para decidir, sin salir de la pantalla:

| Bloque | Qué trae |
|---|---|
| **La propuesta** | Título, categoría, detalle, día preferido, duración y requerimientos |
| **Quién la presenta** | Foto, perfil, correo, teléfono, identificación, entidad, territorio, cuándo se registró y cuándo envió la propuesta. Y un enlace a la **ficha completa**, con sus ingresos y su carnet |
| **Documentos de respaldo** | La hoja de vida y la exposición, para bajar |
| **Validar la participación** | La decisión, con el día, la hora y el salón que se le asignan |

Los datos de la persona van aquí y no en otra pantalla a propósito: decidir sobre una propuesta
es decidir sobre quién la presenta, y tener que abrir la ficha en otra pestaña para ver de qué
entidad viene convertía la revisión en un ir y venir.

Si el perfil de la persona dejó de decir «expositor» —porque lo cambió después de enviar la
propuesta— aparece marcado. No es un error, pero conviene saberlo antes de aprobar: el carnet se
imprime con lo que diga el perfil.

### 4.2 Las tres decisiones

| Botón | Qué pasa |
|---|---|
| **Aprobar y agendar** | La charla se publica en la agenda con el día, la hora y el salón de ese formulario, y el carnet sale con el rótulo de expositor |
| **Devolver con observaciones** | Se le regresa para que corrija lo que le escribas. La observación es obligatoria, y puede volver a enviarla |
| **Rechazar** | Queda fuera del evento. Pide confirmación |

**Ninguna es definitiva.** Se puede volver a entrar y cambiarla; al dejar de estar aprobada, la
charla se quita de la agenda.

### 4.3 Los dos documentos son obligatorios

Quien marca «voy a exponer» en el formulario **tiene que** adjuntar su **hoja de vida** (PDF) y
su **exposición** (PDF o PPTX). Los ve solo el equipo que revisa las propuestas; no se publican
en la agenda ni se comparten con los demás asistentes.

Sin los dos, el formulario no se envía: el archivo se comprueba con los demás campos, antes de
guardar nada. No hay propuesta a medias que alguien tenga que perseguir después por correo.

Quien no los tenga a mano en ese momento tiene una salida, y el propio formulario se la dice:
**registrarse sin marcar «voy a exponer»** y volver cuando los tenga. Queda registrado, con su
carnet, y la propuesta la manda otro día.

Tampoco se pueden **quitar** una vez subidos, solo reemplazar. Un botón que deja la propuesta
sin lo que la hace evaluable no tendría sentido.

Aun así, la lista marca **qué falta**: cada propuesta lleva dos etiquetas debajo del título, en
verde lo que llegó y en gris lo que no. Si ves alguna en gris es de antes de que fueran
obligatorios. Devuélvela con observaciones pidiendo el documento: el expositor entra a su
registro y lo sube.

### 4.4 Bajarlos

En **Administración → Expositores**, se pulsa la propuesta y se abre su hoja de revisión: los
dos archivos están ahí, en el bloque **«Documentos de respaldo»**, con el formato y el peso al
lado. No hay una pantalla aparte de anexos; van con la propuesta a la que pertenecen, que es
donde se decide sobre ellos. Se descargan en vez de abrirse
dentro de la página, y llegan con un nombre legible —`Hoja-de-vida-Lucia-Villota-Erazo.pdf`—
en lugar del que tienen en el disco del servidor.

Que se bajen y no se abran incrustados es deliberado: un PDF puede traer sus propios guiones, y
estos los subió alguien de fuera de la entidad.

### 4.5 Después de aprobar

El expositor **sigue pudiendo cambiar sus archivos** cuando su propuesta ya está aprobada y
agendada. Es justo cuando la mayoría tiene la presentación definitiva lista, y cambiar el
archivo no mueve nada de la agenda.

Lo que ya **no** puede cambiar desde el formulario es el tema, la categoría ni el día: están
publicados. El formulario se lo dice con un aviso, para que nadie edite el detalle, guarde, y
no entienda por qué no pasó nada.

### 4.6 Si un expositor dice que no puede subir su presentación

Casi siempre es el límite de PHP del servidor, no la plataforma. Mira el diagnóstico del
instalador (`/instalar/diagnostico`), fila **Tamaño máximo de subida**: si aparece en amarillo,
`upload_max_filesize` o `post_max_size` se quedaron cortos. Se suben en Plesk, en
**Dominio → Configuración de PHP**. Está en `docs/DESPLIEGUE-PLESK.md`, sección 2.

El otro caso es un archivo que no es lo que dice ser: un `.pptx` guardado en realidad como
`.odp`, o un PDF exportado por una herramienta que no escribe la cabecera al principio. El
formulario lo dice con esas palabras —«el archivo no es PDF o PPTX»— y no guarda nada a medias:
el archivo anterior se queda intacto y el resto del registro sí se graba.

---

## 5. El perfil Staff

En **Administración → Registros**, abriendo la ficha de la persona. Exige rol administrador.

### 5.1 Qué es

Es el único perfil de asistencia que cambia algo más que el rótulo del carnet. Quien lo tenga
entra **con su propio acceso de asistente** —el mismo correo y contraseña, o su QR— y además de
sus pantallas ve dos más:

- **Acreditar**, la pantalla de la puerta: lector de QR y búsqueda a mano.
- **Carnets**, la lista de todos los del evento, para consultarla e imprimirla.

Es para los voluntarios y el personal de apoyo: gente que trabaja en el evento pero a la que no
se le va a crear una cuenta del equipo organizador con contraseña y segundo factor.

Lo que **no** ve: el panel, los registros con su caracterización, las propuestas, los eventos,
la identidad ni la configuración. Nada de administración.

### 5.2 Cómo se pone

Abre la ficha de la persona desde *Registros*, en el bloque **Perfil de asistencia**: se elige
Staff y se pulsa Cambiar. Cada cambio queda en la bitácora con el perfil anterior y el nuevo.

Dos cosas que conviene saber:

- **Solo desde aquí.** El formulario público no ofrece ese perfil y tampoco lo acepta enviado a
  mano: si lo aceptara, cualquiera con el enlace del registro podría ver la cédula de todos los
  asistentes.
- **No se pierde solo.** Quien tiene el perfil puede entrar a «mis datos» y corregir su teléfono
  sin quedarse sin él. En su formulario aparece la etiqueta de su perfil en lugar del selector.

Para quitárselo, el mismo control: se elige otro perfil.

A quien todavía no ha completado su registro no se le puede poner: no podría entrar a acreditar,
porque la plataforma lo mandaría primero a llenar su nombre y su identificación.

---

## 6. Imprimir los carnets

En **Carnets**, en el menú de Administración —entre *Registros* y *QR por día*—. La misma
entrada la ve el Staff en su propio grupo.

### 6.1 La tanda

El botón **Imprimir** saca todos los carnets de la lista, **una sola cara por persona y con el
código QR dentro**. Caben seis por hoja tamaño carta; se imprime en cartulina y se recorta.

Una cara y no dos es lo que hace que la función sirva: el carnet individual tiene anverso y
reverso porque su dueño lo dobla por la mitad, pero para doscientos eso significa imprimir
cuatrocientas caras y aparearlas a mano.

Cada tarjeta lleva el perfil en grande, la foto, el nombre, la identificación, la entidad y el
código. El perfil y la foto van grandes a propósito: son lo que se mira a un metro de distancia
en una fila; el nombre se comprueba ya de cerca.

### 6.2 Imprimir solo una parte

El filtro de la lista llega hasta la impresión. Es lo que de verdad se usa: «los expositores»,
«los de la Alcaldía de Tumaco». Se filtra, se comprueba la cuenta y se imprime.

### 6.3 Quién queda fuera

Los registros a medias —quien creó su acceso con correo y contraseña y no llenó el formulario—
no se imprimen, y la pantalla dice cuántos son. Un carnet sin nombre ni identificación es una
cartulina en blanco que nadie ve hasta que la reparte.

---

## 7. Acreditar a quien llega sin nada

En **Acreditar** (el Staff) o **Escanear carnet** (el equipo).

Lo normal es leer el QR del carnet. Cuando no se puede —teléfono sin batería, código rayado, o
la persona llegó sin nada— está la búsqueda a mano, debajo del lector.

Se busca por **número de identificación**, nombre, correo o entidad. Con una diferencia que la
pantalla explica:

| Por | Cómo | A dónde lleva |
|---|---|---|
| **Identificación** | Completa. Con puntos o sin ellos, da igual, pero entera | **Directo a registrar el ingreso**, igual que leer el QR |
| Nombre, correo, entidad, municipio | Basta con una parte | A la lista, para elegir |

Que digitar la cédula lleve directo a la misma pantalla que el QR es deliberado: quien llega
sin carnet y sin teléfono tiene que poder acreditarse en los mismos pasos que quien lo trae, y
no en uno más por el camino. Con un nombre que da varias coincidencias sí sale la lista,
porque ahí elegir es de quien está en la puerta y no de la plataforma.

El número hay que escribirlo completo porque está cifrado en la base: lo que se compara es una
huella, y una huella o coincide entera o no coincide. Buscar por los últimos cuatro dígitos
obligaría a descifrar la tabla de todos los asistentes en cada búsqueda, que es justamente lo
que el cifrado evita.

Cada búsqueda queda en la bitácora con cuántos resultados dio: es una consulta de datos
personales hecha a mano.

---

## 8. Antes de abrir al público

Una lista corta para no llevarse sorpresas:

1. **Borra los eventos de prueba.** Un asistente no los ve —solo ve el activo—, pero el equipo
   sí, y en la lista de exportaciones también aparecen.
2. **Comprueba las fechas de las jornadas** en *QR por día*. Son las que deciden qué día sella
   cada escaneo.
3. **Imprime los pliegos después de fijar las fechas.** Cambiar la fecha no invalida el código,
   pero regenerarlo sí: si vas a regenerar, hazlo antes de imprimir.
4. **Deja el evento bueno activo** y los demás desactivados.
5. **Comprueba el tamaño máximo de subida** en el diagnóstico, si esperas exposiciones. Es lo
   único de esta lista que se arregla fuera de la plataforma, y por tanto lo que más tarda.
