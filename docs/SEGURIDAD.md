# Revisión de seguridad

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño
Revisión de la **fase funcional** (PHP, base de datos, autenticación).

---

## 1. Qué cubre esta revisión

La plataforma ya no es una maqueta: guarda datos personales de asistentes reales, controla
quién entra al recinto y permite exportar listados. Esta revisión repasa el código que se
escribió para eso.

| Área | Estado |
|---|---|
| Autenticación y sesiones | Implementado y probado |
| Autorización por rol | Implementado y probado |
| Inyección SQL | Cerrada por diseño (solo sentencias preparadas) |
| Inyección en la salida (XSS) | Escape sistemático, verificado con pruebas |
| Falsificación de peticiones (CSRF) | Testigo en todos los envíos, verificado |
| Fuerza bruta | Límite por acción y por clave |
| Cifrado de datos sensibles | Documento cifrado; huella HMAC para búsquedas |
| Subida de archivos | Validación por contenido, reescritura y limpieza de SVG |
| Auditoría | Bitácora de solo inserción |
| Cabeceras y exposición de archivos | En `.htaccess` y también desde PHP |

Lo verifica `pruebas/extremo-a-extremo.php`: 478 comprobaciones sobre un servidor real, 19 de
ellas en el bloque específico de seguridad y otras tantas repartidas por los guardias de cada
pantalla, más las suites de correo, segundo factor, saneado de SVG, fotografía, adjuntos del
expositor y detección de proxy.

Esta revisión se rehízo módulo por módulo con diez agentes independientes, cada uno con un
área asignada, y cada hallazgo pasó por otro agente encargado de refutarlo. Lo que sobrevivió
está corregido y cubierto por una prueba; lo que sigue abierto está en el apartado 4.

---

## 2. Decisiones de diseño que reducen riesgo

### 2.1 El QR no lleva datos personales

El código del carnet contiene solo una URL con un identificador opaco de 128 bits:

```
https://tic.narino.gov.co/cumbreAI/c/9f3a2b7d10c4e5a1b2c3d4e5f6a7b8c9
```

Ni nombre, ni documento, ni correo. Quien fotografíe un carnet ajeno —cosa que pasa todo el
tiempo en un evento— no obtiene nada por sí mismo: tiene que preguntarle al servidor, y el
servidor decide qué entrega según quién esté identificado al otro lado.

La alternativa habitual —meter una vCard completa dentro del QR— funciona sin conexión,
pero convierte cada carnet colgado del cuello en una publicación permanente de la cédula.

**Qué ve cada quien al escanear un carnet** (`Escaneo::carnetAjeno`):

| Quién escanea | Qué recibe |
|---|---|
| Nadie identificado | Solo el nombre del evento y la pregunta de quién es. **No se revela de quién es la credencial** |
| Otro asistente | Los cuatro campos de contacto: nombre, entidad, correo y teléfono si su dueño lo autorizó |
| Operador o administrador | La ficha de acreditación, con documento. **Queda registrado en la bitácora** |

### 2.2 Los códigos QR funcionan desde fuera de la aplicación

Es un requisito y también una decisión de seguridad. Alguien apunta la cámara al pliego de
la puerta: el teléfono abre la URL sin ningún contexto. La plataforma no responde «no
autorizado» —que dejaría a la persona atascada delante de la puerta—, sino que la lleva al
acceso que corresponde recordando a dónde iba, y al terminar completa la acción.

El destino de retorno pasa por `Url::destinoSeguro()`, que solo admite rutas internas.
Sin esa comprobación, un enlace como `/entrar?destino=https://sitio-falso/` convertiría la
plataforma en un trampolín creíble para robar credenciales.

El lector integrado (`assets/js/escaner.js`) solo sigue códigos que apunten a esta misma
instalación y con el formato esperado. Sin eso, bastaría pegar un QR falso encima del de la
puerta para llevar a los asistentes a una imitación del acceso.

### 2.3 El código del día rota y tiene ventana horaria

El QR de acceso pertenece a la jornada, no al evento: cambia cada día, se regenera a mano si
el pliego impreso se filtra —una foto en redes basta— y el anterior queda inválido en ese
momento. Cada jornada tiene `abre_a` y `cierra_a`: un escaneo fuera de esa franja se rechaza
con un mensaje claro, y el intento queda anotado.

### 2.4 Un ingreso por persona y jornada, garantizado por la base

La regla la impone la llave única `uq_asistencia (persona_id, evento_dia_id)`, no el código
PHP. En la puerta hay reintentos, dobles toques y dos operadores escaneando a la vez; una
comprobación previa en PHP pierde esas carreras. El código intenta insertar e interpreta el
choque (`Asistencia::sellar`).

### 2.5 Los datos sensibles viven aparte

La caracterización —género, pertenencia étnica, condición de discapacidad— es dato sensible
según el artículo 5 de la Ley 1581 de 2012. Está en `persona_caracterizacion`, separada de
`persona`, con dos consecuencias prácticas: las consultas del día a día nunca la tocan, y su
exportación se audita por separado y exige rol administrador.

Si alguien no diligencia la caracterización, **no se crea la fila**. Una tabla de datos
sensibles llena de registros vacíos solo agranda el riesgo sin aportar nada.

### 2.6 El documento va cifrado, con huella para buscar

`persona.documento_cifrado` usa XChaCha20-Poly1305 (libsodium) o AES-256-GCM como respaldo,
ambos con autenticación. Junto a él, `documento_huella` guarda un HMAC-SHA256 con la llave
del sistema.

La huella permite detectar que alguien ya está registrado sin descifrar toda la tabla. Es un
HMAC y no un SHA-256 a secas porque el espacio de las cédulas colombianas es pequeño: con un
hash sin clave, quien obtenga la tabla puede probarlas todas en minutos.

> **La llave vive en `config/config.php`.** Si ese archivo se pierde, los documentos
> guardados quedan ilegibles para siempre. Está dicho también en la guía de despliegue,
> porque es el error de operación más caro que se puede cometer aquí.

### 2.7 Dos accesos distintos, para dos riesgos distintos

| | Asistente | Equipo organizador |
|---|---|---|
| Cómo entra | Correo + código de 6 dígitos de un solo uso | Contraseña + segundo factor |
| Contraseña | No tiene | Argon2id (bcrypt si no está disponible) |
| Duración | 30 días | 12 h absolutas, 2 h de inactividad |
| Por qué | Pedirle a mil personas que inventen una contraseña para tres días produce contraseñas malas y una fila en el punto de información | Maneja datos personales de todos los asistentes |

El código de acceso se guarda como SHA-256, nunca en claro, vence en diez minutos y sirve una
sola vez. Pedir uno nuevo invalida el anterior.

**Argon2id con `m=65536, t=4, p=1`.** Los parámetros están en un solo sitio,
`Cripto::ARGON`, y el hilo único es deliberado. PHP puede traer Argon2 de dos bibliotecas
distintas —`libargon2` suelta, o la que va dentro de `libsodium`— y **la de libsodium solo
admite un hilo**: pedirle más no degrada el hash, lanza un `ValueError`. Las dos
compilaciones son indistinguibles desde fuera: misma versión de PHP, misma constante
`PASSWORD_ARGON2ID`, mismo `phpinfo()`.

El código pedía dos hilos. Funcionó en desarrollo y reventó en el servidor del despliegue,
en el paso 4 del asistente, justo al convertir la contraseña del administrador: la
instalación quedó con las dieciséis tablas creadas, `evt_usuario` vacía y sin ninguna forma
de entrar. Un hilo es además el valor por omisión de PHP y el que recomienda OWASP; el
paralelismo no endurece nada, solo reparte el mismo trabajo entre núcleos. Lo que protege es
el coste en memoria, que sigue en 64 MiB.

