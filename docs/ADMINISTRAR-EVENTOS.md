# Administrar el evento

Qué se puede cambiar una vez creado, quién puede tocar qué, y qué no tiene vuelta atrás:
eventos y jornadas, las propuestas de los expositores, el perfil Staff, la impresión de
carnets, la acreditación en la puerta, la verificación en dos pasos del equipo, la
recuperación de contraseñas, el formulario de registro, cómo se recorren los listados largos,
los tokens de la API con que otros sistemas consultan los registros, los certificados y cómo
funcionan varios eventos a la vez, cada uno con su equipo (14).

Todo lo de aquí está en **Administración → Eventos**, **QR por día**, **Expositores**,
**Registros** y **Configuración**, y exige el rol **administrador** salvo donde se diga otra
cosa. Desde la 3.22 ese rol es de cada evento: lo de aquí lo hace el administrador del evento
elegido, y lo que es de toda la plataforma —crear y eliminar eventos, el correo de salida— lo
hace solo el administrador global (14.4). Imprimir carnets y acreditar lo comparten el equipo y
el Staff. Registrar a quien llega sin registro, también con el registro cerrado, lo hacen el
operador de acceso y el administrador (9.10).

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

En el evento **principal** el estado es informativo: se ve siempre. En los demás,
**Borrador** además lo oculta: su dirección le responde «Evento no encontrado» a quien no es de
su equipo, para que se pueda armar sin que nadie lo vea (14.3). Ningún estado cierra el
registro: para dejar de recibir inscripciones nuevas está *Configuración → Registro* (9.9).

### 2.3 El evento principal

Hasta la 3.21 había un solo evento *activo* y los demás quedaban guardados. Desde la 3.22 pueden
funcionar varios a la vez, cada uno en su propia dirección (14.2), y uno de ellos es el
**principal**: el que se ve en la dirección de siempre de la plataforma, sin `/e/`. Los demás
siguen funcionando en la suya: sus códigos QR, sus carnets y sus registros no dependen de cuál
sea el principal.

**Hacer principal** (administrador global) lo cambia, y el anterior sigue en su dirección
propia. **Quitar como principal** no borra nada: mientras no haya ninguno marcado, la dirección
de siempre muestra el más reciente. Ningún evento se apaga por dejar de ser el principal: para
que el público deje de ver uno que terminó o uno de prueba que se quiere conservar, quítalo como
principal y pásalo a **Borrador** (2.2).

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

También se van su identidad, sus formularios, la configuración de sus certificados y los
**tokens de la API** que se crearon en él: un sistema que lo consultaba recibe desde ese momento
que el token no es válido.

Lo que **no** se toca: las cuentas del equipo, la bitácora de auditoría y la configuración del
servidor. Son de la instalación, no del evento. Quien trabajaba en ese evento pierde su rol en
él y conserva los de los demás; una cuenta que trabajaba solo ahí sigue existiendo, sin ningún
evento, hasta que la agreguen a otro equipo.

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

### 3.4 Los días siguientes se renumeran

Desde la versión 3.7.1, al eliminar un día **los que siguen toman el número consecutivo**: si
se elimina el día 1 de tres, el 2 pasa a ser el 1 y el 3 pasa a ser el 2. La confirmación lo
dice antes de hacerlo, día por día, y el mensaje de después lo repite.

Lo único que cambia es el número que se ve. Cada día conserva:

- **su fecha y su horario**;
- **su código QR**: los pliegos ya pegados en la entrada siguen funcionando. Lo que queda
  desactualizado es el número impreso en el pliego —decía «Día 2» y ahora es el día 1—, así que
  conviene reimprimirlo si se va a ver;
- **sus ingresos y sus charlas**, que en el historial de cada asistente y en la agenda salen con
  el número nuevo y la misma fecha.

**El día preferido de las propuestas** se traduce con su día: quien pidió el día 3 sigue
pidiendo esa misma fecha, que ahora se llama día 2. Si pidió justo el día que se eliminó, queda
«Sin día preferido», en vez de apuntar sin avisar a otra fecha.

**Si la pantalla estaba abierta en otra pestaña** cuando alguien eliminó un día, sus botones
todavía muestran los números de antes. Por eso cada botón —eliminar, cambiar fecha u horario,
regenerar el código— lleva también la identidad de su día: si el número cambió de dueño, no se
hace nada y la pantalla pide revisar la lista. Lo mismo al aprobar una propuesta, que elige la
jornada por su identidad y no por su número.

**Un evento que ya tenía huecos** —días que se eliminaron con una versión anterior, que no
renumeraba— no se toca solo al actualizar. *QR por día* lo avisa arriba, con los números
actuales, y ofrece **«Dejarlos seguidos»**: aplica la misma renumeración, con su confirmación,
y queda en la bitácora.

---

## 4. Revisar las propuestas de los expositores

En **Administración → Expositores**. Exige rol **administrador**, y eso es lo que permite que
en esta pantalla aparezcan el teléfono y la cédula de quien propone.

La pantalla tiene dos listas:

- **Las propuestas**, filtrables por estado: pendiente, con observaciones, aprobada o rechazada.
- **Los registrados como expositores sin propuesta todavía**, aparte y con su propio filtro,
  *Sin propuesta*. Son quienes tienen el perfil Expositor o llegaron por el enlace privado, pero
  no han enviado la suya. Pasa a menudo: con «Voy a exponer» marcado, el formulario pide la hoja
  de vida y la presentación, y quien no las tiene a mano se registra sin la casilla para volver
  después. De cada uno se ve el correo, el teléfono, la entidad, cuándo se registró y un botón a
  su ficha: son a quienes hay que escribirles. El panel avisa cuántos hay.

Quien llegó por el enlace privado de expositores lleva la marca **Por el enlace**, en las dos
listas.

### 4.1 La hoja de revisión

Pulsar una propuesta abre todo lo que hace falta para decidir, sin salir de la pantalla:

| Bloque | Qué trae |
|---|---|
| **La propuesta** | Título, categoría, detalle, día preferido, duración y requerimientos |
| **Quién la presenta** | Foto, perfil, correo, teléfono, identificación, entidad, territorio, cuándo se registró y cuándo envió la propuesta. Y un enlace a la **ficha completa**, con sus ingresos y su carnet |
| **Documentos de respaldo** | La hoja de vida y la exposición, para bajar |
| **Validar la participación** | La decisión, con el día, la hora y el salón que se le asignan, los ajustes a la propuesta y el aviso por correo |

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
charla se quita de la agenda. Al volver a abrir una propuesta ya aprobada, la jornada aparece en
el día que se le asignó —no en el que había pedido—, así que cambiar el salón o la hora no mueve
la charla de día sin querer. La lista de propuestas muestra también el día asignado; las que no
están agendadas dicen el día que piden.

### 4.3 El correo al expositor

Con cualquiera de las tres decisiones, **le llega un correo a quien presentó la propuesta**. La
casilla «Avisar a … por correo», encima de los botones, viene marcada; se desmarca para corregir
algo sin escribirle —por ejemplo, cambiar el salón de una charla ya aprobada y avisada—.

| Decisión | Asunto | Qué dice |
|---|---|---|
| Aprobar | «Tu propuesta fue aprobada: …» | El título con el que queda, el día, la hora y el salón, y el enlace a la agenda |
| Devolver | «Tu propuesta tiene observaciones: …» | Lo que tiene que corregir y el enlace a **«Mi exposición»** (4.9), donde la corrige y la vuelve a enviar |
| Rechazar | «Sobre tu propuesta: …» | Que no fue seleccionada, con el motivo si se escribió, y el enlace a «Mi exposición» |

El de aprobar le recuerda además que puede subir la presentación final en «Mi exposición».

En los tres casos van **las observaciones** del cuadro de texto y **los cambios** que se le
hicieron a la propuesta (abajo), cada uno en su línea: «Título: «A» pasa a ser «B»»,
«Duración: de 40 a 60 minutos», «Día: pediste el día 1; quedó el día 2 (2 sep 2026)». Esta
última solo va si el formulario le preguntó el día preferido.

Si el correo no sale, la decisión se guarda igual y la pantalla lo dice en amarillo: decidir no
depende del correo. Si el correo saliente no está configurado, la casilla lo advierte antes de
pulsar. Cada decisión queda en la bitácora, con los cambios y si se avisó.

### 4.4 Ajustar la propuesta al decidir

