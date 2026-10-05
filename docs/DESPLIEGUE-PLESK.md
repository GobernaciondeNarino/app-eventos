# Despliegue en Plesk

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño

Esta guía cubre el caso concreto del despliegue actual —
**https://tic.narino.gov.co/cumbreAI/** — y también el de un dominio propio, porque la
plataforma funciona igual en los dos sin cambiar un solo archivo.

---

## 1. Cómo se resuelve la subcarpeta

No hay que configurar la ruta en ningún lado. `index.php` la deduce de `SCRIPT_NAME`:

| Dónde se sube | Ruta base detectada | Ejemplo de URL interna |
|---|---|---|
| `httpdocs/cumbreAI/` | `/cumbreAI` | `/cumbreAI/carnet` |
| `httpdocs/` | *(vacía)* | `/carnet` |
| `httpdocs/eventos/2026/` | `/eventos/2026` | `/eventos/2026/carnet` |

Todas las URLs del sistema —enlaces, formularios, cookies, y el contenido de los códigos
QR— se construyen con `Url::a()` y `Url::absoluta()`, que anteponen esa base. Mover la
plataforma de sitio es copiar la carpeta y actualizar `url_base` en `config/config.php`.

> **Importante para los QR.** Los códigos ya impresos llevan la URL absoluta dentro. Si
> cambias el dominio o la subcarpeta después de imprimirlos, hay que regenerarlos y volver
> a imprimir (Administrador → QR por día → Regenerar).

---

## 2. Requisitos del servidor

| | Mínimo | Recomendado |
|---|---|---|
| PHP | 8.1 | 8.2 o superior |
| Base de datos | MySQL 5.7 / MariaDB 10.3 | MariaDB 10.6+ |
| Extensiones obligatorias | `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo` | |
| Extensiones recomendadas | `gd` (reduce logos), `zip` (valida los PPTX), `sodium` (cifrado moderno) | |
| Tamaño de subida | `upload_max_filesize` y `post_max_size` en **32M** | |
| Apache | con `mod_rewrite` | además `mod_headers` |

En Plesk: **Dominio → Configuración de PHP**. Ahí se elige la versión y se revisan las
extensiones. La plataforma comprueba todo esto en el primer paso del instalador y no deja
continuar si falta algo obligatorio.

**Los dos valores de tamaño hay que subirlos casi siempre**, porque Plesk los deja en 2 MB de
fábrica y con eso no entra ni la foto de un carnet. Lo que más pesa es la exposición de un
expositor, que admite hasta 25 MB. Se cambian en esa misma pantalla —están en la lista de
ajustes de `php.ini`— y `post_max_size` tiene que ser igual o mayor que `upload_max_filesize`.

Si se quedan cortos el fallo es desconcertante: PHP descarta el envío completo y el formulario
responde «el envío pesaba demasiado» con el límite que encontró. El instalador avisa antes, en
la fila **Tamaño máximo de subida** del diagnóstico.

---

## 3. Instalación paso a paso

### 3.1 Crear la base de datos

**Plesk → Bases de datos → Añadir base de datos**

- Nombre: `eventos_tic` (o el que prefieras).
- Cotejamiento: **utf8mb4_unicode_ci**. Si se deja `latin1`, las tildes y la ñ se guardarán
  mal y el instalador lo rechazará.
- Crea un **usuario dedicado** para esta base, no reutilices uno existente ni uses `admin`.
  Si la aplicación se ve comprometida, el daño queda acotado a estas tablas.

Anota nombre de base, usuario y contraseña: el instalador los pedirá.

### 3.2 Subir los archivos

**Plesk → Archivos**, o por SFTP.

Sube el contenido del repositorio a `httpdocs/cumbreAI/`. Debe quedar así:

```
httpdocs/cumbreAI/
├── index.php          ← el punto de entrada
├── .htaccess
├── app/
├── assets/
├── config/            ← vacío; el instalador escribe aquí
├── almacen/
└── docs/ herramientas/ pruebas/   ← se pueden omitir
```

`docs/`, `herramientas/` y `pruebas/` no hacen falta en producción. Si los subes no pasa
nada: el `.htaccess` los bloquea.

> Si el panel deja apuntar el documento raíz directamente a la carpeta, mejor. No es
> necesario: la aplicación funciona colgando de una subcarpeta.