`pruebas/claves.php` lo fija por tres vías: los parámetros que se piden, la forma del hash
resultante (`p=1`), y un barrido de `app/` que falla si algún archivo vuelve a pedir más de
un hilo. Si `password_hash` falla igualmente en alguna compilación rara, se cae a bcrypt con
coste 12 y se anota: un hash aceptable es mejor que una instalación sin ninguna cuenta.

### 2.8 Sesiones propias, en la base de datos

No se usa la sesión de PHP. En un alojamiento compartido los archivos de sesión suelen quedar
en un directorio común legible por otras cuentas del mismo servidor. Guardándolas en la base
se pueden cerrar a distancia y caducar de verdad.

El identificador que viaja en la cookie **no es el que se guarda**: en la tabla queda su
SHA-256. Quien consiga leer la tabla no obtiene cookies utilizables. Verificado en las
pruebas.

Las cookies salen `HttpOnly`, `SameSite=Lax` y `Secure` cuando hay HTTPS. Lax y no Strict
porque el asistente llega desde el lector de QR de su teléfono, que cuenta como navegación
externa: con Strict la cookie no viajaría y pediría acceso otra vez en la puerta.

El identificador se rota al superar el segundo factor, para que una cookie fijada antes de
completar la identificación no siga sirviendo.

### 2.8.1 La marca del dispositivo

Hay una segunda cookie, solo para asistentes: `evtic_disp`, seis meses de vida, cuyo único
poder es volver a abrir la sesión de su dueño (`App\Nucleo\Dispositivo`).

Existe porque el escenario real la pedía: la persona se preregistra en el navegador y a los
días abre el enlace desde el correo o desde WhatsApp, que usan su propio almacén de cookies.
Sin esto acababa en «identifícate» con el carnet ya emitido, que es donde la gente abandona.

Los controles:

- **Selector y validador.** El selector busca la fila —es un índice, no un secreto—; del
  validador se guarda solo el SHA-256 y se compara con `hash_equals`. Igual que las sesiones:
  quien lea la tabla no obtiene cookies utilizables. Verificado en las pruebas.
- **Un selector real con un validador equivocado borra la fila entera.** No es un error de
  tecleo: son 256 bits al azar. Se anota en la bitácora y el dispositivo queda invalidado.
- **No abre el panel de nadie del equipo.** Solo asistentes; la sesión del organizador es
  corta a propósito y muere al cerrar el navegador.
- **Salir es salir.** `Sesion::cerrar('asistente')` borra también la marca; si no, la página
  siguiente volvería a abrir la sesión sola y el botón no serviría para nada.
- **Se puede retirar a distancia**, desde el carnet de su dueño y desde *Registros*, junto
  con la anulación del QR de acceso.

**No se rota el validador en cada uso, a propósito.** La rotación detecta el robo, pero en un
teléfono con varias pestañas —o con la precarga del navegador— dos peticiones simultáneas
usan el mismo valor y la segunda queda inválida: la persona se encuentra fuera sin haber
hecho nada. A cambio se guarda el agente y la fecha de último uso, y el dueño puede cerrar
todos sus dispositivos.

### 2.8.2 El QR de acceso, distinto del QR de contacto

Son dos códigos y la diferencia es deliberada:

| | Qué es | Quién lo puede ver |
|---|---|---|
| `/c/{token}` | Tarjeta de presentación. Escanearlo no identifica a nadie: intercambia contacto, o acredita si quien mira es del equipo | Cualquiera. Va impreso en la escarapela |
| `/entrar/qr/{token}` | Credencial. Abre la sesión de su dueño en el teléfono que lo escanee | Solo su dueño y el equipo, y así se rotula en pantalla |

Que existiera solo el primero es la razón de que escanear el propio carnet terminara en
«Escaneaste el carnet de un asistente» en vez de entrar. Fundirlos en uno habría sido peor:
convertiría cada foto de una escarapela en una llave.

El token de acceso son 128 bits al azar, con límite por dirección contra la enumeración, y se
puede anular desde *Registros* —lo que además olvida todos los dispositivos de esa persona.

### 2.8.3 El registro a medias

Desde la 3.2 se puede crear el acceso con solo correo y contraseña y llenar el formulario
después. Eso obligó a que el documento sea nulable, y de ahí salen tres cosas que conviene
dejar dichas:

- **La llave única del documento sigue valiendo.** En MySQL los nulos no chocan entre sí, así
  que puede haber muchos registros a medias y ninguno impide que otro se registre. En cuanto
  el documento se escribe, la unicidad vuelve a aplicarse como siempre.
- **La autorización de tratamiento de datos se pide al crear el acceso**, no al completar el
  formulario. Es cuando se crea el registro, y la Ley 1581 no admite diferirla.
- **El guardia de asistente lo comprueba.** Todo lo que hay detrás —el carnet, el sellado de
  ingreso, el intercambio de contacto, la ficha de acreditación— necesita al menos el nombre y
  el documento, así que a quien no los tiene se le lleva a completarlos en vez de enseñarle
  pantallas vacías o emitirle un carnet sin nombre.

El carnet no se emite hasta que el registro está completo. No es cosmético: la ficha de
acreditación existe para comparar contra el documento físico en la puerta, y una credencial
sin identificación no sirve para eso.

### 2.9 Autorización comprobada en el servidor, en cada petición

Los guardias están declarados junto a cada ruta en `app/rutas.php`, en un solo archivo, para
que revisar qué expone la aplicación sea leer una columna. La navegación oculta lo que no
corresponde al rol, pero eso es cosmético: lo que decide es el guardia.

Jerarquía: `administrador` ⊃ `operador` ⊃ `consulta`. El operador puede sellar ingresos pero
no exportar con caracterización ni tocar la configuración.

### 2.10 Sin dependencias externas en tiempo de ejecución

Ni framework, ni Composer, ni CDN. Las tipografías están autoalojadas y el generador de QR es
propio. Consecuencias: no hay forma de que un paquete comprometido inyecte código, la IP de
los asistentes no viaja a terceros, la política de seguridad de contenido puede prohibir
orígenes externos por completo, y la plataforma se sube por FTP sin ejecutar nada previo.

---

## 3. Controles implementados

### Inyección SQL

Todo el acceso pasa por `App\Nucleo\Bd`, que solo expone métodos con sentencias preparadas.
No existe un método que reciba SQL ya interpolado con datos. `PDO::ATTR_EMULATE_PREPARES`
está en `false`: las sentencias se preparan en el servidor, no del lado del cliente, que es
donde aparecen las inyecciones por juegos de caracteres.

Lo único que se interpola son nombres de columna para ordenar, y salen de listas fijas del
código.

### Salida (XSS)

La función `e()` es la más usada del proyecto y tiene una sola letra a propósito: si escapar
costara más de escribir, alguien se lo saltaría «solo esta vez». Las tres excepciones son
deliberadas y no contienen datos de personas: el SVG del QR (generado por el servidor), el
bloque de estilo del tema (hexadecimales validados dos veces) y el contenido ya renderizado
de una vista dentro de la plantilla.

Verificado en las pruebas con un nombre `<script>alert(1)</script>` y una entidad
`"><img src=x onerror=alert(1)>`: ninguna etiqueta llega a formarse.

### Falsificación de peticiones (CSRF)

Testigo en todos los envíos, con el patrón de doble envío: cookie más campo del formulario.
Se eligió sobre guardarlo en la sesión porque también hace falta en pantallas donde todavía
no hay sesión —el preregistro y el propio acceso—, que son las que más conviene proteger.