En el mismo bloque se pueden corregir **el título, la categoría y la duración** antes de aprobar
—un título demasiado largo para el programa, una charla de 60 minutos que solo cabe en 40—. Lo
que se cambie se guarda en la propuesta, sale así en la agenda, y se le cuenta al expositor en el
correo. Sin cambios, no se menciona nada.

### 4.5 Los dos documentos son obligatorios

Quien marca «voy a exponer» en el formulario **tiene que** adjuntar su **hoja de vida** (PDF) y
su **exposición** (PDF o PPTX). Los ve solo el equipo que revisa las propuestas; no se publican
en la agenda ni se comparten con los demás asistentes.

Sin los dos, el formulario no se envía: el archivo se comprueba con los demás campos, antes de
guardar nada. No hay propuesta a medias que alguien tenga que perseguir después por correo.

Quien no los tenga a mano en ese momento tiene una salida, y el propio formulario se la dice:
**registrarse sin marcar «voy a exponer»** y enviarla después desde **«Mi exposición»** (4.9).
Queda registrado, con su carnet, y la propuesta la manda otro día.

Tampoco se pueden **quitar** una vez subidos, solo reemplazar. Un botón que deja la propuesta
sin lo que la hace evaluable no tendría sentido.

Aun así, la lista marca **qué falta**: cada propuesta lleva dos etiquetas debajo del título, en
verde lo que llegó y en gris lo que no. Si ves alguna en gris es de antes de que fueran
obligatorios. Devuélvela con observaciones pidiendo el documento: el expositor entra a «Mi
exposición» y lo sube.

### 4.6 Bajarlos

En **Administración → Expositores**, se pulsa la propuesta y se abre su hoja de revisión: los
dos archivos están ahí, en el bloque **«Documentos de respaldo»**, con el formato y el peso al
lado. No hay una pantalla aparte de anexos; van con la propuesta a la que pertenecen, que es
donde se decide sobre ellos. Se descargan en vez de abrirse
dentro de la página, y llegan con un nombre legible —`Hoja-de-vida-Lucia-Villota-Erazo.pdf`—
en lugar del que tienen en el disco del servidor.

Que se bajen y no se abran incrustados es deliberado: un PDF puede traer sus propios guiones, y
estos los subió alguien de fuera de la entidad.

### 4.7 Después de aprobar

El expositor **sigue pudiendo cambiar sus archivos** cuando su propuesta ya está aprobada y
agendada. Es justo cuando la mayoría tiene la presentación definitiva lista, y cambiar el
archivo no mueve nada de la agenda.

Lo que ya **no** puede cambiar él es el tema, la categoría, el detalle, el día ni la duración:
están publicados, y los mueve el comité volviendo a decidir. «Mi exposición» se los muestra
bloqueados y se lo dice con un aviso, para que nadie edite el detalle, guarde, y no entienda por
qué no pasó nada. Los requerimientos sí los puede cambiar.

### 4.8 Si un expositor dice que no puede subir su presentación

Casi siempre es el límite de PHP del servidor, no la plataforma. Mira el diagnóstico del
instalador (`/instalar/diagnostico`), fila **Tamaño máximo de subida**: si aparece en amarillo,
`upload_max_filesize` o `post_max_size` se quedaron cortos. Se suben en Plesk, en
**Dominio → Configuración de PHP**. Está en `docs/DESPLIEGUE-PLESK.md`, sección 2.

El otro caso es un archivo que no es lo que dice ser: un `.pptx` guardado en realidad como
`.odp`, o un PDF exportado por una herramienta que no escribe la cabecera al principio. El
formulario lo dice con esas palabras —«el archivo no es PDF o PPTX»— y no guarda nada a medias:
el archivo anterior se queda intacto y el resto del registro sí se graba.

### 4.9 Lo que el expositor cambia después: «Mi exposición»

Ya registrado, cada expositor tiene **«Mi exposición»** (`/exposicion`): en su menú, en un aviso
de su carnet y en «Mis datos», donde su propuesta sale resumida con el camino. Ahí ve su
propuesta como la envió, con su estado y lo que le dijo el comité, y cambia por separado los datos
de la exposición y sus dos documentos: para reemplazar la presentación no tiene que volver a
escribir nada.

| Estado | Qué puede hacer |
|---|---|
| Sin propuesta | Enviarla, con sus dos documentos. Es la salida de quien se registró sin ellos |
| En revisión | Cambiarla y cambiar sus documentos. Sigue en revisión |
| Con observaciones | Ver lo que pidió el comité, corregirla y guardar: **vuelve a revisión** |
| Aprobada | Ver el día, la hora y el salón; cambiar sus documentos y los requerimientos. Lo publicado lo mueve el comité |
| No seleccionada | Ver lo que dijo el comité. Si la cambia y la guarda, vuelve a revisión, como siempre |

Para el comité:

- Una propuesta que el expositor cambió después de la última revisión lleva en la lista la marca
  **«Actualizada por el expositor»**, con la fecha: hay algo nuevo que mirar. Sale también con
  una aprobada a la que se le cambió la presentación.
- Cada envío y cada cambio quedan en la bitácora, y se ven en la actividad reciente del panel:
  «Un expositor envió su propuesta», «Un expositor actualizó su propuesta y sus documentos».
- Cada expositor ve y cambia solo lo suyo: la pantalla no recibe ningún número de propuesta,
  trabaja con su sesión.

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

Con más de 300 carnets en la lista, en vez de un botón hay uno por tanda: **1–300**,
**301–600**… Cada código QR se dibuja en el servidor, y miles de una vez harían que la página
tardara más de lo que el servidor deja correr. La pantalla de impresión dice qué tanda es y
tiene **Siguiente tanda ›** para pasar a la otra. Hasta la 3.12 no había tandas, pero la
impresión se cortaba en 500 sin avisar.

Una cara y no dos es lo que hace que la función sirva: el carnet individual tiene anverso y
reverso porque su dueño lo dobla por la mitad, pero para doscientos eso significa imprimir
cuatrocientas caras y aparearlas a mano.

Cada tarjeta mide **6 × 9,5 cm** y lleva, de arriba abajo: el logo y el nombre del evento, el
perfil en una banda de su color, la foto y el código QR lado a lado, el nombre, la
identificación y la entidad, y al pie el número del carnet. El perfil y la foto van grandes a
propósito: son lo que se mira a un metro de distancia en una fila; el nombre se comprueba ya de
cerca.

**Nada se sale de la tarjeta.** Cada texto tiene su caja: si un nombre o una entidad son
largos, la letra se achica hasta que quepan, y solo si ni así caben se cortan con «…». Hasta la
3.14, un nombre largo empujaba el código por debajo de la tarjeta y se imprimía encima de la
siguiente.

Los colores van sobre papel blanco aunque el tema del evento sea oscuro, y cada perfil conserva
su color, oscurecido lo justo para que se lea impreso.

### 6.2 Imprimir solo una parte

El filtro de la lista llega hasta la impresión. Es lo que de verdad se usa: «los expositores»,
«los de la Alcaldía de Tumaco». Se filtra, se comprueba la cuenta y se imprime.

### 6.3 Quién queda fuera

Los registros a medias —quien creó su acceso con correo y contraseña y no llenó el formulario—
no se imprimen, y la pantalla dice cuántos son. Un carnet sin nombre ni identificación es una
cartulina en blanco que nadie ve hasta que la reparte.

La lista de carnets va por páginas y se ordena desde los títulos de sus columnas, igual que
Registros (ver 11). La impresión respeta el filtro y el orden.

### 6.4 El carnet de cada persona: imprimirlo o descargarlo

Cada asistente tiene en su carnet el botón **Imprimir**, que lleva a **Tu carnet**
(`/carnet/imprimir`): las dos caras a tamaño real, 6 × 9,5 cm cada una, juntas. Ahí hay dos
caminos:

- **Descargar imágenes** —el primero, y el recomendado—: baja dos imágenes PNG, el frente y el
  reverso, de 6 × 9,5 cm a 300 ppp. Es lo que pide una papelería o una imprenta para un carnet,
  incluido uno en plástico, y evita los tropiezos de imprimir desde el navegador. En el celular
  quedan en las descargas y se pueden enviar por WhatsApp. Si el navegador solo deja bajar una,
  la pantalla muestra las dos con un enlace para cada una.
- **Imprimir**: en cartulina, se recorta por la línea continua y se dobla por la punteada del
  medio. Queda un carnet con la foto de un lado y el código del otro. En la ventana de
  impresión, la escala tiene que quedar en 100 % (la predeterminada); con «Ajustar a la
  página», la tarjeta sale de otro tamaño.

