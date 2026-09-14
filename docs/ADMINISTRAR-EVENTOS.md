# Administrar eventos y jornadas

Qué se puede cambiar de un evento una vez creado, qué se lleva por delante cada operación y
qué no tiene vuelta atrás.

Todo lo de aquí está en **Administración → Eventos** y **Administración → QR por día**, y
exige el rol **administrador**.

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
     ├─ propuesta      lo que enviaron los expositores
     ├─ dispositivo    teléfonos recordados
     └─ codigo_acceso  códigos pendientes
```

Además se borran del disco las **fotografías** de esas personas y el **logo** del evento. Sin
eso, cada evento de prueba dejaría sus archivos ocupando espacio para siempre sin que nadie
supiera de quién eran.

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

## 4. Antes de abrir al público

Una lista corta para no llevarse sorpresas:

1. **Borra los eventos de prueba.** Un asistente no los ve —solo ve el activo—, pero el equipo
   sí, y en la lista de exportaciones también aparecen.
2. **Comprueba las fechas de las jornadas** en *QR por día*. Son las que deciden qué día sella
   cada escaneo.
3. **Imprime los pliegos después de fijar las fechas.** Cambiar la fecha no invalida el código,
   pero regenerarlo sí: si vas a regenerar, hazlo antes de imprimir.
4. **Deja el evento bueno activo** y los demás desactivados.