El testigo se emite en cualquier página, no solo donde hay formulario: quien llega directo
desde un lector de QR no debería toparse con un error sin haber hecho nada raro.

Un envío sin testigo válido responde 419 —«la página estuvo demasiado tiempo abierta»— y no
403, porque la causa más común no es un ataque sino una pestaña que llevaba horas abierta.

### La dirección del visitante detrás de un proxy

Todo lo que se cuenta por IP depende de ver la IP correcta. En Plesk hay nginx por delante de
Apache, y en este despliegue además Cloudflare por delante de todo: sin declarar el proxy, la
aplicación ve siempre la misma dirección y **el límite de intentos deja de ser por visitante
para pasar a ser uno solo para todo el mundo**. Veinte accesos fallidos de cualquiera dejarían
fuera al equipo entero.

Lo contrario es igual de malo: hacer caso a `X-Forwarded-For` sin comprobar de dónde viene
permite a cualquiera falsear su origen y saltarse los bloqueos.

El equilibrio es una lista de proxies de confianza. El instalador la rellena solo cuando puede
demostrarlo: la petición llega de una dirección interna —loopback o rango privado, que nadie
puede presentar desde internet— y además trae una cabecera de reenvío. En cualquier otro caso
la deja vacía. `/instalar/diagnostico` avisa si la aplicación está viendo una dirección de
proxy, y `pruebas/proxy-y-limites.php` cubre las dos mitades.

### Fuerza bruta

`App\Nucleo\Limite` cubre siete acciones con ventanas y castigos distintos: acceso del
equipo (por cuenta y por IP), código de correo, envío de códigos, resolución de tokens de QR,
preregistro por IP e intercambio de contactos.

Hay dos clases de regla y confundirlas deja huecos:

- Las que cuentan **fallos** —acceso, códigos, tokens— solo anotan cuando algo salió mal, así
  que a quien acierta no le afectan.
- Las que cuentan **acciones consumadas** —preregistro e intercambio de contactos— anotan
  salga bien o mal, porque ahí el abuso consiste precisamente en tener éxito muchas veces.
  Estas dos anotaban solo los fallos, que nunca ocurrían, de modo que el contador se quedaba
  en cero y la regla no llegaba a saltar nunca.

Los topes de la segunda clase son holgados a propósito: una sede de evento sale a internet por
una sola dirección, y un tope bajo bloquearía al décimo asistente que se registre en la puerta.

La clave del contador se guarda como HMAC. Si se guardara en claro, la tabla de intentos
sería una lista de correos de personas que fallaron el acceso, útil para quien la lea.

### El segundo factor cuando el código correcto no entra

Fue el problema más visible en producción: después de algunas actualizaciones, el código de
seis dígitos del teléfono se rechazaba siempre, con un mensaje que mandaba a revisar el
reloj. Detrás había cuatro causas distintas con una sola respuesta. Desde la 3.6 cada una
tiene la suya.

**El secreto ya no se podía leer.** Si `config/config.php` se perdía —se borra la carpeta
para subir la versión nueva— y se volvía a pasar por el asistente, se generaba otra llave de
cifrado sin avisar, y con ella el secreto guardado de cada cuenta quedaba ilegible. Ahora:

- El asistente y la consola no cambian la llave en silencio. Si la base tiene datos
  cifrados y la configuración no trae llave, piden la anterior y la **prueban contra un dato
  real** antes de aceptarla. La llave pegada viaja al mismo archivo temporal que la
  contraseña de la base (`config/instalacion.php`), nunca a la cookie del proceso, y ese
  archivo se borra al terminar. Seguir sin ella exige marcar una casilla (o `--llave-nueva`).
- Una cuenta cuyo secreto no se puede descifrar se reconoce (`Usuario::estadoSegundoFactor()`
  devuelve `ilegible`): la pantalla lo dice tal cual, se anota en la bitácora y en el
  registro de errores, y se ofrece entrar con un código al correo. No se le deja entrar solo
  con la contraseña: el secreto ilegible sigue contando como segundo factor configurado.

**El reloj del servidor corrido.** El teléfono pone la hora solo; el servidor no siempre. Con
más de un minuto de diferencia, la tolerancia de ±30 s no alcanzaba y ningún código entraba
nunca. Ahora se aplica la resincronización del RFC 6238 (§6):

- Primero se busca cerca de la hora del servidor y cerca del desfase aprendido para esa
  cuenta (`usuario.totp_deriva`). Es lo normal, y entra.
- Si no cuadra, se busca hasta doce horas a cada lado —cubre el reloj puesto en hora local
  como si fuera UTC, cinco horas exactas en Colombia—. Un código que cuadra lejos **no da
  acceso**: con tantos intervalos abiertos, uno al azar acierta en uno de cada trescientos
  intentos. Se pide el siguiente, que tiene que caer justo después con el mismo desfase; dos
  seguidos al azar son una posibilidad en un billón, y el límite de cinco intentos cada
  quince minutos sigue contando los dos.
- El desfase confirmado queda aprendido y la vez siguiente se entra a la primera. Se anota
  en la bitácora, y en el registro se avisa de activar la hora automática del servidor.

**El código repetido.** El último intervalo aceptado no se admite dos veces (RFC 6238 §5.2),
y la escritura que lo consume es atómica: dos envíos del mismo código a la vez, solo uno
entra. Pero el rechazo decía «revisa el reloj», y después de una actualización —que cierra
las sesiones— entrar dos veces en el mismo medio minuto es lo normal. Ahora dice que ya se
usó y que se espere el siguiente. Si el último aceptado está bastante más adelante que el
código, no se trata como repetido sino como un teléfono que se puso en hora: se pide el
siguiente, como en la resincronización. Un código viejo que alguien vio hace rato sigue sin
servir.

**La base sin la columna.** Con una instalación anterior a la 1.1.0, el código correcto
respondía 500 porque se escribía en `totp_ultimo`, que no existía. El acceso ahora funciona
con o sin esa columna, y además la base se pone al día sola en la primera visita.

**El código por correo.** Para quien perdió el teléfono o tiene la configuración ilegible.
Solo se ofrece **después de la contraseña**, nunca en su lugar, y solo si hay correo
saliente. Seis dígitos, válidos diez minutos y una sola vez; se guardan como HMAC con la llave
de la instalación y el identificador de la sesión, así que no sirven en otra sesión. Tope de
cuatro envíos por hora y de cinco fallos (después, media hora de espera). El mensaje dice qué
hacer si no fue uno quien lo pidió: alguien tiene la contraseña. Es más débil que la
aplicación —el buzón tiene su propia contraseña—, así que se puede apagar con
`'respaldo_2fa_correo' => false`.

**Restablecer el código QR.** En **Configuración → Mi cuenta**, cada persona genera uno nuevo con su
contraseña actual —una sesión abierta en un equipo ajeno no basta—. El secreto nuevo espera
cifrado en la sesión, y **el anterior sigue valiendo hasta que el nuevo se confirma** con un
código: abandonar a la mitad deja todo como estaba. Al confirmar se cierran las demás
sesiones de la cuenta, por si el motivo es un teléfono perdido. Una cuenta administradora
puede además quitárselo a otra desde **Organizadores**; queda en la bitácora.

### Enumeración de cuentas

El mensaje de error del acceso es único: no distingue entre correo inexistente, contraseña
mala y cuenta suspendida. Y cuando el correo no existe se verifica igual contra un hash de
descarte, para gastar el mismo tiempo: sin eso, la diferencia de duración delata qué cuentas
existen aunque el texto sea idéntico.

Lo mismo en el acceso del asistente: pedir un código para un correo no registrado responde
igual que para uno registrado.

### Subida de archivos