El frente lleva el logo y el nombre del evento, la **foto**, el perfil en una banda de su color,
el nombre, la identificación y la entidad. El reverso, el código QR, el nombre y la entidad otra
vez —para que se lea aunque el carnet quede del revés en la funda— y la identificación.

Hasta la 3.14 esta página no ponía la foto —siempre salían las iniciales—, y al imprimir el
nombre, la identificación y la entidad quedaban por fuera del recuadro. Ahora lo que se ve en la
pantalla es exactamente lo que sale en el papel y en las imágenes.

**La foto se ve igual en todas partes.** Se guarda cuadrada, tal como la persona la encuadra en
el formulario, y así sale en el carnet en pantalla, en el impreso y en las imágenes. Hasta la
3.18 el carnet en pantalla la ponía en un marco apaisado y le cortaba la parte de abajo: la gente
veía su foto distinta a como la había encuadrado, aunque al imprimir saliera bien.

**Cambiar la foto.** La persona la cambia cuando quiera desde **Mis datos**, con **Cambiar la
foto**: elige otra, la encuadra y guarda. La nueva reemplaza a la anterior —que se borra del
servidor— y se ve en el acto en su carnet, en el impreso, en las imágenes y en la ficha del
equipo. Hasta la 3.18.1 la nueva se guardaba pero no se veía: el navegador seguía enseñando la
anterior, que guarda en su memoria hasta un día, y parecía que no dejaba reemplazarla. Si marca
«Quitar la foto actual» y además elige otra, queda la nueva; antes se perdía sin aviso y el
carnet quedaba con las iniciales. Con el logo del evento, en **Identidad**, pasa lo mismo: el
nuevo se ve en el acto.

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

Si la búsqueda no encuentra a nadie, la persona no está registrada. Desde la 3.18, el equipo
—operador de acceso o administrador— la registra ahí mismo con «¿No está registrada?
Regístrala ›», también con el registro cerrado, y al guardar tiene a mano **Acreditar su
ingreso** (9.10).
El Staff no registra: se lo pide a alguien del equipo.

---

## 8. La verificación en dos pasos del equipo

Las cuentas administradoras entran con contraseña y con el código de seis dígitos de una
aplicación del teléfono (Google Authenticator, Authy, FreeOTP…). Para operador y consulta es
opcional.

### 8.1 Cuando el código correcto no entra

Desde la versión 3.6 la pantalla dice cuál es el problema, en vez de mandar siempre a revisar
el reloj del teléfono:

| Lo que dice | Qué pasó | Qué hacer |
|---|---|---|
| «Ese código ya se usó» | Se entró con él hace un momento —lo normal después de una actualización, que cierra las sesiones— | Esperar a que la aplicación muestre el siguiente (cambia cada 30 segundos) |
| «La hora de este servidor no coincide con la de tu teléfono» | El reloj del servidor está corrido | Escribir el código siguiente: con eso la plataforma confirma y desde ahí compensa sola. Conviene avisar a sistemas para que active la hora automática del servidor |
| «La configuración de tu verificación en dos pasos no se puede leer» | La llave de cifrado de la instalación cambió | Entrar con un código por correo (abajo) y escanear un QR nuevo |
| «El código no coincide» | El código es de otra cuenta o de otra entrada de la aplicación | Revisar que sea la entrada de esta plataforma; si no aparece, código por correo |

### 8.2 Entrar con un código por correo

Debajo del campo del código, **«Enviar un código a mi correo»**. Llega un código de seis
dígitos que vence en diez minutos y sirve una sola vez. Solo se ofrece después de escribir la
contraseña, nunca en su lugar, y solo si el correo saliente está configurado. Al entrar así,
la plataforma lleva a **Configuración → Mi cuenta** para que vuelvas a dejar la aplicación en
orden.

Si el mensaje te llega sin haberlo pedido, alguien escribió tu contraseña correcta: cámbiala.

Durante las 24 horas siguientes a cambiar la contraseña con un enlace del correo (8.5), esta
opción no aparece para esa cuenta: el segundo paso tiene que ser la aplicación. Si no, quien
tuviera acceso al buzón entraría al panel solo con eso.

### 8.3 Mi cuenta: restablecer el código QR

**Administración → Configuración → Mi cuenta**, para todo el equipo. Sirve cuando se cambió de
teléfono, se borró la aplicación, o el código no funciona:

1. Escribe tu contraseña actual y pulsa **Restablecer: generar un código QR nuevo**.
2. Escanéalo con la aplicación (o escribe a mano el código que aparece al lado).
3. Escribe el código que te muestre y confirma.

Hasta que confirmes, **el código anterior sigue valiendo**: si cierras la pestaña a la mitad,
todo queda como estaba. Al confirmar se cierran tus demás sesiones abiertas. Después, borra de
la aplicación la entrada vieja de esta cuenta.

Desde ahí mismo se cambia la contraseña.

### 8.4 Si alguien del equipo no puede entrar

Si perdió el teléfono y el código por correo no le llega, una cuenta administradora puede
quitarle la verificación desde **Organizadores → Restablecer 2FA** —el administrador de un
evento, a quien trabaja solo en el suyo; el global, a cualquiera (14.5)—. Se le cierran las sesiones
y, al entrar con su contraseña, escanea un QR nuevo. La tuya propia se restablece en
Configuración → Mi cuenta, no desde ahí. Queda en la bitácora.

Por consola, quien tenga acceso al servidor: `php herramientas/cuenta.php sin-2fa --correo=…`.

### 8.5 Cuando alguien olvidó su contraseña

Desde la 3.12 se recupera con un enlace al correo, en las dos puertas. Nadie puede *ver* una
contraseña —se guarda una huella irreversible—, pero sí poner una nueva:

| Quién | Lo hace la propia persona | Lo hace un administrador |
|---|---|---|
| **Asistente** | En **Entrar → Contraseña**, «¿Olvidaste tu contraseña?». Escribe su correo y le llega un enlace | En **Registros**, su ficha → **«Enviarle un enlace por correo»** |
| **Equipo organizador** | En el acceso del equipo, «¿Olvidaste tu contraseña?» | En **Organizadores**, en su fila → **«Enviar enlace»** |

Cómo funciona el enlace:

- **Sirve una sola vez y vence**: en una hora si lo pidió la persona, en 24 horas si se lo
  enviaste tú. Si se pide otro, el anterior deja de servir: que use siempre el último que llegó.
- **Su contraseña actual sigue sirviendo** hasta que ponga la nueva. Enviar el enlace no le
  quita el acceso a nadie.
- Al poner la nueva, **se cierran sus sesiones abiertas** en otros teléfonos o computadores.
- El **asistente** queda dentro al guardarla. La cuenta del **equipo** no: entra después con la
  contraseña nueva y el código de su aplicación, como siempre. Si además perdió el teléfono,
  restablécele el segundo factor (8.4): durante un día después de usar el enlace no puede
  entrar con un código al correo.
- La pantalla donde se pide responde lo mismo exista o no el correo. Si alguien dice que no le
  llega: que revise no deseado, que use el correo con el que se registró, y comprueba en
  **Configuración → Acceso y correo** que el correo salga. El botón del panel sí dice en el
  momento si el correo no salió.
- A una cuenta del equipo **suspendida** no se le envía: reactívala antes.
- Si el acceso de asistentes con contraseña está apagado (Configuración → Acceso y correo), no
  hay contraseña que recuperar: entran con un código al correo o con su QR, y la ficha no
  ofrece el enlace.

Para alguien que está delante y no tiene su correo a mano sigue estando **«Generar una
contraseña nueva»** en la ficha, que se muestra una sola vez para dictársela.

Cada pedido, cada envío —con quién lo hizo— y cada uso quedan en la bitácora.

---

## 9. Configuración

Desde la versión 3.7, todo lo que se ajusta una vez y no se toca a diario está en una sola
entrada del menú, **Administración → Configuración**, con seis pestañas:

| Pestaña | Qué se configura | Quién |
|---|---|---|
| **Registro** | El formulario de registro del evento activo: campos, listas y banner, y si el registro está abierto, oculto o cerrado (9.9) | Administrador |
| **Registro de expositores** | El formulario privado de los expositores (9.8): su enlace, y sus propios campos, listas y banner | Administrador |
| **Identidad** | Colores, tipografía y logo del evento, con la revisión de contraste | Administrador |
| **Acceso y correo** | Las formas de entrar de los asistentes y el envío de correo. Son de toda la plataforma, no de un evento | Administrador global |
| **API** | Los tokens con que otros sistemas consultan los registros del evento en JSON (12) | Administrador |
| **Certificados** | Abrirlos, sus textos, horas, firma y diseño (13) | Administrador |
| **Mi cuenta** | La verificación en dos pasos y la contraseña de quien está dentro | Todo el equipo |