### 3.3 Permisos

El instalador necesita escribir en dos sitios:

```
config/     0750   (aquí se escribe config.php)
almacen/    0750   (logos, fotos, documentos de expositores, respaldos y
                    registro de errores)
```

Las subcarpetas de `almacen/` las crea el instalador si faltan. Ninguna se sirve por web: el
`.htaccess` de la raíz bloquea la carpeta entera y cada archivo sale por PHP, que es quien
comprueba antes si quien lo pide tiene derecho a verlo.

En Plesk se ajustan desde **Archivos → (menú del directorio) → Cambiar permisos**. El
propietario debe ser el usuario del sistema del dominio.

### 3.4 Activar HTTPS antes de instalar

**Plesk → Certificados SSL/TLS → Instalar (Let's Encrypt)** y activa la redirección
permanente a HTTPS.

Hazlo *antes* de instalar: así el instalador detecta la conexión segura y las cookies de
sesión salen marcadas como `Secure` desde el primer momento. Sin TLS, las contraseñas del
equipo y los tokens de los carnets viajan en claro por la red del recinto, que suele ser
wifi abierto.

Cuando el certificado esté puesto, descomenta en `.htaccess` el bloque de redirección a
HTTPS y la línea de `Strict-Transport-Security`.

> **Sobre HSTS.** La plataforma lo envía sin `includeSubDomains`. Vive en una subcarpeta de un
> dominio compartido con otras aplicaciones y no le corresponde obligar a todo `narino.gov.co`
> —ni a sus subdominios— a hablar solo por HTTPS durante un año. Si el área de sistemas quiere
> esa política para el dominio entero, se pone en el servidor, no aquí.

> **Si preparas el servidor con `git clone`,** el `.htaccess` bloquea `.git/`. Compruébalo:
> `https://tic.narino.gov.co/cumbreAI/.git/config` debe responder 404. Si responde con el
> contenido, nginx está sirviendo estáticos por delante de Apache y hay que añadir la regla
> también allí (ver el apartado 4).

### 3.5 Ejecutar el asistente

Abre **https://tic.narino.gov.co/cumbreAI/**. Sin configuración, cualquier dirección lleva
al asistente. Son seis pasos:

1. **Servidor** — comprueba versión de PHP, extensiones y permisos.
2. **Base de datos** — los datos del punto 3.1. El botón «Probar conexión» avisa antes de
   seguir.
3. **Tablas** — detecta lo que ya existe y propone: limpio, actualizar o anexar. Muestra el
   SQL exacto antes de ejecutarlo.
4. **Cuenta administradora** — la primera cuenta; podrá crear el resto del equipo.
5. **Primer evento** — nombre, fechas, jornadas y la identidad visual.
6. **Fin** — resumen y siguientes pasos.

Al terminar, el asistente **se cierra solo**: mientras exista `config/config.php` con la
instalación marcada como completa, la ruta `/instalar` responde 403. No hay que acordarse
de borrar ninguna carpeta. Para reinstalar a propósito, borra `config/config.php` o crea el
archivo `config/permitir-reinstalar`.

### 3.5.1 Si el asistente no llega a terminar

Tres salidas, y conviene conocerlas antes de necesitarlas.

**Leer el error en la propia pantalla.** Mientras la instalación no haya terminado, un fallo
no se queda en «algo salió mal»: la página de error muestra la excepción, el archivo y la
línea. Es a propósito. En ese momento no hay todavía ni cuentas, ni datos de personas, ni
llave de cifrado —no hay nada que filtrar—, y quien instala normalmente no tiene consola con
la que leer `almacen/registro/`. **En cuanto `config/config.php` existe con la marca de
instalado, ese detalle deja de mostrarse solo** y se vuelve al mensaje genérico. Para volver a
verlo en un sitio ya instalado hay que encender `'depurar' => true` a mano.

**Ver qué falta:** <https://tic.narino.gov.co/cumbreAI/instalar/diagnostico>

Dice el estado real —qué archivos hay, qué contesta la base de datos, cuántas cuentas y
eventos existen, y los últimos errores registrados— sin mostrar ninguna credencial. Mientras
la plataforma no funcione es una página pública, igual que el propio asistente; en cuanto
funciona, exige sesión de administrador. Trae un bloque de texto listo para copiar y pegar en
un correo.

Cuando la instalación se cortó a mitad, mira la base de datos con los datos de
`config/instalacion.php`, así que **cuenta las tablas de verdad aunque `config/config.php`
todavía no exista**.

**Instalar sin navegador:** la instalación completa cabe en un solo comando.

```bash
php herramientas/instalar.php \
    --bd-nombre=eventos_tic --bd-usuario=eventos_app --bd-clave='…' \
    --admin-correo=tu@narino.gov.co --admin-nombre="Nombre Apellido" \
    --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
    --url=https://tic.narino.gov.co/cumbreAI
```

Usa las mismas clases que el asistente, así que no hay dos caminos que puedan divergir. Va
contando cada paso y, si algo falla, dice exactamente qué. Es reejecutable: si se interrumpe,
volver a lanzarlo no duplica nada.

**Terminar una instalación cortada a mitad:** si las tablas ya están creadas pero `evt_usuario`
está vacía —lo que deja un asistente interrumpido entre el paso 3 y el 5— no hace falta
repetir nada. `--reparar` hace solo lo que falte:

```bash
php herramientas/instalar.php --reparar \
    --admin-correo=tu@narino.gov.co --admin-nombre="Nombre Apellido" \
    --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
    --url=https://tic.narino.gov.co/cumbreAI
```

Toma los datos de conexión de `config/config.php`, o de `config/instalacion.php` si el
asistente llegó al paso 2, o de `--bd-*`. **Nunca toca las tablas ni borra datos**: crea la
cuenta administradora si falta, el evento si falta, escribe `config/config.php` y borra
`config/instalacion.php`. Si no le pasas `--admin-clave`, genera una y la muestra una sola vez.

En Plesk sin SSH: **Sitios web y dominios → Tareas programadas → Ejecutar un script PHP**, con
la ruta `cumbreAI/herramientas/instalar.php` y los argumentos en su campo. Ejecutar una vez y
borrar la tarea.

> **`config/instalacion.php`.** El paso 2 del asistente guarda ahí los datos de conexión
> mientras dura el proceso, para que la contraseña de la base no viaje en una cookie. El paso
> 6 lo borra. Si lo encuentras en un servidor que ya funciona, es de una instalación que quedó
> a medias y se puede borrar sin miedo.

### 3.6 Correo saliente

**Plesk → Correo** y crea una cuenta como `no-responder@tic.narino.gov.co`.

La plataforma usa la función `mail()` del servidor, que en Plesk sale firmada con el SPF y
el DKIM del dominio. Ajusta en `config/config.php`:

```php
'correo_remitente' => 'no-responder@tic.narino.gov.co',
'correo_nombre'    => 'Cumbre Tecnológica CIOS Nariño',
'modo_correo'      => 'php',   // 'registro' deja los mensajes en almacen/registro
```

Sin correo funcionando, un asistente que pierda su sesión no puede volver a entrar: el
código de acceso es lo único que lo identifica.

---

## 3.7 Entrar al panel

| | |
|---|---|
| **Dirección** | `https://tic.narino.gov.co/cumbreAI/admin/entrar` |
| **Usuario** | El correo que diste en el paso 4 del asistente |
| **Dónde queda guardada** | Tabla `evt_usuario` (el prefijo es el que elegiste en el paso 2) |

Tres cosas que confunden y conviene tener claras:

**No existe una carpeta `admin/` en el servidor.** Todo pasa por `index.php`; `/cumbreAI/admin/`
es una ruta de la aplicación, no un directorio. Si entras ahí sin sesión, responde un 303 y te
lleva a `/cumbreAI/admin/entrar`, que es el formulario. Si el navegador se queda en `/admin/`
mostrando un error del servidor y no ese formulario, el problema es de Apache —falta
`mod_rewrite`— y no de la plataforma.

**La contraseña no está guardada en ninguna parte.** En `evt_usuario` solo queda su hash
Argon2id. Nadie —tampoco quien tenga acceso completo a la base de datos— puede leerla; sí se
puede reemplazar por otra, que es lo que hacen el asistente y la herramienta de consola.

**Los asistentes al evento no están en esa tabla.** `evt_usuario` es solo el equipo
organizador. Quien se preregistra va a `evt_persona` y entra por correo con un código de un
solo uso, sin contraseña.

---

## 3.8 Qué tablas mirar para saber si la instalación grabó sus datos

De las dieciséis tablas, el asistente solo escribe en **seis**. Las otras diez se crean vacías
y se van llenando con el uso. Si acabas de instalar y quieres comprobar que todo quedó
guardado, mira estas seis y en este orden:

| Tabla | Qué debe haber | Si está vacía |
|---|---|---|
| **`evt_usuario`** | **1 fila**: tu cuenta, con `rol = 'administrador'` y `estado = 'activo'` | **Es la que importa.** Sin ella no se puede entrar al panel y todo el sitio redirige al asistente. La instalación se cortó en el paso 4 o 5. |
| **`evt_evento`** | **1 fila**, con `activo = 1` | La parte pública responde 503. Se crea desde el panel, en *Eventos*. |
| **`evt_evento_dia`** | **una fila por jornada** (3 días → 3 filas), cada una con su token de QR | Nadie puede registrar su ingreso. Se regeneran desde el panel. |
| **`evt_evento_tema`** | **1 fila**: colores, tipografía y logo del evento | El evento se ve con el tema por omisión. No es grave. |
| **`evt_migracion`** | al menos **1 fila**, con la versión del esquema (`1.1.0`) | Las actualizaciones futuras no sabrán de dónde parten. |
| **`evt_bitacora`** | al menos **1 fila** con `accion = 'instalacion'` | Solo se pierde el rastro de cuándo se instaló. |

Una consulta que lo dice todo de una vez (cambia `evt_` si usaste otro prefijo):

```sql
SELECT 'evt_usuario' AS tabla, COUNT(*) AS filas FROM evt_usuario
UNION ALL SELECT 'evt_evento',       COUNT(*) FROM evt_evento
UNION ALL SELECT 'evt_evento_dia',   COUNT(*) FROM evt_evento_dia
UNION ALL SELECT 'evt_evento_tema',  COUNT(*) FROM evt_evento_tema
UNION ALL SELECT 'evt_migracion',    COUNT(*) FROM evt_migracion
UNION ALL SELECT 'evt_bitacora',     COUNT(*) FROM evt_bitacora;
```

Las diez restantes —`evt_persona`, `evt_persona_caracterizacion`, `evt_credencial`,
`evt_asistencia`, `evt_contacto`, `evt_charla`, `evt_propuesta`, `evt_sesion`,
`evt_codigo_acceso`, `evt_intento`— **tienen que estar vacías recién instalado**. Que lo estén
es lo correcto: se llenan con el uso.

No hace falta entrar a phpMyAdmin para esto: **`/cumbreAI/instalar/diagnostico`** cuenta lo
mismo en una pantalla, y funciona aunque `config/config.php` todavía no exista.

---

## 4. Si hay nginx por delante

Plesk suele poner nginx como proxy de Apache. Dos consecuencias:

**La IP real llega en una cabecera.** El instalador lo detecta y lo deja configurado: si la
petición llega desde una dirección interna —loopback o rango privado— y además trae una
cabecera de reenvío, esa dirección se anota como proxy de confianza. Queda así en
`config/config.php`:

```php
'proxies_confiables' => ['127.0.0.1'],
```

Solo se hace caso a `X-Forwarded-For` o a `CF-Connecting-IP` cuando la conexión viene de una de
esas direcciones. Si se confiara siempre, cualquiera podría falsear su origen y saltarse los
bloqueos; y si no se confiara nunca, **todos los visitantes compartirían una sola dirección y el
límite de intentos pasaría a ser uno solo para todo el mundo**: veinte accesos fallidos de
cualquiera dejarían fuera al equipo entero.

Si la instalación es anterior o la detección no acertó, `/cumbreAI/instalar/diagnostico` lo
avisa en rojo y muestra la línea exacta que hay que poner.

**Algunos estáticos no pasan por Apache.** Si en Plesk está activada la opción «Servir
archivos estáticos directamente por nginx», las reglas del `.htaccess` no se aplican a esos
archivos. La aplicación no depende de eso —las cabeceras de seguridad también salen desde
PHP y el código comprueba la constante `EVENTOS_TIC`—, pero para que las carpetas internas
queden bloqueadas también en nginx, agrega en **Dominio → Configuración de Apache y nginx →
Directivas adicionales de nginx**:

```nginx
location ~ ^/cumbreAI/(app|config|almacen|docs|herramientas|pruebas|\.git)/ { deny all; }
location ~ ^/cumbreAI/.*\.(sql|log|md|bak|old)$ { deny all; }
```

---

## 5. Comprobación tras instalar

| Qué revisar | Cómo | Qué debe pasar |
|---|---|---|
| Portada | `https://tic.narino.gov.co/cumbreAI/` | Se ve el preregistro |
| Código interno protegido | `.../cumbreAI/app/Nucleo/Bd.php` | 403 o 404, nunca código |
| Configuración protegida | `.../cumbreAI/config/config.php` | 403 o 404, nunca la contraseña |
| Instalador cerrado | `.../cumbreAI/instalar` | «La plataforma ya está instalada» |
| Rutas limpias | `.../cumbreAI/agenda` | Carga la agenda (si da 404, falta `mod_rewrite`) |
| Códigos QR | Administrador → QR por día → Imprimir | El pliego sale con la URL del dominio real |
| Escaneo real | Apunta la cámara del celular al pliego | Abre la plataforma y pide identificarse |

---

## 6. Copias de seguridad

**Plesk → Copias de seguridad → Programar.** Durante la semana del evento, una copia diaria.

Lo que hay que respaldar:

- **La base de datos.** Es donde están los registros y las asistencias.
- **`config/config.php`.** Contiene la llave de cifrado. **Sin ese archivo, los documentos
  de identidad guardados quedan ilegibles para siempre**: no hay forma de recuperarlos. Con
  la misma llave está cifrada la verificación en dos pasos de cada cuenta del equipo. Si el
  archivo se pierde, el asistente pide la llave anterior (la línea `'llave_cifrado' => '…'`
  de la copia) antes de seguir; ver 7.1.
- **`almacen/logos` y `almacen/fotos`.**

Restaura la copia una vez antes del evento, en un entorno de prueba. Una copia que nunca se
restauró no es una copia.

---

## 7. Actualizar a una versión nueva

1. Copia de seguridad completa (base de datos y archivos). **Guarda aparte una copia de
   `config/config.php`**: es lo único que no se puede volver a generar.
2. Sube los archivos nuevos **sin tocar `config/` ni `almacen/`**.
3. **La base se pone al día sola.** Desde la 3.6, la primera visita después de subir los
   archivos —la de quien sea: un asistente que abre su carnet, el equipo que entra al
   panel— agrega las tablas y columnas que la versión nueva necesite y crea las carpetas de
   `almacen/` que falten. Solo agrega: nunca borra ni estrecha nada, así que es seguro con el
   evento en curso. Se hace una sola vez aunque lleguen muchas visitas juntas, y queda en la
   bitácora como «La base de datos se puso al día sola».

   Hasta la 3.5 había que pulsar un botón del panel, y eso tenía una trampa: para llegar al
   botón había que iniciar sesión, y con una base muy vieja el segundo factor fallaba antes
   de llegar. Ya no depende de eso.

   Si el usuario de la base de datos no tiene permiso de `ALTER` —pasa en algunos
   alojamientos—, la actualización automática no puede hacerse: lo anota en el registro,
   vuelve a intentarlo cada cinco minutos y la plataforma sigue funcionando. Para hacerlo a
   mano, o si prefieres apagarla con `'actualizacion_automatica' => false` en
   `config/config.php`, quedan los tres caminos de siempre:

   - **Desde el panel.** Entra en `/admin`. Si la base está atrasada, arriba del todo aparece
     un aviso con el motivo y un botón **«Actualizar la base de datos»**. Agrega las tablas y
     columnas que falten, y ensancha las que cambiaron de tipo —por ejemplo una que pasó a
     admitir vacíos—; nunca borra una columna ni estrecha una que ya tenía datos, así que se
     puede hacer con el evento en curso. Se puede pulsar dos veces sin consecuencias: cada
     cambio se comprueba contra el catálogo de la base antes de aplicarse. Queda en la
     bitácora.
   - **Desde la consola**, si prefieres no tocar el navegador:

     ```bash
     php herramientas/instalar.php --reparar --esquema
     ```

   - **Desde el asistente**, si algo salió mal con los dos anteriores: crea
     `config/permitir-reinstalar`, entra a `/instalar` y sigue los pasos. Con tablas en la
     base, el asistente sugiere **Actualizar** —conserva los datos—; la instalación limpia
     solo se aplica marcando además la casilla que confirma el borrado. En el paso 4 escribe
     el correo de la cuenta que ya usas: solo se le cambia la contraseña, y su verificación en
     dos pasos se conserva. Al terminar, el asistente borra `permitir-reinstalar` por su
     cuenta (si no puede, lo dice en el resumen).

4. Comprueba que el aviso desapareció. Desde la 3.4.1 sale en **todas** las pantallas del
   equipo y del staff, no solo en el panel: mientras esté puesto, las que estrenan columnas no
   funcionan, y la de acreditar responde 503 explicando por qué en vez de un error sin
   sentido. En `/instalar/diagnostico` se ve además si las tablas están completas.

   **Si subiste los archivos y no ves ningún cambio en la plataforma, mira primero ese aviso.**
   Es la causa más común: el código nuevo está, pero la base no se enteró.

### 7.1 Si se perdió `config/config.php`

Pasa cuando, para subir la versión nueva, se borra la carpeta entera de la plataforma. El
sitio vuelve a llevar al asistente, y el asistente necesita la **llave de cifrado** de la
instalación anterior: con ella están cifrados los documentos de identidad y la configuración
de la verificación en dos pasos de cada cuenta.

Hasta la 3.5 el asistente generaba una llave nueva sin decir nada. Era la causa de que, tras
algunas actualizaciones, **el código correcto del teléfono se rechazara siempre** con un
mensaje sobre el reloj: el secreto guardado ya no se podía leer con la llave nueva.

Desde la 3.6, si la base tiene datos cifrados y la configuración no trae llave, el paso 3
pide la anterior:

1. Abre la copia de seguridad de `config/config.php` y busca la línea
   `'llave_cifrado' => '…',`.
2. Pégala en el campo **Llave anterior** (la línea entera o solo el valor). Se comprueba
   contra los datos de la base antes de aceptarla.
3. Termina el asistente como siempre. Todo queda como estaba: las cédulas se leen y cada
   cuenta entra con el mismo código del teléfono.

Si no hay copia, se puede seguir marcando la casilla que lo reconoce. Los documentos guardados
no se podrán leer, y cada cuenta configura otra vez su verificación en dos pasos: la que se
escribe en el paso 4 al entrar, las demás con un código que les llega al correo (o desde
**Organizadores**, ver 8). Por consola es lo mismo con `--llave=…` o `--llave-nueva`.

---

## 8. Problemas frecuentes

### Todas las direcciones llevan al asistente de instalación

Significa una cosa concreta: `Config::instalado()` devuelve falso. O no existe
`config/config.php`, o existe y la marca está en falso.

Abre **`/cumbreAI/instalar/diagnostico`**, que responde aunque el resto del sitio no. Ahí verás
cuál de los dos casos es, y si la base de datos ya tiene las tablas, la cuenta y el evento.

- **Si la base ya está completa** y solo falta la marca, la plataforma se corrige sola en la
  siguiente visita. Si por lo que sea no puede escribir el archivo, `php
  herramientas/instalar.php --reparar` lo hace.
- **Si las tablas están pero `evt_usuario` está vacía**, el asistente se cortó entre el paso 3
  y el 5. Vuelve a `/cumbreAI/instalar` y termina esos dos pasos —no se borra nada de lo ya
  creado—, o hazlo de una vez con `php herramientas/instalar.php --reparar` (ver 3.5.1).
- **Si falta solo el evento**, entra al panel y créalo en *Eventos*.
- **Si no existe `config/config.php`**, la instalación nunca terminó. Lo mismo: asistente o
  consola.

Si `/cumbreAI/instalar` responde **500**, la pantalla de error dice ahora la causa exacta
—excepción, archivo y línea—, porque mientras la instalación no ha terminado no hay nada que
proteger. Con eso se sabe qué arreglar sin necesidad de leer registros por SSH.

### No puedo entrar al panel

Empieza por saber en qué estado está la instalación. Por consola —en Plesk, **Acceso SSH**, o
**Tareas programadas → Ejecutar un script PHP**:

```bash
php herramientas/cuenta.php estado
```

O desde el navegador, en `/cumbreAI/instalar/diagnostico`.

Responde qué hay y qué falta: el archivo de configuración, la conexión, las 16 tablas, cuántas
cuentas administradoras activas existen, cuántos eventos, y el nombre exacto de la tabla donde
viven las cuentas. Con eso, el caso es uno de estos cuatro:

| Lo que dice el diagnóstico | Qué pasó | Cómo se arregla |
|---|---|---|
| `✕ cuentas administradoras activas: 0` | La instalación se interrumpió antes de crear la cuenta | El asistente se reabre solo en **modo reparación**: entra a `/cumbreAI/instalar`. O `cuenta.php crear` |
| Hay cuenta, pero no recuerdas la contraseña | — | `php herramientas/cuenta.php clave --correo=…` |
| Entra pero se queda pidiendo el código de seis dígitos | El segundo factor quedó en un teléfono que ya no está | En la misma pantalla, **«Enviar un código a mi correo»**; después, **Configuración** para escanear un QR nuevo. Sin correo: otra cuenta administradora, desde **Organizadores → Restablecer 2FA**, o `php herramientas/cuenta.php sin-2fa --correo=…` |
| «La configuración de tu verificación en dos pasos no se puede leer» | La llave de cifrado cambió (se perdió `config/config.php`, ver 7.1) | Código por correo desde esa pantalla; o lo mismo que la fila anterior |
| `✕ tablas` o `✕ conexión` | La base no es la que cree, o le falta el esquema | Revisa `config/config.php` y repite el asistente en modo **Actualizar** |

Sobre el código de seis dígitos, desde la 3.6 cada causa tiene su propio mensaje:

- **«Ese código ya se usó»**: se entró con él hace un momento —lo normal tras una
  actualización, que cierra las sesiones—. Basta esperar el siguiente.
- **«La hora de este servidor no coincide con la de tu teléfono»**: el reloj del servidor
  está corrido. La plataforma pide el código siguiente para confirmarlo y desde ahí compensa
  el desfase sola. Conviene igual activar la hora automática (NTP) del servidor; la pantalla
  muestra la hora del servidor para compararla.
- **«No se puede leer»**: ver la tabla de arriba.

Y dos cosas que **no** son el problema, aunque lo parezcan:

- Que `/cumbreAI/admin/` no muestre un formulario. No debe: redirige a `/cumbreAI/admin/entrar`.
- Que la contraseña no aparezca en ninguna tabla. Nunca aparece: se guarda en hash.

Si no hay forma de ejecutar PHP por consola, todo lo anterior menos el diagnóstico se puede
hacer desde el navegador: crea el archivo `config/permitir-reinstalar` con el administrador de
archivos de Plesk, entra a `/cumbreAI/instalar`, elige el modo **Actualizar** —conserva los
datos— y vuelve a dar los datos de la cuenta administradora en el paso 4. Borra
`permitir-reinstalar` al terminar.

### Otros

**Todas las rutas dan 404 menos la portada.**
Falta `mod_rewrite` o `AllowOverride` está en `None`. En Plesk: **Dominio → Configuración
de Apache y nginx**, y verifica que se permitan los `.htaccess`.

**«No se pudo escribir config/config.php».**
Permisos de `config/`. Debe ser escribible por el usuario del dominio.

**«Access denied for user ''@'localhost' (using password: NO)» al entrar al asistente.**
Corregido en esta versión; si lo ves, el servidor tiene código viejo. No era un problema de
permisos de MySQL, por más que el mensaje lo pareciera: el usuario iba **vacío** porque la
aplicación todavía no tenía datos de conexión. Pasaba al hacer una instalación limpia desde un
navegador que conservaba las cookies de sesión de un intento anterior: el asistente pinta un
testigo CSRF en cada formulario, el testigo preguntaba por la sesión, y la sesión consulta la
base de datos —que aún no estaba configurada—. Como la cookie solo la tiene ese navegador, el
error parecía del servidor y no se reproducía desde otro equipo.

Ahora el testigo no consulta la base mientras la plataforma no esté instalada, una sesión que no
se puede leer se trata como «no hay sesión», y conectar sin nombre de base da un mensaje que
dice lo que pasa en vez de hablar de permisos. Si aun así te topas con algo parecido, borrar las
cookies del sitio en el navegador es una solución inmediata.

**«A thread value other than 1 is not supported by this implementation».**
Corregido en esta versión; si lo ves, el servidor tiene código viejo. Era un `ValueError` de
`password_hash()` en el paso 4 del asistente, al convertir la contraseña del administrador.
PHP puede traer Argon2 de dos sitios —la biblioteca `libargon2` suelta o la que va dentro de
`libsodium`— y **la de libsodium solo admite un hilo**. Desde fuera las dos compilaciones son
idénticas: misma versión de PHP, misma constante `PASSWORD_ARGON2ID`, mismo `phpinfo()`. El
código pedía dos hilos, así que funcionaba en unos servidores y reventaba en otros, dejando la
instalación con las tablas creadas y `evt_usuario` vacía. Ahora se pide **un** hilo, que es el
valor por omisión de PHP y el que recomienda OWASP; lo que protege el hash es el coste en
memoria (64 MiB), que no cambió.

**Las tildes salen como signos raros.**
El cotejamiento de la base no es utf8mb4. Créala de nuevo con
`utf8mb4_unicode_ci` y vuelve a instalar.

**El instalador dice que la base de datos ya tiene tablas.**
Es correcto si estás reinstalando. Elige **Actualizar** para conservar los datos o
**Limpio** para empezar de cero —esto último borra todo lo registrado.

**Los correos no llegan.**
Revisa `almacen/registro/`. Si dice «mail() falló», el problema está en el servidor de
correo del dominio, no en la plataforma. Mientras se resuelve, `'modo_correo' => 'registro'`
deja los mensajes en ese archivo para poder seguir probando.

**Alguien escaneó un QR y le pidió identificarse otra vez.**
Es el comportamiento correcto: sin sesión, el código lleva al acceso y después continúa
solo. Si pasa siempre, revisa que las cookies no estén bloqueadas y que el dominio del QR
impreso coincida con el actual.

**El correo da «Connection refused» pero desde SSH `nc -zv smtp.gmail.com 587` sí conecta.**
El bloqueo está puesto **por usuario**: root queda exento y el usuario con el que corre PHP-FPM
no. Casi siempre es `SMTP_BLOCK` de ConfigServer Firewall. Se añade el usuario del dominio a
`SMTP_ALLOWUSER` en `/etc/csf/csf.conf` y se recarga con `csf -r`. El usuario exacto sale en
**Administración → Correo → Probar la salida de red**, campo «usuario de PHP». Ver
`docs/config-mail.md`, apartado 5.4. Para probar las credenciales mientras tanto:
`php herramientas/correo.php probar` desde SSH, donde el bloqueo no aplica.

**La consola del navegador se queja de `static.cloudflareinsights.com/beacon.min.js`.**
No es un fallo de la plataforma y no rompe nada. Cloudflare, cuando tiene **Web Analytics**
encendido, inyecta ese script en el HTML de salida; la política de contenidos de la
aplicación solo permite scripts del propio sitio y el navegador lo bloquea. La página
funciona igual: la aplicación no depende de ese script para nada. Dos formas de que el
mensaje desaparezca:

- **Recomendada:** apagarlo en Cloudflare (**Analytics & Logs → Web Analytics**, quitar el
  hostname o desactivar *Automatic Setup*). Deja la política de contenidos como está, que es
  lo más seguro.
- Si la Gobernación quiere conservar esa analítica, permitirlo a propósito en
  `config/config.php`:

  ```php
  'permitir_cloudflare_analytics' => true,
  ```

  Añade `https://static.cloudflareinsights.com` a `script-src` y
  `https://cloudflareinsights.com` a `connect-src`, y nada más.

---

## 9. Antes de abrir al público

- [ ] HTTPS activo, con redirección permanente y HSTS.
- [ ] `/instalar` responde 403.
- [ ] `config/config.php` y `app/` no son accesibles desde el navegador.
- [ ] Correo saliente probado con una cuenta real.
- [ ] Segundo factor activado en todas las cuentas de administrador.
- [ ] Copia de seguridad programada **y una restauración probada**.
- [ ] Códigos QR de las jornadas impresos con el dominio definitivo.
- [ ] Contraste del tema revisado (Identidad → Revisión de contraste): la puerta del
      recinto suele tener sol directo.