Se suben cuatro cosas: el logo del evento y el banner del formulario de registro, que sube un
administrador; la fotografía del carnet, que sube el propio asistente desde el formulario
público; y los dos documentos del expositor —hoja de vida y exposición—, que suben también desde
ahí. Las dos últimas son las que más cuidado piden, porque el formulario está abierto.

**La fotografía** (`App\Nucleo\Imagen::guardarFoto`):

1. Tipo determinado por el **contenido real** (`finfo`). Solo JPG, PNG y WEBP.
2. **No se admite SVG.** Para un logo tiene sentido; para la foto de una cara no hay ningún
   motivo, y un SVG es un documento XML capaz de contener guiones.
3. Tamaño máximo 6 MB, y el error de PHP por exceso de tamaño se traduce a una frase que la
   persona entiende, con el límite real del servidor.
4. La imagen se **vuelve a generar entera** con GD: se descarta cualquier carga útil
   escondida en los metadatos EXIF o detrás de la cabecera. La salida es siempre JPEG,
   recortada cuadrada a 480 px.
5. **El encuadre lo elige la persona, pero no lo decide.** El editor del navegador manda
   el rectángulo visible en las medidas con las que él vio la imagen; el servidor lo
   reescala a las suyas y lo encaja dentro de la foto —nunca mayor que ella, nunca fuera
   de sus bordes—, y si los números no son números, o la proporción no coincide con la de
   la imagen real, se cae al recorte del centro en vez de fallar. Lo que viaja es el
   archivo original más cinco números, no una imagen ya recortada por el cliente: así el
   servidor sigue siendo quien decide qué se guarda. `pruebas/foto.php` prueba nueve
   encuadres imposibles —fuera de rango, negativos, con letras, en notación científica— y
   de todos tiene que salir un JPEG válido de 480 px.
6. El nombre lo pone el servidor y lleva 8 bytes al azar: sin eso, saber el id de una persona
   bastaría para adivinar la ruta de su foto.
7. **Nunca se sirve desde el disco.** Pasa por `Medios::foto`, que además comprueba quién
   mira: su dueño, o el equipo organizador. El id es correlativo, así que sin esa
   comprobación bastaría con contar desde uno para descargar la cara de todos los asistentes.

**Los documentos del expositor** (`App\Nucleo\Documento::guardar`). Desde la 3.3 quien marca
que va a exponer **tiene que** adjuntar su hoja de vida (PDF) y su exposición (PDF o PPTX): son
obligatorios, y el formulario no se envía sin ellos.

Son un caso distinto del de la foto, y más difícil: **no se pueden volver a generar**. Una
imagen se descompone y se vuelve a dibujar, y en el camino se pierde cualquier cosa escondida
dentro; reescribir un PDF significaría cambiar el documento que la persona quiso enviar. Como
no se puede limpiar el contenido, lo que se hace es quitarle al archivo toda posibilidad de
ejecutarse:

1. Tipo determinado por el **contenido real** (`finfo`), y para el PDF además se exige que el
   archivo **empiece** por `%PDF-`. libmagic se conforma con encontrar esa cadena en el primer
   kilobyte, así que sin esa segunda comprobación un archivo que es otra cosa y la lleva
   metida más adelante pasaría por PDF. La norma dice que la cabecera es la primera línea del
   archivo y toda herramienta la escribe ahí, así que exigirlo no deja fuera nada legítimo.
2. **Un PPTX es un ZIP**, y libmagic casi siempre dice solo `application/zip`. Así que se abre
   el paquete y se le pregunta qué lleva: sin `ppt/presentation.xml` **y** sin que
   `[Content_Types].xml` lo declare, no es una presentación. Hacen falta las dos condiciones:
   con solo la primera, bastaría con meter un archivo vacío con ese nombre dentro de un ZIP
   cualquiera. Nunca se extrae nada al disco, y del paquete se lee un solo archivo —el
   índice— y únicamente después de comprobar en la tabla del ZIP que mide menos de 512 KB: un
   ZIP de un mega puede llevar dentro un XML de varios gigas, y descomprimirlo a ciegas tumba
   el servidor con la memoria agotada.
3. Cada campo admite **solo lo suyo**: una presentación en el campo de la hoja de vida se
   rechaza aunque sea un PPTX perfectamente válido.
4. **Se comprueba antes de guardar nada** (`Documento::revisar()`), durante la validación del
   formulario. Un campo obligatorio tiene que fallar con los demás campos: avisar después
   —«lo guardamos todo, menos esto»— es lo correcto para la foto del carnet, que es un adorno,
   y lo incorrecto para algo sin lo cual la propuesta no se puede evaluar. Y no hay forma de
   **quitar** un adjunto, solo de reemplazarlo: un botón que deja la propuesta sin lo que la
   hace evaluable no tiene sentido.
5. Topes de 8 MB para la hoja de vida y 25 MB para la exposición. El instalador **comprueba
   `upload_max_filesize` y `post_max_size`** y avisa si el servidor no llega: en Plesk vienen
   en 2 MB de fábrica. Y pasado `post_max_size`, PHP descarta el envío completo —`$_POST` y
   `$_FILES` vacíos, el testigo incluido—, así que `Csrf::exigir()` distingue ese caso y
   responde 413 con el motivo real en vez del «la sesión expiró» que no lleva a ninguna parte.
6. El nombre lo pone el servidor, con 8 bytes al azar, y el original del cliente **no se
   guarda**: sirve de poco y sería texto de fuera que habría que desconfiar cada vez que se
   imprime. El nombre de la descarga se arma a partir de datos que ya están en la base.
7. **Nunca se sirven desde el disco.** Pasan por `Medios::documento`, que comprueba quién
   mira —su dueño, o el equipo que revisa las propuestas— y responde el mismo 404 para «no
   existe» y para «no es tuyo»: distinguirlos convertiría la dirección en una forma de
   averiguar qué propuestas hay y quién adjuntó qué.
8. **Salen siempre como descarga, nunca incrustados.** Un PDF abierto dentro de la página es
   un documento que puede traer sus propios guiones; bajado al disco lo abre el lector de
   quien lo pidió, fuera del origen del sitio. El nombre de la descarga se reduce a letras,
   números, punto y guion, porque acaba dentro de `Content-Disposition`: una comilla o un
   salto de línea ahí dejan de ser texto y pasan a ser estructura de la respuesta.
9. La **ranura de la dirección** (`/medios/documento/{n}/hoja-de-vida`) se traduce a nombre de
   columna con una lista fija de dos, no con el texto de la URL. Esa columna se interpola en
   el `SELECT`, y es lo único que hace que eso sea seguro: `pruebas/documentos.php` comprueba
   que ninguna otra palabra —`estado`, `detalle`, `titulo`— resuelva a nada.

Al eliminar un evento estos archivos se borran del disco igual que las fotos. Una hoja de
vida trae teléfono, dirección y trayectoria laboral: no puede quedarse ahí cuando ya no hay
nadie que sepa de quién era.

**El logo del evento.** Los controles, en `Admin::guardarLogo()`:

1. Tipo determinado por el **contenido real** (`finfo`), no por la extensión ni por lo que
   declare el navegador.
2. Tamaño máximo 512 KB.
3. Los mapas de bits se **vuelven a generar** con GD, lo que descarta cualquier carga útil
   escondida en los metadatos, y se reducen a 600 px.
4. Los SVG se **limpian**: se quitan `<script>`, `<foreignObject>`, `<iframe>`, atributos
   `on*`, referencias externas, `javascript:` y declaraciones de entidades (la vía de los
   ataques XXE).