Las direcciones de antes —`/admin/identidad`, `/admin/autenticacion`, `/admin/cuenta`— siguen
funcionando: un enlace guardado o un correo viejo no se rompen. Quien no es administrador entra
directo a *Mi cuenta*, que es lo único de aquí que le corresponde.

Todo, salvo *Acceso y correo* y *Mi cuenta*, es **del evento elegido** (14.1): la identidad, el
registro o los certificados de un evento no tocan los de otro, y uno nuevo empieza con los de
fábrica.

### 9.1 Registro: qué campos se piden

Cada campo del formulario tiene tres estados posibles:

| Estado | En el formulario |
|---|---|
| **Obligatorio** | Aparece con asterisco y no se puede enviar vacío |
| **Opcional** | Aparece y se puede dejar en blanco |
| **Oculto** | No aparece |

Los que se configuran: identificación (tipo y número), entidad u organización, teléfono, perfil
de asistencia, fotografía, rango de edad, departamento y municipio, género, grupo étnico,
discapacidad, la propuesta de exposición y, dentro de ella, el día preferido, la duración y los
requerimientos técnicos. Cuando a un campo no le cabe ser obligatorio —el perfil, la casilla
de exponer— las opciones son *Visible* u *Oculto*.

Desde la 3.8 **la entidad va en los datos principales**, debajo de la identificación, y no
plegada con la caracterización: es lo que sale en el carnet debajo del nombre.

Tres cosas no se pueden cambiar, y la pantalla dice por qué:

- **El correo, el nombre y la autorización de tratamiento de datos se piden siempre.** Sin
  correo no hay forma de entrar, sin nombre no hay carnet, y sin autorización la Ley 1581 no deja
  guardar nada.
- **El género, la pertenencia étnica y la discapacidad se pueden pedir, nunca exigir.** Son datos
  sensibles, y el artículo 6 de la Ley 1581 deja al titular la libertad de no responderlos. Aunque
  alguien manipule el envío para marcarlos obligatorios, el servidor no lo acepta.
- **El perfil Staff no se ofrece nunca en el formulario** (sección 5).

**La identificación se puede dejar opcional u oculta** —un taller abierto, una charla para
colegios—. Quien se registra sin ella recibe su carnet igual, que sale con su código en lugar del
número. Lo que se pierde, y la pantalla lo advierte: no se le puede acreditar en la puerta por su
número, ni detectar si se registró dos veces.

**La fotografía, si se vuelve obligatoria,** se revisa con los demás campos: un archivo que no
sirve —una foto HEIC del iPhone, una de más de 6 MB— se dice en el momento y no se guarda nada.
Opcional, como viene de fábrica, el registro se guarda igual y solo se avisa que la foto no
entró. Con la foto obligatoria tampoco se ofrece «quitar la foto actual».

### 9.2 Registro: qué opciones trae cada lista

En *Opciones de las listas*, cada lista desplegable se abre y se cambia:

| Lista | Cómo se edita |
|---|---|
| Tipos de documento | Activar o apagar cada uno, cambiar cómo se muestra, y agregar otros con su sigla —«PPT», Permiso por Protección Temporal—. La sigla es lo que sale en el carnet. La cédula y la tarjeta de identidad se validan como solo números; los demás admiten letras |
| Perfiles de asistencia | Agregar, cambiar el nombre, apagar y eliminar (sección 9.3) |
| Género, grupo étnico, discapacidad | Activar, apagar, cambiar el texto y agregar. «Prefiero no responder» no se puede apagar |
| Rangos de edad, categorías de las propuestas | Una opción por línea, en el orden en que se muestran |
| Departamentos y municipios | Un departamento por línea y, debajo, sus municipios con un guion: «Nariño» y en la siguiente «- Pasto» |
| Duraciones de las exposiciones | En minutos, separadas por comas: «20, 40, 60» |

**Las opciones de fábrica no se borran, se apagan.** Hay registros que las tienen guardadas, y
borrarlas dejaría esos datos sin nombre en los reportes. Los perfiles son la excepción, con sus
propias reglas.

### 9.3 Los perfiles de asistencia

El perfil es lo que sale en grande en el carnet —«EXPOSITOR», «PRENSA»—, lo que se mira a un
metro en la fila. De fábrica vienen **Participante, Visitante, Expositor, Prensa, Rueda de
Negocios y Comunicaciones**, y cada evento los ajusta en *Opciones de las listas → Perfiles de
asistencia*:

| Para | Cómo |
|---|---|
| **Agregar** uno | Se escribe su nombre en una fila vacía —«Aliados estratégicos»— y se guarda. Sale encendido |
| **Cambiar el nombre** | Se corrige en su fila. Quien ya lo tiene pasa a llevar el nombre nuevo en su carnet, en las listas y en la exportación |
| **Apagar** | Se desmarca *Activo*. Deja de ofrecerse en el formulario, pero quien ya lo tiene lo conserva y un administrador lo puede seguir poniendo desde la ficha. Sirve para un perfil que solo asigna la organización |
| **Eliminar** | Se marca *Eliminar* y se guarda. Solo se puede con uno que **no tenga nadie**: la columna *Lo tienen* dice cuántas personas lo llevan, y en lugar de la casilla dice *En uso* |

Lo que no se puede, y la pantalla lo dice:

- **Participante** no se apaga ni se elimina: es el de quien no elige ninguno. **Expositor** se
  apaga, pero no se elimina: va con las propuestas de exposición. Los dos se pueden renombrar.
- **Staff y Organizador no están en la lista.** Los pone un administrador desde la ficha de la
  persona (sección 5), y ningún perfil puede llamarse como ellos: un «Staff» de mentira en un
  carnet confundiría en la puerta.
- **Dos perfiles no pueden llamarse igual**, y el nombre tiene un máximo de 30 caracteres, para
  que quepa en el carnet.

Por dentro, cada perfil tiene una clave que no cambia aunque cambie el nombre —«Rueda de
Negocios» se guarda como `rueda_de_negocios`—, así que renombrar no le mueve nada a nadie. Los
perfiles que agrega el evento toman en el carnet el color de acento del evento; los de fábrica
tienen el suyo.

**Volver al formulario de fábrica** (9.6) devuelve también la lista de perfiles de fábrica, con
una excepción: los perfiles que agregó el evento y que alguien ya tiene se conservan, con su
nombre. El mensaje dice cuáles.

### 9.4 Lo que ya está registrado no cambia

Cambiar el formulario no toca a nadie que ya se registró:

- **Se guarda el valor, no la posición.** Una opción que se quite de una lista sigue apareciendo
  en los datos de quien la eligió, en su ficha y en la exportación.
- **Si alguien vuelve a editar su registro**, su opción guardada sigue ahí aunque ya no se
  ofrezca, para que no tenga que cambiarla por obligación.
- **Un campo que se oculta conserva lo que ya tenía.** Ocultar el teléfono no borra el teléfono
  de quien ya lo dio; simplemente no se le vuelve a preguntar.

### 9.5 El banner

Una imagen ancha arriba del formulario, antes del primer campo, con un título y un texto corto
si se quiere —«Inscripciones abiertas hasta el 30 de octubre»—.

- JPG, PNG o WEBP, de hasta 6 MB. **1600 × 400 px** se ve bien en el computador y en el celular;
  una más grande se reduce sola.
- La imagen se vuelve a dibujar en el servidor, como la fotografía del carnet: lo que se guarda
  es una imagen limpia, sin los metadatos ni nada escondido del archivo original.
- **La descripción** es lo que oye quien usa un lector de pantalla. Conviene escribirla.
- La casilla *Mostrar el banner* lo enciende y lo apaga sin perder la imagen. *Quitar esta
  imagen* la borra del servidor.

### 9.6 Volver al de fábrica

Con el formulario cambiado aparece, abajo, **«Volver al formulario de fábrica»**: los campos y
las listas vuelven a ser los de la plataforma —los mismos que tenía el evento antes de
configurarlo—. El banner se conserva, porque se puso a propósito, y también los perfiles que
agregó el evento y que alguien ya tiene (9.3). Pide confirmación y queda en la bitácora, igual
que cada vez que se guarda.

Cada evento tiene su propio formulario. Al crear uno nuevo arranca con el de fábrica, aunque el
anterior estuviera configurado.

