# Administrar el evento

Qué se puede cambiar una vez creado, quién puede tocar qué, y qué no tiene vuelta atrás:
eventos y jornadas, las propuestas de los expositores, el perfil Staff, la impresión de
carnets, la acreditación en la puerta, la verificación en dos pasos del equipo y el formulario
de registro.

Todo lo de aquí está en **Administración → Eventos**, **QR por día**, **Expositores**,
**Registros** y **Configuración**, y exige el rol **administrador** salvo donde se diga otra
cosa. Imprimir carnets y acreditar lo comparten el equipo y el Staff.

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
| Devolver | «Tu propuesta tiene observaciones: …» | Lo que tiene que corregir y el enlace a su registro, para mandarla otra vez |
| Rechazar | «Sobre tu propuesta: …» | Que no fue seleccionada, con el motivo si se escribió |

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
**registrarse sin marcar «voy a exponer»** y volver cuando los tenga. Queda registrado, con su
carnet, y la propuesta la manda otro día.

Tampoco se pueden **quitar** una vez subidos, solo reemplazar. Un botón que deja la propuesta
sin lo que la hace evaluable no tendría sentido.

Aun así, la lista marca **qué falta**: cada propuesta lleva dos etiquetas debajo del título, en
verde lo que llegó y en gris lo que no. Si ves alguna en gris es de antes de que fueran
obligatorios. Devuélvela con observaciones pidiendo el documento: el expositor entra a su
registro y lo sube.

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

Lo que ya **no** puede cambiar desde el formulario es el tema, la categoría ni el día: están
publicados. El formulario se lo dice con un aviso, para que nadie edite el detalle, guarde, y
no entienda por qué no pasó nada.

### 4.8 Si un expositor dice que no puede subir su presentación

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
quitarle la verificación desde **Organizadores → Restablecer 2FA**. Se le cierran las sesiones
y, al entrar con su contraseña, escanea un QR nuevo. La tuya propia se restablece en
Configuración → Mi cuenta, no desde ahí. Queda en la bitácora.

Por consola, quien tenga acceso al servidor: `php herramientas/cuenta.php sin-2fa --correo=…`.

---

## 9. Configuración

Desde la versión 3.7, todo lo que se ajusta una vez y no se toca a diario está en una sola
entrada del menú, **Administración → Configuración**, con cuatro pestañas:

| Pestaña | Qué se configura | Quién |
|---|---|---|
| **Registro** | El formulario de registro del evento activo: campos, listas y banner | Administrador |
| **Identidad** | Colores, tipografía y logo del evento, con la revisión de contraste | Administrador |
| **Acceso y correo** | Las formas de entrar de los asistentes y el envío de correo | Administrador |
| **Mi cuenta** | La verificación en dos pasos y la contraseña de quien está dentro | Todo el equipo |

Las direcciones de antes —`/admin/identidad`, `/admin/autenticacion`, `/admin/cuenta`— siguen
funcionando: un enlace guardado o un correo viejo no se rompen. Quien no es administrador entra
directo a *Mi cuenta*, que es lo único de aquí que le corresponde.

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
   desde una ventana privada: es lo que va a ver la gente.
6. **Comprueba el tamaño máximo de subida** en el diagnóstico, si esperas exposiciones. Es lo
   único de esta lista que se arregla fuera de la plataforma, y por tanto lo que más tarda.