5. El nombre lo pone el servidor; el del cliente se descarta.
6. **Nunca se sirven desde el disco.** Pasan por `Medios::logo`, que fija el tipo desde el
   servidor y añade `Content-Security-Policy: sandbox`. Aunque un SVG malicioso pasara la
   limpieza, no se ejecutaría en el origen del sitio.

**El banner del formulario de registro** (`Imagen::guardarBanner`, desde la 3.7). Lo sube un
administrador, pero lo ve cualquiera que abra el registro, así que se trata como la foto:

1. Tipo por el **contenido real** (`finfo`): JPG, PNG o WEBP. **Sin SVG**: un banner no lo
   necesita, y así no hay documento XML que limpiar en una imagen pública.
2. Tamaño máximo 6 MB, con el mismo mensaje legible que la foto cuando PHP lo corta antes.
3. Se **vuelve a dibujar** con GD —se pierden los metadatos y lo que viniera detrás de la
   cabecera— y se reduce a 1600 px de ancho.
4. El nombre lo pone el servidor, con 6 bytes al azar; el del cliente se descarta.
5. **Nunca se sirve desde el disco.** Pasa por `Medios::banner`, que toma el nombre de la base
   y no de la dirección. El del evento activo es público; el de cualquier otro —un borrador
   puede no estar anunciado— solo lo ve el equipo, y a los demás se les responde el mismo 404
   que si no hubiera banner.
6. Al cambiarlo, quitarlo o eliminar el evento, el archivo anterior se borra del disco.

### Exportaciones

Los CSV llevan neutralizada la inyección de fórmulas: un valor que empiece por `=`, `+`, `-`
o `@` se antepone con un apóstrofo. Sin eso, alguien podría escribir `=HYPERLINK(...)` en el
campo «entidad» del formulario público y esa celda se ejecutaría al abrir el reporte en el
equipo de un funcionario.

La exportación con caracterización exige rol administrador —comprobado en el servidor, no
solo escondiendo el botón— y queda en la bitácora.

### El perfil Staff: el único que da permisos

Desde la 3.4, una persona registrada puede tener el perfil **Staff**. No es una etiqueta más
del carnet: quien lo tenga entra con su propio acceso de asistente y puede ver e imprimir los
carnets del evento —con la cédula y la foto de cada quien— y sellar ingresos.

Eso convierte un campo del formulario público en un campo de permisos, y ahí está el riesgo:
`/registro` está abierto al público y envía `rol`. Las barreras, en orden:

1. **El formulario no lo ofrece.** La lista sale de `Persona::ROLES_PUBLICOS`, que es la misma
   contra la que valida el servidor: escritas por separado, una y otra podían separarse.
2. **El servidor lo rechaza.** `Publico::validarRegistro()` valida contra `ROLES_PUBLICOS` y no
   contra `ROLES`. Esconder la opción no sirve de nada por sí solo: un envío hecho a mano no
   pasa por ninguna pantalla. El envío se rechaza entero, con el motivo a la vista, en vez de
   guardarse con otro perfil.
3. **Y el modelo tampoco lo escribe.** `Persona::rolAdmitido()` descarta cualquier perfil que
   no sea público. Es la red por si alguien añade mañana otro camino que llame a `registrar()`.
4. **Solo lo pone un administrador**, desde la ficha de la persona
   (`POST /admin/registros/perfil`, guardia `admin:administrador`), y el cambio queda en la
   bitácora con el perfil anterior y el nuevo.

La misma función cierra el lado contrario, que es menos obvio: un perfil que solo pone un
administrador **tampoco se pierde** desde el formulario. Sin eso, a alguien del staff le
bastaba con abrir «mis datos» y guardar para quedarse sin su perfil, porque el selector no
tiene su opción y el navegador manda la primera de la lista. Por eso, además, a quien tiene uno
de esos perfiles se le enseña su etiqueta en vez de un selector.

No se le puede poner a un registro a medias: entrar a acreditar exige sesión de asistente, y el
guardia manda a esa persona a terminar el formulario. Quedaría con el perfil puesto y sin poder
usarlo.

### El guardia «acreditar»

Las pantallas de la puerta —ver los carnets, imprimirlos, sellar ingresos— las pasan dos
sesiones distintas: el equipo con rol operador y el Staff. El guardia lo resuelve así:

- **Con sesión del equipo** se aplica el guardia del equipo completo, no una comprobación de
  rol a secas. Ahí viven el segundo factor pendiente, la contraseña que puso otra persona y la
  cuenta suspendida, y cada uno sabe a qué pantalla mandar a quien llega.
- **Con sesión de asistente** se exige el perfil Staff y el registro completo.
- **Sin ninguna** se manda al acceso del asistente, que es por donde entra la mayoría.

Al armarlo apareció un hueco que ya existía: `Guardia::equipoOperativo()` —la que usan las
rutas que no llevan guardia, como escanear un carnet— no miraba `debe_cambiar`. Una cuenta con
la contraseña puesta por otra persona podía acreditar escaneando, aunque el panel se lo
negara. Ahora lo mira.

`Asistencia` guarda **quién** selló y **de qué tabla** sale ese número (`operador_tipo`): sin
eso, el usuario 7 del equipo y el staff 7 serían el mismo número en la misma columna, y los
reportes de quién acreditó a quién no significarían nada.

### Buscar por número de identificación

La pantalla de la puerta ofrecía desde el principio buscar por documento, y nunca funcionó: el
número se guarda cifrado, así que un `LIKE` sobre él no encuentra nada. Desde la 3.4 se compara
la **huella HMAC**, que es exacta: o se escribe el documento completo, o no aparece.

Buscar por los últimos dígitos exigiría descifrar la tabla entera en cada búsqueda, que es
justamente lo que el cifrado evita. La pantalla lo dice con esas palabras en vez de dejar que
alguien lo intente y crea que la persona no está registrada.

### Eliminar un evento borra datos personales de verdad

Desde la 3.2 un administrador puede eliminar un evento entero. Se lleva en cascada las
personas registradas, sus asistencias, sus contactos, sus propuestas y sus carnets, y borra
del disco sus fotografías y los documentos que adjuntaron los expositores. No hay deshacer.

Los controles, en `Admin::eliminarEvento()` y `Evento::eliminar()`:

1. Rol **administrador**, comprobado por el guardia de la ruta.
2. El diálogo dice **cuántos registros de cada tipo se van a perder**, calculados antes de
   preguntar. Un «¿seguro?» sin un número al lado no es una confirmación.
3. Hay que **escribir ELIMINAR**. Un botón suelto en una fila de tabla se pulsa por error.
4. Queda en la **bitácora** con el nombre del evento y las cuentas de lo eliminado.

Para dejar de mostrar un evento sin borrar nada está **desactivar**, que es reversible con un
clic y es lo que corresponde en casi todos los casos.

Lo mismo con las jornadas: una que tenga ingresos se puede eliminar, pero el botón dice
cuántos se van con ella. Existe porque los días de prueba se llenan de escaneos justamente
probando, y la alternativa era entrar a la base de datos a mano, que es peor.

### Las contraseñas de los asistentes no se pueden mostrar

Se pide con frecuencia: «esta persona no recuerda su contraseña, muéstramela». No se puede, y
no es una limitación de la pantalla. En la base hay un hash Argon2id, que es una función de
un solo sentido; si la plataforma pudiera leer la contraseña, cualquiera que copiara la tabla
tendría en claro las de todos los asistentes.

Lo que sí ofrece la ficha de *Registros*, para un administrador:

- **Generar una contraseña nueva**, de diez caracteres sin letras ni números que se confundan
  al dictarlos por teléfono (sin `I`, `O`, `0` ni `1`). Se muestra **una sola vez** y no
  viaja en la redirección ni en el aviso: se pinta en la respuesta de esa misma petición.
  Al hacerlo se cierran las sesiones de esa persona y se olvidan sus dispositivos, porque
  quien pide restablecer una contraseña suele haber perdido el control de la cuenta.