### 9.7 Cuando a alguien le falta un dato

Si quien se registra pulsa *Completar registro* —o *Guardar cambios*— con un campo obligatorio
vacío, **el formulario no se envía y se abre una ventana**: «Tu registro no fue guardado. Por
favor registra los datos completos», con la lista de lo que falta. Su botón, *Completar los
datos*, lleva al primer campo pendiente, y abre la sección plegada si estaba ahí dentro.

Lo mismo cuando el envío llega al servidor y algo no pasa —una cédula con letras, un correo
repetido—: la página vuelve con la ventana abierta y los errores dentro. Lo que se había escrito
se conserva.

Lo obligatorio es lo de la sección 9.1: la ventana sigue a la configuración del evento.

**Sin municipio, a «Mis datos» (desde la 3.19).** Quien ya está registrado y no ha registrado su
municipio es llevado a **Mis datos** al entrar a la plataforma, con el aviso «Te falta registrar
tu municipio». Llega con la sección de caracterización abierta y la página puesta en
*Departamento y municipio*. Así se completa la cobertura territorial que piden los informes sin
tener que escribirle a nadie.

- Pasa al entrar: al iniciar sesión —con el código, la contraseña o su QR de acceso— o al abrir
  la plataforma con la sesión ya abierta, en su carnet o en la portada.
- **Una vez al día.** Desde *Mis datos*, *Mi carnet* lo lleva a su carnet con un toque y no lo
  vuelve a sacar ese día. En la puerta lo que tiene que enseñar es el carnet, y esto nunca se
  lo impide. Al día siguiente, o al iniciar sesión otra vez, se le vuelve a pedir, hasta que lo
  registre.
- Quien acaba de registrarse o de guardar *Mis datos* no es llevado de vuelta ese día, aunque
  haya dejado el municipio en blanco: acaba de ver el formulario.
- Escanear el QR de la jornada o el carnet de otra persona no pasa por aquí: el ingreso y el
  intercambio de contacto se registran primero.
- Si el evento oculta *Departamento y municipio* en **Configuración → Registro**, no se pide.
  Para que además sea obligatorio al guardar, ponlo en *Obligatorio* ahí mismo (sección 9.1).

### 9.8 El formulario privado de expositores

Desde la 3.9 hay un **segundo formulario de registro, solo para quienes van a exponer**. Es el
mismo formulario —los mismos campos, la misma ventana si falta algo, el mismo carnet al
terminar—, con dos diferencias:

- **No está en ningún menú.** Se llega a él solo con su enlace, que se envía aparte, por correo o
  por WhatsApp, a cada expositor. El enlace lleva un código al azar imposible de adivinar:
  `…/registro/expositores/3f9c…`.
- **Tiene su propia configuración**, en *Configuración → Registro de expositores*. Lo que se
  cambie ahí —un campo obligatorio, una categoría de propuesta, el banner— no toca el formulario
  público, y al revés.

**El enlace** está arriba de esa pestaña, con un botón para copiarlo y otro para abrirlo. También
en *Expositores* hay una línea que lleva a él. Si se filtra —se compartió en un grupo abierto,
se publicó por error—, **«Generar un enlace nuevo»** lo cambia: el anterior deja de abrir el
formulario en el acto y responde como una dirección que no existe. Quien ya se registró no
pierde nada; a quienes falten hay que enviarles el nuevo. Queda en la bitácora.

**Quien entra por el enlace:**

| Situación | Qué pasa |
|---|---|
| Llega nuevo | Queda con el perfil **Expositor**, y «Voy a exponer» viene marcado: la propuesta está lista para llenar |
| Ya estaba registrado como participante | Al guardar por el enlace, pasa a **Expositor** |
| Tiene otro perfil especial —Prensa, Staff— | Lo conserva |
| Su correo ya está registrado y no ha entrado | Se le ofrece entrar con su código, y vuelve a este mismo formulario |

De fábrica, este formulario **no pregunta el perfil** —quien llega por ahí es expositor—; si se
pone *Visible*, ofrece los perfiles del evento con Expositor elegido. Los **perfiles de
asistencia** son del evento, no de un formulario: se configuran solo en la pestaña *Registro* y
este los usa tal cual.

**La identificación**, si este formulario la deja opcional, también deja completo el registro de
quien no la dio: recibe su carnet sin que la plataforma lo mande a completar el formulario
público, que sí la pide. El documento cuenta para dar por completo un registro solo cuando los
dos formularios lo exigen.

**Dónde se ven.** Quien se registra por el enlace queda en las mismas tablas que cualquier otro
asistente —persona, propuesta, carnet—, con los mismos datos. No hay una lista aparte: se ve y
se filtra en todos los módulos.

| Módulo | Cómo encontrarlo |
|---|---|
| Registros | Filtro de perfil **Expositor**, o buscándolo por nombre, correo, entidad o documento. Su ficha, como la de cualquiera |
| Exportación | La de *Registros*, con el mismo filtro de perfil |
| Carnets | Filtro de perfil **Expositor** |
| Acreditar | Por su carnet, o buscándolo por nombre o documento |
| Panel | Cuenta en *Expositores*, y su propuesta en *por aprobar*. En la actividad reciente sale «Nuevo registro por el enlace de expositores» |
| Expositores | Su propuesta, con las demás, filtrable por estado y con el mismo correo de decisión (sección 4). Si se registró sin propuesta, aparece aparte, en *Sin propuesta*. Las dos con la marca **Por el enlace** |
| Agenda | Al aprobarla, su charla, filtrable por día y por categoría |

Al revisar una propuesta, el comité puede elegir entre **las categorías y duraciones de los dos
formularios**: la propuesta pudo llegar por cualquiera. Y el correo de una propuesta **devuelta**
lleva a «Mi exposición» (4.9), que funciona igual llegue por donde llegue: ahí se corrige y se
vuelve a enviar, tenga o no el formulario público la sección «Voy a exponer».

### 9.9 Abrir, ocultar o cerrar el registro público

Desde la 3.17, arriba de *Configuración → Registro* está **Registro público**, con tres estados
y su propio botón, **Guardar el estado del registro**, que no toca los campos ni el banner:

| Estado | Qué ve el público | ¿Se registra alguien nuevo? |
|---|---|---|
| **Abierto** (de fábrica) | La portada ofrece registrarse y el menú tiene *Registro*, como siempre | Sí: desde la portada, el menú o el enlace |
| **Oculto** | La portada, el menú y la pantalla de entrada ya no lo ofrecen | Solo quien tenga el enlace `…/registro` |
| **Cerrado** | La portada dice que el registro está cerrado, y `…/registro` también | Nadie por su cuenta: ni con el formulario, ni con el enlace, ni creando un acceso. El equipo sí (9.10) |

**Quien ya se registró no pierde nada** con ninguno de los tres: entra con su correo, ve su
carnet, corrige sus datos y, si solo había creado su acceso, termina su registro.

**Los textos cambian solos.** Con el registro oculto o cerrado, la portada deja de invitar a
registrarse y le habla a quien ya lo hizo: que entre, **complete su perfil** —sus datos y su
foto— y **lleve su carnet, impreso o en el celular: con él ingresa al evento**. En los tres
estados, la portada y el carnet de cada persona le recuerdan llevarlo y le dicen qué le falta
para tener el perfil completo: su foto, su entidad, su teléfono o su municipio, solo los que el
formulario pide. La caracterización no se le pide: es opcional por ley.

**El mensaje para el público** es opcional. Lo que escribas —«Cupo completo: el registro cerró el
25 de octubre»— sale en la portada y en el registro mientras no esté abierto, junto a esos
textos. Al volver a abrirlo deja de mostrarse, y queda guardado para la próxima vez.

**Cerrado no es solo esconder el botón.** El servidor lo comprueba en cada puerta: el formulario,
`…/preregistro` —la dirección de los correos viejos—, «Crear mi acceso» y cualquier envío hecho a
mano. Nadie nuevo queda registrado.

**El formulario de expositores (9.8) no cambia**: su enlace privado sigue funcionando aunque el
público esté cerrado. Para cerrarlo a él, genera un enlace nuevo y no lo envíes.

Mientras no esté abierto, el **panel** lo recuerda en *Pendientes*, y cada cambio queda en la
bitácora: quién abrió, ocultó o cerró el registro.

**Para registrar a alguien con el registro cerrado**, lo hace el equipo: ver 9.10. No hace falta
abrirlo ni ocultarlo.

### 9.10 Registrar a una persona desde el equipo