- **Enseñarle su QR de acceso**, que entra sin escribir nada, y anularlo si hizo falta.

Ambas acciones quedan en la bitácora, y abrir una ficha también.

### El formulario de registro se configura, pero no se puede desarmar

Desde la 3.7 un administrador decide en **Configuración → Registro** qué campos pide el
formulario público y qué opciones trae cada lista. Es una pantalla que cambia lo que la
plataforma acepta de cualquiera, así que lo que se configura pasa por las mismas reglas que lo
que llega del público (`App\Modelos\Formulario`):

- **Solo administrador**, con testigo CSRF, y cada guardado o restablecimiento queda en la
  bitácora con quién y cuándo.
- **Tres campos no se pueden quitar**: el correo, el nombre y la autorización de tratamiento de
  datos. Sin la autorización, la Ley 1581 no deja guardar nada.
- **Los datos sensibles nunca son obligatorios.** Género, pertenencia étnica y discapacidad
  solo admiten «visible» u «oculto»; un envío manipulado que los marque obligatorios se guarda
  como opcional. «Prefiero no responder» no se puede apagar.
- **Los perfiles que dan permisos no se ofrecen nunca.** Desde la 3.8 cada evento agrega,
  renombra, apaga y elimina perfiles de asistencia, pero Staff y Organizador no entran en esa
  lista por mucho que se envíen a mano, ni metidos en la base: al leerla, una clave de
  administrador se descarta. Ningún perfil puede llamarse como ellos, para que un «Staff» de
  mentira no confunda en la puerta. Lo que da permisos es la clave `staff` y nada más: un perfil
  que agregue el evento, se llame como se llame, es solo un rótulo en el carnet.
- **Un perfil en uso no se elimina.** Se apaga. Eliminarlo dejaría a esas personas con un perfil
  sin nombre; por lo mismo, volver al formulario de fábrica conserva los perfiles propios que
  alguien ya tenga. Las claves nuevas salen del nombre, sin tildes ni espacios, y nunca toman una
  que ya tenga alguien.
- **Lo enviado se acota antes de leerlo.** `Peticion::campoEstructurado()` corta a tres niveles,
  500 elementos por nivel y 20 000 caracteres por texto. Una opción más larga que la columna
  donde se guardaría se rechaza diciendo cuál, y una sigla de documento solo admite de 2 a 8
  letras y números.
- **Lo guardado se revisa también al leer.** Una fila editada a mano en la base con una lista
  sin forma —o sin ninguna opción activa, o un departamento sin municipios— se ignora y se usa
  la de fábrica: el registro público nunca se queda sin opciones.
- **El servidor es quien valida.** El registro público acepta solo las opciones de la lista
  configurada —más la que ya tenía guardada quien edita sus datos—, y comprueba lo obligatorio
  aunque el navegador no lo haga. La ventana de «tu registro no fue guardado» es una ayuda, no
  un control.
- **Ocultar un campo no borra datos.** Lo que alguien ya había dado se conserva al editar su
  registro; simplemente no se le vuelve a preguntar.

### El enlace privado del formulario de expositores

Desde la 3.9 hay un segundo formulario de registro, para quienes van a exponer, al que se llega
solo por un enlace que la organización envía aparte (`/registro/expositores/{token}`):

- **El token es lo que lo hace privado:** 128 bits al azar (`Cripto::token(16)`), en una columna
  única. No está en ningún menú ni en ninguna página pública, y todas las páginas llevan
  `noindex, nofollow`.
- **Si se filtra, se cambia.** «Generar un enlace nuevo», solo para administradores, con
  testigo CSRF y en la bitácora, invalida el anterior en el acto.
- **Un enlace que no es el vigente responde 404**, igual que una dirección que no existe —uno
  regenerado, uno de otro evento, uno inventado—, para no confirmar que alguna vez lo fue. Uno
  sin forma de token ni llega a la base.
- **Solo vale para el evento activo**: el de un evento anterior no abre el formulario del actual.
- **No abre más puertas que el formulario público.** El registro pasa por las mismas
  validaciones, el mismo límite por dirección y la misma regla de no reescribir el registro de
  un correo ajeno: un correo ya registrado tiene que entrar con su código. Lo único que cambia es
  el perfil por omisión, *Expositor*, que no da ningún permiso; a quien tiene Staff u Organizador
  no se le toca.
- La política `Referrer-Policy: strict-origin-when-cross-origin` evita que la dirección, con el
  token, salga hacia otro sitio en la cabecera `Referer`.

### El correo al expositor

Desde la 3.7, decidir sobre una propuesta le avisa por correo a quien la envió, con la
decisión, los cambios que le hizo el comité y las observaciones.

- Va **solo a la dirección registrada de quien propuso**, nunca a una que llegue en el envío.
- No lleva datos personales más allá de su nombre: ni documento, ni teléfono, ni nada de la
  caracterización.
- El título de la propuesta lo escribió alguien de fuera y va en el asunto: los saltos de
  línea se quitan antes de armar las cabeceras, y el asunto viaja codificado. En el cuerpo, todo
  texto que vino de un formulario sale escapado.
- Si el correo no sale, **la decisión se guarda igual** y la pantalla lo dice; la bitácora
  anota si se avisó o no.

### Auditoría

`App\Nucleo\Bitacora` solo inserta. Registra accesos y su resultado, sellado de asistencias,
consultas de credenciales, decisiones sobre propuestas —y si se avisó por correo—,
exportaciones, rotaciones de token, cambios de identidad, cambios del formulario de registro y
los rechazos de seguridad.

Lo que **no** registra: el contenido de los datos personales. Dice que alguien exportó la
caracterización, no qué decía. Un filtro descarta cualquier clave que parezca contraseña,
documento o token antes de guardar, por si alguien pasa el formulario completo por comodidad.
Verificado en las pruebas.

### Credenciales durante la instalación

Ninguna contraseña pasa por la cookie del asistente. La cookie lleva el estado del proceso
firmado con HMAC, pero firmado no es cifrado: quien la capture puede leer lo que contiene.

- **La de la base de datos** se escribe en `config/instalacion.php` en cuanto la conexión se
  comprueba, y el paso 6 borra ese archivo. Va en una carpeta bloqueada por el servidor y en
  un archivo `.php` que, aunque llegara a servirse como estático, no mostraría nada.
- **La del administrador** se convierte a hash Argon2id en el paso 4 y solo viaja así.

Va en un archivo suyo y no dentro de `config/config.php` por una razón que costó un incidente:
**que exista `config/config.php` tiene que significar «instalación terminada»**. Escribirlo a
medias, con `instalado => false`, deja el sitio entero redirigiendo al asistente, y desde fuera
eso es indistinguible de un sitio que nunca se instaló.

La prueba de extremo a extremo lo verifica leyendo la cookie después de cada paso, no solo al
final: mirar únicamente el estado final daba por bueno un secreto que sí estuvo ahí
durante tres pasos.

**La firma de esa cookie es ahora un secreto de verdad.** Era
`hash('sha256', RAIZ . PHP_VERSION . '|instalador')`, y ninguna de esas dos piezas es secreta:
la ruta de instalación en Plesk es previsible —`/var/www/vhosts/<dominio>/httpdocs/…`— y la
versión de PHP la publica el propio servidor. Con las dos, cualquiera podía firmar un estado
válido durante la ventana en que el asistente está abierto y colar su propio correo como
cuenta administradora del evento. Ahora la llave se genera con `random_bytes(32)` la primera
vez y se guarda en `config/.llave-asistente` (permisos `0600`, fuera del repositorio, en una
carpeta que el servidor no sirve). De paso desaparece un fallo silencioso: una actualización
menor de PHP a mitad de instalación invalidaba el estado y devolvía al paso 1 sin explicar
por qué.

### La instalación no puede dejar la plataforma sin puerta

El paso final escribe la marca de «instalado» **al final**, cuando ya existen la cuenta
administradora y el evento. El orden inverso —el que tenía— convertía cualquier fallo
posterior en un callejón sin salida: el asistente respondía «ya está instalada» y el acceso
del equipo «correo o contraseña incorrectos», sin ninguna forma de entrar ni de reintentar.

Como red de seguridad, `App\Nucleo\Instalacion` comprueba que haya tablas y al menos una
cuenta administradora activa. Si no la hay, el asistente se reabre en modo reparación. La
barrera no se pierde: el paso 2 sigue exigiendo las credenciales de la base de datos, que
quien llega de fuera no tiene, y en ese modo no se ofrece la opción que borra tablas. En
cuanto existe una cuenta, el asistente vuelve a cerrarse solo.

Que el acceso del equipo diga «todavía no hay ninguna cuenta» es deliberado y no contradice
la regla de no revelar qué cuentas existen: no se está diciendo nada de una cuenta concreta,
el estado ya es visible desde fuera, y sin decirlo no hay salida.

### Mientras se instala, los errores se muestran; después, no

La regla general es que un fallo nunca enseña su traza al visitante: revela rutas del
servidor, nombres de tablas y a veces credenciales. Hay **una excepción acotada**: mientras
`config/config.php` no exista con la marca de instalado, la página de error muestra la
excepción, el archivo y la línea.

El razonamiento, y por qué esto no es una fuga:

- En ese momento no existe **nada** que filtrar. No hay llave de cifrado, ni una sola cuenta,
  ni un dato de ninguna persona, ni tablas con contenido. La única credencial en juego es la
  de la base de datos, y esa vive en `config/instalacion.php`, no en las trazas.
- La ruta absoluta del servidor se recorta antes de imprimirla (`RAIZ` → `…`), así que no se
  publica ni la cuenta del sistema ni la estructura de directorios.
- La ventana es la instalación, que dura minutos y ocurre antes de que el sitio se anuncie.
- **Y sin esto no había salida.** Quien instala en Plesk normalmente no tiene SSH y no puede
  leer `almacen/registro/`. Una pantalla que dice «se registró el problema para revisarlo»
  a alguien que no puede revisar nada deja la instalación muerta y sin ninguna pista. Es
  exactamente lo que ocurrió en producción.

En cuanto la instalación termina, la excepción vuelve a ser invisible sin tocar ninguna
opción. `pruebas/instalacion.php` comprueba las dos mitades: que el detalle se ve antes de
instalar y que **no** se ve después.

Como complemento, `App\Nucleo\Registro` ya no pierde apuntes en silencio: si no puede escribir
en `almacen/registro/` —permisos mal puestos al desplegar, algo habitual—, cae al registro de
errores de PHP, que en Plesk queda en los registros del dominio. Antes, ese caso dejaba la
avería sin ningún rastro en ninguna parte.

### Cabeceras

Se envían desde PHP **y** desde `.htaccess`. En Plesk es común que `mod_headers` no esté
activo o que nginx sirva por delante sin aplicar las reglas de Apache; duplicarlas evita
depender de eso.

`Content-Security-Policy` sin orígenes externos, `X-Frame-Options: DENY`, `nosniff`,
`Referrer-Policy`, `Permissions-Policy` (solo cámara, y del propio origen), y HSTS cuando la
conexión es segura.

**Cloudflare y su beacon.** Con Web Analytics encendido, Cloudflare inyecta
`static.cloudflareinsights.com/beacon.min.js` en el HTML de salida. La política lo bloquea y
la consola del navegador lo denuncia. Se deja así a propósito: la aplicación no necesita ese
script, y ensanchar `script-src` para todo el mundo por una analítica opcional es peor
negocio que apagar la analítica. Quien la quiera puede permitirlo explícitamente con
`'permitir_cloudflare_analytics' => true` en `config/config.php`, que añade ese origen a
`script-src` y `https://cloudflareinsights.com` a `connect-src`, y nada más.

---

## 3.1 Lo que encontró la revisión por módulos

Diez agentes revisaron un módulo cada uno y otro tanto se dedicó a refutar cada hallazgo.
Estos son los que se sostuvieron y ya están corregidos. Se dejan escritos porque la mayoría
son errores fáciles de volver a cometer.

| Qué pasaba | Por qué importaba |
|---|---|
| El segundo factor dejaba al administrador en un bucle sin salida | `$_COOKIE` se vaciaba al rotar la sesión, así que `pendiente_2fa` no se apagaba nunca |
| Cualquiera podía entrar a la cuenta de un asistente sabiendo su correo | El preregistro público actualizaba por correo y abría sesión con ese registro |
| Un asistente identificado podía reescribir el registro de otro | El `readonly` del campo del correo lo decide el navegador |
| `/c/{token}` mostraba la cédula con el segundo factor a medias | Esa ruta no lleva guardia y comprobaba el rol por su cuenta |
| El segundo factor obligatorio se saltaba escribiendo `/admin` | El guardia miraba `pendiente_2fa` pero no si la cuenta tenía el factor puesto |
| Toda búsqueda por texto respondía 500 | Marcador con nombre repetido, sin emulación de sentencias preparadas |
| Ningún JavaScript de pantalla llegaba al navegador | La vista y la plantilla no comparten ámbito |
| El paso 3 del asistente se ofrecía a borrar una base con datos | No podía consultar el esquema y daba por hecho que estaba vacía |
| Los límites del preregistro y de contactos no contaban nada | Solo anotaban fallos, y esas acciones no fallan |
| Detrás del proxy, un solo bloqueo dejaba fuera a todo el mundo | `proxies_confiables` se escribía siempre vacío |
| El correo llegaba roto o no llegaba | Líneas por encima del límite del RFC 5321 y cabeceras sin codificar |
| El lector de QR no reconocía ningún código | Solo funcionaba colgando de la raíz del dominio |
| Dos pasaportes distintos se tomaban por el mismo | La normalización del documento quitaba las letras |
| Quien recibía una clave temporal no podía cambiarla nunca | La marca existía y no la miraba nadie; no había pantalla |
| Tras algunas actualizaciones, el código correcto del teléfono se rechazaba siempre | Sin `config/config.php`, el asistente generaba otra llave y el secreto ya no se podía leer; el mensaje culpaba al reloj |
| Con una base anterior a la 1.1.0, el código correcto respondía 500 | Se escribía en una columna que no existía, y el botón que la creaba estaba detrás de ese mismo acceso |
| Con el reloj del servidor corrido más de un minuto, ningún código entraba | Solo se toleraban ±30 segundos y no había forma de compensar |
| Entrar dos veces en el mismo medio minuto decía «revisa el reloj» | El código repetido se rechazaba con el mismo mensaje que el equivocado |
| «Anexar» dejaba la base dada por buena y sin las columnas nuevas | La revisión miraba la versión anotada, no las columnas que hay |
| Un envío del paso 3 sin modo vaciaba la base | El modo por omisión era la instalación limpia |
| El carnet y la acreditación mostraban un pasaporte sin sus letras | La función que da formato al número quitaba todo lo que no fuera dígito |
| Desde el celular no se podía escribir un pasaporte | El campo pedía el teclado numérico para cualquier tipo de documento |
| Tras un envío que no salía, el botón se quedaba en «Enviando…» | El bloqueo del doble envío no miraba si el envío se había cancelado |