Desde la 3.18, el **operador de acceso** y el **administrador** registran a quien llegue sin
registro, **también con el registro público cerrado**: se cierra para el público, no para el
equipo. Es el mismo formulario, con los campos de *Configuración → Registro*, y está en:

- *Registros* → **Registrar persona**;
- *Escanear carnet* → **Registrar a una persona**, y en «¿No está registrada? Regístrala ›» cuando
  una búsqueda no encuentra a nadie;
- la página de registro cerrado y la portada, que a quien es del equipo le muestran el botón.

Se llena **con la persona delante**. Cambia lo que tiene que cambiar cuando quien escribe no es
ella:

- **No abre su sesión en tu navegador.** Al guardar vuelves al formulario vacío, listo para la
  siguiente, con una tarjeta de la que acabas de registrar: **Acreditar su ingreso** —su ficha
  de acreditación, para sellar el ingreso del día— y **Ver su ficha**.
- **Su carnet le llega al correo**, con un texto que dice que la registró el equipo. Si el correo
  no sale, el aviso lo dice: su ingreso se acredita igual.
- **No pide contraseña.** La elige la persona con «Olvidé mi contraseña», o entra con el código
  del correo o con su QR de acceso, como cualquiera.
- **No trae la propuesta de exposición.** Si va a exponer, regístrala con el perfil *Expositor*:
  su propuesta la envía ella después, desde «Mi exposición».
- **La autorización de datos es de la persona:** pregúntale antes de marcarla. La caracterización
  es opcional: pregúntale si quiere darla.
- **Si el correo o la identificación ya están registrados**, no se crea nada ni se toca a nadie: el
  aviso enlaza a la ficha de esa persona. Si solo había creado su acceso, que entre con su correo
  y lo complete ella: lo puede hacer con el registro cerrado.
- **Los perfiles son los del formulario.** Staff y Organizador los pone un administrador desde la
  ficha, como siempre.

La bitácora dice quién registró a cada persona y si el registro público estaba cerrado. El rol
**consulta** ve los registros pero no registra, y una persona con perfil **Staff** acredita en la
puerta pero tampoco: registrar es del operador de acceso y del administrador.

---

## 10. Antes de abrir al público

Una lista corta para no llevarse sorpresas:

1. **Borra los eventos de prueba.** Un asistente no los ve —solo ve el activo—, pero el equipo
   sí, y en la lista de exportaciones también aparecen.
2. **Comprueba las fechas de las jornadas** en *QR por día*. Son las que deciden qué día sella
   cada escaneo.
3. **Imprime los pliegos después de fijar las fechas y los días.** Cambiar la fecha no invalida
   el código, pero regenerarlo sí; y eliminar un día renumera los siguientes, con lo que el
   número impreso deja de coincidir. Si vas a hacer cualquiera de las dos cosas, hazla antes de
   imprimir.
4. **Deja el evento bueno activo** y los demás desactivados.
5. **Revisa el formulario** en *Configuración → Registro* y ábrelo con «Ver el formulario»
   desde una ventana privada: es lo que va a ver la gente. Si vas a invitar expositores, haz lo
   mismo con el de *Registro de expositores* antes de enviar su enlace.
6. **Comprueba el tamaño máximo de subida** en el diagnóstico, si esperas exposiciones. Es lo
   único de esta lista que se arregla fuera de la plataforma, y por tanto lo que más tarda.

---

## 11. Los listados largos: páginas, orden y cifras

Desde la 3.13, **Registros**, **Carnets** y **Expositores** van por páginas. Hasta la 3.12 el
listado de Registros mostraba como mucho 200 personas sin decirlo, y la tarjeta de arriba
decía «200 en el filtro» aunque el evento tuviera 396.

### 11.1 Las cifras de arriba

En Registros, la primera tarjeta muestra **grande cuántos registros tiene el evento** y debajo,
pequeño, **cuántos deja el filtro** («128 en el filtro», o «… en la lista, sin filtros»). Las
otras tres —con al menos un ingreso, asistencia completa, sin completar o entidades— se
cuentan sobre **todo el filtro**, no sobre la página que se ve.

### 11.2 Las páginas

Al pie de la tabla: cuáles se están viendo de cuántos («51–100 de 396 registros»), los números
de página y **Por página: 25 · 50 · 100**. En el celular queda **‹ Página 2 de 8 ›**.

La página, el filtro y el orden van en la dirección, así que se puede recargar, guardar en
favoritos, compartir con otra persona del equipo y volver atrás con el navegador. Filtrar o
cambiar el orden vuelve a la primera página.

En Expositores van 25 propuestas por página. Al decidir una propuesta se vuelve a la misma
página, con el mismo filtro y el mismo orden: no hace falta buscar otra vez por dónde iba la
revisión. Debajo de «Todas» salen los diez registrados sin propuesta más recientes; la lista
entera, con páginas y orden, está en el filtro **Sin propuesta**.

### 11.3 Ordenar desde el título de cada columna

Cada título que ordena lleva una **flecha doble pegada al texto**. Al pulsarlo, la tabla se
ordena por esa columna de menor a mayor y su flecha se enciende hacia arriba; al pulsarlo otra
vez, al revés. Ordena **todo el listado**, no solo la página que se ve.

| Pantalla | Se ordena por |
|---|---|
| Registros | Asistente, municipio, entidad, perfil e ingresos (cuántos días entró) |
| Carnets | Asistente, entidad, territorio y perfil |
| Expositores | Propuesta, expositor, categoría, jornada y estado |
| Expositores → Sin propuesta | Expositor, entidad y fecha de registro |

Los vacíos —sin entidad, sin municipio, sin día asignado— van siempre al final. El
**documento** no se ordena: va cifrado, y ordenarlo obligaría a descifrar a todo el evento en
cada página.

### 11.4 Exportar

**Exportar CSV** saca todo lo que deja el filtro, en el orden de la tabla, no solo la página que
se ve. Hasta la 3.12 se cortaba en 500 filas sin avisar.

---

## 12. La API: que otro sistema consulte los registros

Desde la 3.14, otro sistema —el sitio web de la Secretaría, un tablero, otra dependencia— puede
consultar en JSON quiénes se registraron: los expositores con su propuesta y su lugar en la
agenda, los participantes y los demás perfiles, con filtros en la dirección. Solo lee. La
referencia completa, con ejemplos, está en `docs/API.md`; aquí va lo que le toca al equipo.

### 12.1 Crear un token

En **Configuración → API**, escribe para qué sistema es y elige qué datos puede consultar:

- **Sin datos de contacto**: nombre, perfil, entidad, municipio, carnet, días que entró y la
  propuesta de quien expone. Es lo que necesita, por ejemplo, un sitio web que publica la lista
  de expositores.
- **Con correo y teléfono**: lo mismo, y además cómo contactar a cada persona. Solo para
  sistemas de la propia organización.

Aparte, dos casillas: **Incluir el enlace de la fotografía** (desde la 3.16) hace que cada
persona traiga la dirección de su foto —ver 12.3—, e **Incluir la caracterización** (desde la
3.20), su género, rango de edad, grupo étnico y discapacidad —ver 12.4—.

Al pulsar **Crear token** aparece el token, con consultas de ejemplo que ya lo llevan puesto.
Cópialo con **Copiar el token** y entrégalo por un canal seguro a quien administra ese sistema.
Si después se pierde, **Ver el token** lo muestra otra vez (12.2).

Uno por sistema: así la lista dice quién consulta, cuándo fue la última vez y cuántas consultas
lleva, y se le corta a uno sin dejar sin servicio a los demás.

Desde la 3.22 cada token es **del evento** en el que se crea, y solo consulta ese: la lista de
**Configuración → API** muestra los del evento elegido. Los que ya existían quedaron con el
evento principal. Para que un sistema consulte dos eventos, crea un token en cada uno.

### 12.2 Volver a copiarlo, revocarlo o eliminarlo

Debajo de cada token de la lista están sus botones:

- **Ver el token** (desde la 3.20). Lo muestra arriba de la pantalla, con **Copiar el token**.
  La lista no lo lleva a la vista: aparece solo al pulsarlo, y queda en la bitácora quién lo
  volvió a ver. **Los tokens creados antes de la 3.20 no tienen este botón**: de ellos solo se
  guardó la huella y no hay cómo recuperarlos. Siguen funcionando; la pantalla lo dice debajo de
  cada uno. Si se perdió uno de esos, crea otro, pásalo al sistema y, cuando ya lo use, elimina
  el viejo.