---

## 4. Hallazgos abiertos

### 4.1 La CSP necesita `style-src 'unsafe-inline'` — *compromiso consciente*

Las vistas usan atributos `style=` para ajustes puntuales de maquetación, así que la política
no puede prohibir estilos en línea. Los scripts sí van todos en archivos: no hay un solo
`<script>` en línea, y por eso `script-src 'self'` va sin excepciones, que es lo que de
verdad detiene un XSS.

**Pendiente:** migrar esos atributos a clases y quitar la excepción.

### 4.2 El instalador puede conectar a cualquier servidor — *acotado*

El paso 2 abre una conexión MySQL al servidor que se le indique, lo que permitiría sondear
máquinas de la red interna. Está acotado porque la ruta solo existe **antes** de que haya
una instalación utilizable: apenas hay configuración y una cuenta administradora, responde
403. Quien pueda ejecutar el instalador ya tiene control total sobre la instalación de todos
modos.

La excepción es el modo reparación, que mantiene la ruta abierta mientras no haya ninguna
cuenta con la que entrar. Es una ventana que se cierra sola en cuanto se crea la cuenta, y la
alternativa —cerrarla igual— deja la plataforma inaccesible para siempre, que es peor.

**Recomendación:** completar la instalación en cuanto se suban los archivos, no dejarla a
medias.

### 4.3 El correo depende del servidor del dominio

Si `mail()` no funciona, un asistente que pierda su sesión no puede volver a entrar. La
plataforma lo registra en `almacen/registro/` y el instalador lo advierte, pero no hay
segundo canal. **Probar el correo antes del evento no es opcional.**

### 4.4 Sin política de retención — *pendiente de decisión jurídica*

Nada define cuánto tiempo se conservan los registros después del evento. Debe acordarse con
el área jurídica y ejecutarse de forma automática. Hoy, los datos se quedan.

### 4.5 El teléfono de contacto no se puede retirar de lo ya compartido

Quien apaga «compartir teléfono» deja de compartirlo en adelante, pero quien ya lo recibió lo
conserva. Es inherente al intercambio de contactos —igual que una tarjeta de papel—, pero
conviene decirlo con claridad en la pantalla de privacidad.

---

## 5. Datos personales (Ley 1581 de 2012)

| Exigencia | Cómo se atiende |
|---|---|
| Autorización previa e informada | Casilla obligatoria en el preregistro, con la finalidad declarada. Se guarda `autorizo_datos_en` |
| Finalidad determinada | Acreditación, control de asistencia y reportes de cobertura del evento |
| Datos sensibles con tratamiento reforzado | Tabla aparte, nunca obligatorios —ni siquiera por configuración—, exportación con rol administrador y auditada |
| Minimización | De fábrica, solo nombre y documento son obligatorios. Cada evento puede pedir menos —el documento puede quedar opcional u oculto— y ocultar lo que no necesite |
| Circulación restringida | El QR no expone datos; el intercambio comparte cuatro campos y el teléfono es opcional |
| Derecho de supresión | El intercambio de contactos guarda `revocado_en`. **Falta** el procedimiento para la persona completa |
| Seguridad | Cifrado del documento, control de acceso por rol, auditoría de lecturas sensibles |

---

## 6. Verificación

```bash
php pruebas/extremo-a-extremo.php      # 515 comprobaciones sobre un servidor real
php pruebas/instalacion.php            # el asistente, y qué se ve cuando falla
php pruebas/actualizacion.php          # subir desde la 1.0.0 por cada camino, sin perder nada
php pruebas/claves.php                 # parámetros de Argon2id y rehash
php pruebas/smtp.php                   # el cliente SMTP contra un servidor real
php pruebas/totp.php                   # segundo factor contra el RFC 6238
php pruebas/correo.php                 # formato MIME e inyección de cabeceras
php pruebas/svg-saneado.php            # logos SVG con código dentro
php pruebas/foto.php                   # la foto del carnet y encuadres manipulados
php pruebas/documentos.php             # PDF y PPTX disfrazados de otra cosa
php pruebas/proxy-y-limites.php        # la IP real detrás del proxy
php pruebas/qr-php-contra-js.php       # el generador de QR del servidor
python3 pruebas/qr-contra-referencia.py
node pruebas/pantallas.js              # escritorio
ANCHO=390 node pruebas/pantallas.js    # móvil
```

Lo que comprueban las de seguridad, concretamente:

- Un anónimo no ve registros ni el panel; un asistente tampoco entra al backoffice.
- Un envío con testigo falso se rechaza.
- `app/`, `config/`, `almacen/` y `pruebas/` no se sirven por la web.
- Las cabeceras de seguridad salen en todas las respuestas.
- Las cookies son `HttpOnly` y llevan `SameSite`.
- La cookie de sesión no coincide con el identificador guardado en la base.
- Un nombre con etiquetas y un atributo inyectado se escapan.
- Un destino de redirección externo se descarta.
- La bitácora registra la actividad y no guarda contraseñas.
- Un token de QR inventado no revela nada.
- Sin sesión, el QR del carnet no dice de quién es.
- El documento se guarda cifrado y no en claro.
- Nadie puede registrarse sobre el correo de otra persona, ni con sesión ni sin ella.
- Con el segundo factor a medias no se ve la ficha de acreditación.
- Un testigo plantado en la cookie no vale mientras haya sesión abierta.
- El mismo código del segundo factor no sirve dos veces, y lo dice así, no culpando al reloj.
- Con el reloj del servidor corrido, el código correcto se confirma con el siguiente y el
  desfase queda aprendido; un código viejo ya superado no entra.
- Con el secreto ilegible, la pantalla lo dice y se entra con un código del correo; ese
  código vence, sirve una sola vez y solo en la sesión que lo pidió.
- Restablecer el QR exige la contraseña actual, el anterior vale hasta confirmar el nuevo, y
  al confirmarlo se cierran las demás sesiones de la cuenta.
- Sin `config/config.php`, el asistente no cambia la llave en silencio: pide la anterior y
  rechaza una que no abra los datos.
- Una cuenta con contraseña puesta por otro no puede trabajar hasta cambiarla.
- La contraseña de la base sale de la cookie del asistente en el paso 2, no al final.
- La del administrador no viaja en claro entre pasos: viaja su hash.
- La contraseña del administrador queda en hash en la tabla, no en claro.
- El modo reparación no ofrece borrar tablas, y no borra datos aunque se envíe a mano.
- Antes de instalar, un fallo enseña su causa; después de instalar, no enseña nada.
- El diagnóstico cuenta las tablas sin filtrar la contraseña de la base de datos.
- La cuenta del paso 4 queda en la tabla con su hash, y con ella se entra al panel.
- El hash de contraseñas pide un solo hilo, que es lo que admiten las dos compilaciones
  de Argon2 que trae PHP.
- La contraseña del correo no vuelve al navegador, no entra en la transcripción SMTP que se
  muestra en pantalla, y no queda en la bitácora.

---

## 7. Antes de abrir al público

1. HTTPS con certificado válido, redirección permanente y HSTS activo.
2. `/instalar` responde 403.
3. `config/config.php` y `app/` inaccesibles desde el navegador.
4. Usuario de base de datos dedicado, sin privilegios sobre otras bases.
5. Segundo factor activado en todas las cuentas de administrador.
6. Correo saliente probado con una cuenta real.
7. Copias de seguridad programadas **y una restauración probada**.
8. `proxies_confiables` configurado si hay nginx por delante, para que el límite de intentos
   vea la IP real.
9. Política de retención definida.
10. Contraste del tema revisado con el logo definitivo.