- **Revocar.** Deja de servir en el acto; los demás siguen. El token queda en la lista, marcado,
  con su historial. Hazlo cuando un sistema ya no lo necesite, cuando cambie quien lo administra
  o si el token pudo haberse filtrado —se pegó en un chat, quedó en un correo reenviado, se
  publicó en una página—.
- **Eliminar** (desde la 3.20, también en los revocados). Lo mismo que revocar, y además lo quita
  de la lista: sirve para limpiar los de prueba y los que ya no se usan. La bitácora conserva
  quién lo creó, quién lo eliminó y si estaba vigente.

Revocar y eliminar preguntan antes. Ninguno de los dos se deshace: para volver a dar acceso, se
crea un token nuevo.

### 12.3 La fotografía

Desde la 3.16, a un token se le puede dar el **enlace de la fotografía** de cada persona: al
crearlo, con la casilla, o después, con **Incluir la fotografía** junto a cada token vigente. No
cambia el token: desde la consulta siguiente, cada persona trae su enlace. Los tokens que ya
existían siguen sin la foto hasta que alguien se la incluya. En la lista, los que la tienen
llevan la etiqueta **Con fotografía**.

Ese enlace **se abre sin el token**, así que el sitio web lo puede poner tal cual en su página.
Deja de servir cuando:

- pulsas **Quitar la fotografía** en ese token —si se la vuelves a incluir, recibe enlaces
  nuevos; los de antes ya no sirven—,
- revocas o eliminas el token, o
- la persona cambia de foto.

Dáselo solo al sistema que de verdad va a mostrar fotos. La foto es un dato personal: quien la
publique debería mostrar solo la de quien aceptó aparecer en el directorio del evento
(`en_directorio`), o la de los expositores que lo autorizaron.

### 12.4 La caracterización

Desde la 3.20, a un token se le puede dar la **caracterización** de cada persona: género, rango
de edad, grupo étnico y discapacidad. Al crearlo, con la casilla, o después, con **Incluir la
caracterización** junto a cada token vigente; no cambia el token. **Quitar la caracterización** lo
deshace. Ningún token la tiene si no se le da, tampoco los que ya existían ni los de correo y
teléfono. En la lista, los que la tienen llevan la etiqueta **Con caracterización**.

El departamento y el municipio no dependen de este permiso: salen con cualquier token.

Son **datos sensibles** (Ley 1581 de 2012, art. 5): dalos solo a un sistema de la propia
organización que los necesite —un tablero de indicadores, un informe de población atendida— y
no a un sitio público. La bitácora del panel anota cada día en que un token los recibe, además
de quién le dio o le quitó el permiso.

### 12.5 Qué decirle a quien lo va a usar

- La dirección es `https://tic.narino.gov.co/cumbreAI/api/v1`. Abierta con el token, dice todo lo
  que se puede consultar y con qué filtros.
- **El token no va en una página pública ni en el JavaScript de un navegador**: se usa desde un
  servidor. Mejor en la cabecera `Authorization: Bearer …` que en la dirección.
- Son datos personales. Quien muestre nombres en otro sitio debería respetar `en_directorio`, si
  la persona aceptó aparecer en el directorio del evento.
- Cada token admite 600 consultas cada diez minutos. Ver una foto no cuenta.
- Si tiene la fotografía: el enlace de cada foto cambia cuando la persona cambia de foto, así que
  hay que tomarlo de la API cada vez que se actualicen los datos, no guardarlo para siempre.
- Si tiene la caracterización: cada campo trae `clave` y `nombre` —«Prefiero no responder», en
  género, con la clave vacía—, o `null` si la persona no lo declaró. Son datos sensibles: no se
  publican persona por persona.

### 12.6 Lo que nunca sale por ahí

El número de identificación, lo que el comité le escribe al expositor y sus requerimientos. Ni
con el alcance de contacto. La fotografía y la caracterización, solo a un token con cada permiso.

Quién creó, revocó o eliminó cada token, quién lo volvió a ver y quién le incluyó o le quitó la
fotografía o la caracterización, queda en la bitácora del panel.

## 13. Los certificados

Desde la 3.21, cada persona que asistió descarga su certificado en PDF desde la plataforma, en
**Mi certificado**. No hay que enviar nada a nadie: cada quien entra y lo baja.

- **Asistencia.** Lo recibe quien registró su ingreso en al menos una jornada —con el código de
  la entrada, con el escáner del equipo o a mano desde su ficha (13.4)—. Dice a qué jornadas
  asistió y, si se configuraron, cuántas horas suman.
- **Asistencia completa.** Quien estuvo en **todas** las jornadas recibe el suyo con un **sello
  dorado** de «Asistencia completa», el texto «asistió a las dos jornadas…» y la suma de las horas
  de todo el evento. Es el valor agregado de quedarse los dos días, y la persona lo ve también en
  su pantalla. Con un evento de una sola jornada no hay sello: todos los que fueron son iguales.
- **Participación como conferencista.** Cada expositor con su propuesta **aprobada** recibe uno
  por exposición, con el título, aunque no haya escaneado el código de la entrada. Si además
  registró su ingreso, tiene también el de asistencia.

### 13.1 Antes de abrirlos

En **Configuración → Certificados**:

1. **Quién certifica.** Por omisión, «La Gobernación de Nariño, a través de la Secretaría TIC,
   Innovación y Gobierno Abierto,»; la plataforma le agrega «certifica que» y, debajo, el nombre.
2. **El evento, como se nombra en el texto**, con su artículo: «la Cumbre Tecnológica IA Nariño
   2026». Vacío, el texto dice «el evento Cumbre…», que vale para cualquier nombre. Ese mismo
   nombre, sin el artículo, es el que muestra la página de verificación.
3. **La ciudad**: «…que se realizó en San Juan de Pasto…» y «Se expide en San Juan de Pasto el…».
4. **Las horas de cada jornada.** Vacías, el certificado no habla de horas.
5. **Quién firma**: nombre, cargo y, si quieres, la imagen de su firma o su visto. Mejor en PNG
   con fondo transparente; si es una firma escaneada en JPG con fondo blanco, el blanco se vuelve
   transparente solo, y en los dos casos se quitan las motas del escaneo y el margen vacío. La
   firma va **encima del nombre de quien firma**, como en un papel firmado a mano: el trazo
   queda sobre la línea y una pequeña parte pasa sobre el nombre, hasta la mitad de sus letras,
   sin llegar al cargo. Si hace falta, la plataforma la achica un poco para que así sea. Sin
   nombre, el certificado sale sin firma.
6. **El diseño**: el color del título y del nombre, el **fondo de la hoja** —carta horizontal,
   lo ideal 3300 × 2550 px; con menos de 2400 px de ancho se ve bien en pantalla pero impreso
   pierde nitidez— y el **fondo del nombre**, la imagen ancha del recuadro donde va el nombre, en
   PNG con fondo transparente. Sin imágenes, la hoja sale en blanco con un marco del color
   principal y el nombre del evento arriba.

Guarda y pulsa **Ver un ejemplo**: descarga un certificado con datos inventados y la marca de
agua «EJEMPLO · SIN VALIDEZ», en sus tres versiones —asistencia completa, una jornada y
expositor—. Para ver el de una persona de verdad, ábrela en **Registros** y pulsa **PDF** en su
tarjeta de certificados: funciona aunque estén cerrados.

### 13.2 Abrirlos y cerrarlos

Arriba de la misma pestaña, elige **Disponibles** y guarda el estado. Desde ese momento:

- «Mi certificado» aparece en el menú de cada persona —en el celular, segundo en la barra de
  abajo, en el lugar de «Mi ingreso», que pasa a la hoja de «más»—, y su carnet avisa que el
  certificado está listo;
- la primera vez que alguien abre «Mi certificado», su certificado se **emite**: recibe su
  código, y la fecha de expedición es la de ese día.

Arriba se ve cuántas personas tienen derecho, cuántas con asistencia completa, cuántos
expositores y cuántos ya lo descargaron.

**Cerrados** otra vez, salen del menú y no se descargan, pero los que ya se emitieron se siguen
verificando: un certificado que alguien ya entregó no deja de ser auténtico porque se cerró la
descarga.

### 13.3 La verificación

Cada certificado lleva un **código** de doce letras y números —«K7M2-9QXR-HT4P»: sin la O ni el
0, la I ni el 1, para que no se confundan— y un **QR** que lleva a `…/v/` con ese código. Quien
recibe el certificado —una universidad, una empresa— lo comprueba ahí sin cuenta: la página
dice si es auténtico, a nombre de quién, de qué y cuándo se expidió. No muestra el documento ni
el correo. También se puede escribir el código a mano en `…/v`.

Lo que dice el certificado se calcula al descargarlo, con los registros de ese momento. Si
después se quita un ingreso que se había registrado a mano, o se retira la aprobación de una
exposición, ese certificado deja de verificarse: la página dice que ya no es válido.

### 13.4 Quien estuvo y no escaneó el código

Sin ingreso no hay certificado de asistencia, y pasado el día el escáner ya no deja sellarlo.
Para eso, en la ficha de la persona (**Registros**), la tarjeta **Ingresos** tiene un botón
**Registrar** en cada jornada que ya llegó y en la que no tiene ingreso: lo registra a mano,
después de confirmar, y cuenta para su certificado. Solo el administrador lo ve.

Un ingreso registrado a mano se puede **Quitar** desde el mismo lugar; los del código de la
entrada y los del escáner no, porque son el registro de lo que pasó en la puerta. Las dos cosas
quedan en la bitácora con quién las hizo.

### 13.5 Si alguien no puede entrar

Desde su ficha, el operador de acceso y el administrador descargan su certificado con el botón
**PDF** de la tarjeta **Certificados** y se lo envían por otro medio. Queda en la bitácora que lo
descargó el equipo.

---

## 14. Varios eventos, cada uno por su cuenta

Desde la 3.22 la plataforma lleva varios eventos a la vez, y cada uno funciona por su cuenta: su
identidad —colores, logo, tipografía—, su formulario de registro y el de expositores, sus
jornadas y códigos QR, sus personas registradas, sus certificados, sus tokens de la API y su
equipo. Lo que se configura en uno no toca a los demás.

### 14.1 Elegir el evento en el que se trabaja

Al entrar al panel, quien trabaja en un solo evento entra directo a él. Quien trabaja en varios
—el administrador global los ve todos— elige primero: la pantalla «¿En qué evento vas a
trabajar?» muestra cada uno con su estado, el rol que tiene en él, sus registros y su dirección.

Desde ese momento todo el panel es de ese evento: indicadores, Registros, Escanear carnet, QR por
día, Expositores, Carnets, Organizadores y Configuración. Su nombre va arriba del menú y, debajo,
**Cambiar de evento**, para pasar a otro sin salir. En **Eventos**, el botón **Administrar** de
cada fila hace lo mismo. La elección es de cada sesión: dos personas pueden trabajar al mismo
tiempo en eventos distintos, y al volver a entrar se pregunta otra vez.

### 14.2 La dirección de cada evento

| Evento | Dirección |
|---|---|
| El **principal** (2.3) | La de siempre, por ejemplo `https://tic.narino.gov.co/cumbreAI/` |
| Cualquier otro | La misma con `/e/<nombre corto>/`, por ejemplo `…/cumbreAI/e/foro-de-gobierno-abierto-2027/` |

El nombre corto se arma solo con el nombre del evento al crearlo, y la dirección completa aparece
en **Eventos**, debajo de cada uno. El administrador global la puede cambiar en el lápiz del
evento —minúsculas, números y guiones—. Si se cambia con el evento ya anunciado, los enlaces
que se compartieron con la anterior dejan de servir.

Lo que va impreso o en un correo **no** lleva la dirección del evento, y por eso sigue sirviendo
aunque esta cambie o el evento deje de ser el principal: el QR de cada jornada, el carnet, el QR
personal de acceso, el enlace para poner una contraseña nueva, el enlace privado del formulario
de expositores y el código de verificación de un certificado. Cada uno ya sabe de qué evento es.

### 14.3 Quién ve un evento

- El **principal** se ve siempre, como hasta ahora.
- Los demás se ven en su dirección en cuanto dejan de estar en **Borrador**. Un evento recién
  creado queda en borrador: se le arma la identidad, el formulario y el equipo sin que nadie lo
  vea, y se anuncia cambiando su estado a **Abierto**. Mientras tanto su dirección le responde
  «Evento no encontrado» al público; su equipo sí lo ve, para revisarlo.
- La sesión de un asistente vale solo en su evento. Si alguien registrado en la Cumbre abre la
  dirección del Foro, la ve como visitante y, para asistir al Foro, se registra aparte. Si abre
  su carnet o sus datos desde la dirección de siempre, la plataforma lo lleva a los de su
  evento.

### 14.4 El administrador global y el de cada evento

| | Administrador global | Administrador del evento |
|---|---|---|
| En qué eventos trabaja | En todos | En los que lo agregan |
| Configurar el evento: identidad, registros, certificados, API | ✓ | ✓, en el suyo |
| Aprobar propuestas, exportar, imprimir carnets, QR por día | ✓ | ✓, en el suyo |
| El equipo | Todas las cuentas | Las que trabajan solo en su evento |
| Crear y eliminar eventos, elegir el principal, cambiar la dirección | ✓ | — |
| *Acceso y correo*: las formas de entrar y el correo de salida | ✓ | — |
| Actualizar la base de datos | ✓ | — |

Con el operador de acceso y la consulta pasa lo mismo: una cuenta **global** tiene ese rol en
todos los eventos; una **del evento**, en los que la agregan, y puede tener un rol distinto en
cada uno —administradora del Foro y operadora en la Cumbre, por ejemplo—.

Las cuentas que ya existían antes de la 3.22 quedaron **globales**, con su mismo rol: nadie
perdió lo que veía al actualizar. Para dejar una de ellas solo en su evento, el administrador
global pulsa **Solo en este evento**, debajo de ella en **Organizadores** (14.5). Las que se crean
desde Organizadores quedan, salvo que el administrador global elija otra cosa, solo en el evento
en el que se crean.

### 14.5 El equipo de cada evento

**Organizadores** muestra el equipo del evento elegido: las cuentas globales, marcadas como
*Global*, y las de ese evento, con su rol en él. Debajo de cada cuenta van los botones de lo que
se le puede hacer.

- **Agregar persona** con un correo nuevo crea la cuenta para este evento, con una contraseña
  inicial que deberá cambiar al entrar. Con el correo de una cuenta que ya existe —alguien que
  trabaja en otro evento— no se crea otra: se le agrega este evento con el rol elegido, y entra
  con la contraseña de siempre.
- El administrador global elige además **Dónde trabaja**: solo en este evento, o en todos
  (cuenta global). Una cuenta global con rol de administrador es administradora global.
- **Cambiar el rol** en este evento no cambia el que tenga en los demás.
- **Solo en este evento** y **Hacer global** (administrador global). El primero deja una cuenta
  global solo en el evento elegido, con el mismo rol; para que trabaje además en otro, se agrega
  su correo en el equipo de ese evento. El segundo vuelve global una cuenta de evento, con el rol
  que tiene en este. En los dos casos tiene que volver a entrar, y nadie cambia así su propia
  cuenta.
- **Quitar del evento** le quita el acceso a este evento y nada más: la cuenta sigue, con los
  otros eventos en los que trabaje. Si no le queda ninguno, al entrar se le dice que pida que la
  agreguen a un equipo.
- Enviar el enlace de la contraseña, restablecer la verificación en dos pasos o suspender: el
  administrador de un evento lo hace con las cuentas que trabajan solo en el suyo. Las globales,
  y las que trabajan también en otro evento, solo las toca el administrador global: así el
  administrador de un evento no puede dejar sin acceso a alguien de otro.

### 14.6 Crear un evento nuevo

1. En **Eventos** (administrador global), **Crear evento**: nombre, fecha de inicio y número de
   jornadas. Queda en **Borrador** y con su propia dirección. En una instalación sin eventos, el
   primero que se crea queda como principal.
2. Pulsa **Administrar** en su fila: el panel pasa a ese evento.
3. En **Configuración**, arma su **Identidad** —colores, logo—, su **Registro**, el **Registro de
   expositores** y, cuando llegue el momento, sus **Certificados**. Empiezan como los de
   fábrica: no se copian de otro evento.
4. En **QR por día** revisa sus jornadas y en **Organizadores** agrega a su equipo.
5. Cuando esté listo, en el lápiz de su fila cambia el estado a **Abierto** y comparte su
   dirección.

### 14.7 El panel, la bitácora y la API

- El panel muestra los indicadores y la bitácora del evento elegido. El administrador global ve
  además lo que es de toda la plataforma: una actualización de la base, un cambio en el correo
  de salida.
- Cada token de la API es del evento en el que se crea y solo consulta ese (12.1).
