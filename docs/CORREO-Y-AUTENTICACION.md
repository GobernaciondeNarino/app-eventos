# Correo y autenticación · guía completa

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño
Versión 3.0

Todo lo que hay que hacer **en el servidor** y **en el sistema** para que la gente pueda entrar
al evento y recibir sus mensajes. Sustituye a la parte operativa de `config-mail.md`, que se
conserva como bitácora de lo que se fue descubriendo.

---

## Índice

1. [Lo primero: no depender solo del correo](#1)
2. [Los cinco métodos de acceso](#2)
3. [Correo con Gmail / Google Workspace](#3)
4. [Correo con buzón interno del servidor](#4)
5. [El bloqueo de SMTP saliente, paso a paso](#5)
6. [WhatsApp](#6)
7. [SMS](#7)
8. [Comprobación final](#8)
9. [Diagnóstico rápido](#9)

---

<a id="1"></a>
## 1. Lo primero: no depender solo del correo

Durante semanas el único camino para entrar fue un código al correo. Cuando el correo dejó de
salir, **nadie podía entrar**. No fue un problema de correo: fue un punto único de fallo.

**Recomendación firme:** deja encendidos al menos **dos** métodos, y que uno sea el **QR de
acceso**, que no depende de red, ni de terceros, ni de que la persona recuerde nada.

Se configura en **Administración → Configuración → Acceso y correo**, que está organizada por
pestañas: una por método, con su configuración y sus instrucciones juntas. Cada pestaña guarda
lo suyo, así que tocar WhatsApp no altera lo que tengas puesto en SMS.

Desde la versión 3.2 una instalación nueva viene con **correo, QR y contraseña encendidos**.
Es deliberado: ninguno de los tres depende de un tercero ni cuesta dinero, y que la
instalación por omisión dependiera de que el correo saliera es justo lo que dejó un evento sin
acceso.

---

<a id="2"></a>
## 2. Los cinco métodos de acceso

| Método | Necesita | Funciona sin salida SMTP | Cuándo usarlo |
|---|---|---|---|
| **Correo electrónico** | Envío de correo funcionando | No | El habitual, si el correo sale |
| **QR de acceso** | Nada | **Sí** | **Siempre encendido.** Es el respaldo que nunca falla |
| **Contraseña simple** | Nada | **Sí** | Si se prefiere algo clásico |
| **WhatsApp** | Cuenta Meta o Twilio | **Sí** (HTTPS 443) | El canal que más gente lee |
| **SMS** | Cuenta Twilio o pasarela local | **Sí** (HTTPS 443) | Llega sin datos móviles |

### QR de acceso — no hay nada que configurar

Se genera solo para cada persona. Aparece en su carnet, en su propia tarjeta y **distinto del
QR de contacto**: el de contacto se enseña a cualquiera para intercambiar datos; el de acceso
abre la sesión de su dueño. Que fueran el mismo convertiría cada foto de una escarapela en una
llave.

Se escanea con la cámara del teléfono, sin aplicación. Si alguien pierde la escarapela, se
regenera su código desde **Registros → botón de perfil → «Anular y generar otro»**, y el
anterior deja de servir en el acto.

El enlace de bienvenida que llega por correo también usa este código, así que abrir ese correo
desde el teléfono entra directo, sin pedir nada.

### Contraseña simple

La persona la elige en el preregistro; se guarda con Argon2id. Quien se preregistró **antes** de
encender el método no tiene contraseña: entrará por otro y podrá ponerla después desde
*Mis datos*.

**Se puede crear el acceso con solo la contraseña.** Quien llega a la pantalla de ingreso sin
estar inscrito encuentra **«Crear mi acceso en un minuto»**: correo, contraseña y la
autorización de tratamiento de datos, y ya está dentro. El resto del formulario lo completa
después, sin la fila detrás.

El carnet **no** se emite hasta que el registro esté completo —hace falta al menos el nombre y
la identificación—, y mientras tanto la plataforma lo lleva al formulario en vez de enseñarle
pantallas vacías. En *Registros* esas personas salen marcadas como **«Sin completar»**, para
verlo antes de la puerta y no en ella.

Este método tiene que estar encendido para que aparezca esa opción: sin él, la contraseña que
se eligiera no serviría para volver a entrar, así que la pantalla manda al formulario completo.

**Su contraseña no se puede consultar.** En la base hay un hash Argon2id, que es de un solo
sentido; si se pudiera leer, quien copiara la tabla tendría en claro las de todos. Cuando
alguien no la recuerda, la ficha de *Registros* ofrece **generar una nueva** —se muestra una
sola vez, sin letras ni números que se confundan al dictarlos— o **enseñarle su QR de acceso**,
que entra sin escribir nada.

### El teléfono queda recordado

Independiente de los cinco métodos: cuando alguien entra por cualquiera de ellos, la plataforma
deja una marca en ese dispositivo que dura seis meses. La próxima vez no le vuelve a pedir
nada, aunque hayan pasado los treinta días de la sesión.

Es lo que resuelve el caso más frecuente: la persona se preregistra en el navegador y días
después abre el enlace desde el correo o desde WhatsApp, que usan su propio almacén de cookies.
Sin la marca acababa en «identifícate» con el carnet ya emitido.

Cada quien puede retirar sus dispositivos desde su carnet, y un administrador desde
*Registros*. Salir de la sesión también los olvida.

---

<a id="3"></a>
## 3. Correo con Gmail / Google Workspace

### 3.1 En Google

1. Entrar a `myaccount.google.com` con `hosting@narino.gov.co`.
2. **Seguridad → Verificación en dos pasos**: tiene que estar **activa**.
3. Al final de esa página, **Contraseñas de aplicaciones** → crear una llamada `eventos`.
4. Google muestra **16 letras en cuatro grupos**. Se ven **una sola vez**.

> Si la opción no aparece, el administrador de Workspace la tiene bloqueada en la consola de
> administración.

> **Una contraseña de aplicación da acceso completo a la cuenta.** Si se comparte por chat o
> aparece en una captura, hay que **revocarla** y generar otra.

### 3.2 En la plataforma

**Administración → Configuración → Acceso y correo → Envío de mensajes**

| Campo | Valor |
|---|---|
| Modo de envío | Servidor SMTP |
| Remitente | `hosting@narino.gov.co` |
| Servidor | `smtp.gmail.com` |
| Puerto | `587` |
| Seguridad | STARTTLS |
| Usuario | `hosting@narino.gov.co` |
| Contraseña de aplicación | las 16 letras (los espacios se quitan solos) |
| Verificar certificado | activado |
| Usar solo IPv4 | **activado** si el diagnóstico de red muestra `Network is unreachable` en IPv6 |

El **remitente tiene que ser la cuenta autenticada**, o un alias verificado en *Gmail →
Configuración → Cuentas → Enviar como*. Si no, Google responde `550-5.7.1`.

### 3.3 En el DNS de narino.gov.co

```
narino.gov.co.        TXT   "v=spf1 include:_spf.google.com ~all"
_dmarc.narino.gov.co. TXT   "v=DMARC1; p=none; rua=mailto:dmarc@narino.gov.co"
```
Y **DKIM** generado en *Consola de administración → Aplicaciones → Google Workspace → Gmail →
Autenticar correo*, publicando el TXT que entregue.

### 3.4 Límites

2.000 mensajes al día por cuenta de Workspace. Una convocatoria masiva los agota: repártela.

---

<a id="4"></a>
## 4. Correo con buzón interno del servidor

Aquí **no existe la «contraseña de aplicación»** — eso es un concepto de Google y de Microsoft.
Es sencillamente **la contraseña del buzón**, la que se puso al crearlo. La pantalla lo detecta
y cambia el rótulo sola.

### 4.1 Crear el buzón en Plesk

**Plesk → Correo → Crear dirección de correo**

- Dirección: `noreply@tic.narino.gov.co`
- Contraseña: una larga, generada; se guarda en la plataforma y no se usa para nada más.

> **Ojo con el dominio.** Tiene que ser un dominio que **este Plesk gestione**. Si el buzón de
> `narino.gov.co` está en Google, un mensaje que salga de este servidor diciendo venir de
> `@narino.gov.co` no pasa SPF y acaba en no deseado. Por eso `tic.narino.gov.co` y no
> `narino.gov.co`.

### 4.2 Dos formas de usarlo

| | Relé local sin credenciales | Buzón con autenticación |
|---|---|---|
| Servidor | `localhost` | `tic.narino.gov.co` |
| Puerto | `25` | `587` |
| Seguridad | Sin cifrar | STARTTLS |
| Usuario | *(vacío)* | `noreply@tic.narino.gov.co` |
| Contraseña | *(vacía)* | la del buzón |
| Cuándo | Lo más simple; Postfix confía en localhost | Si Plesk exige autenticación |

El botón **«Usar el correo local»** del diagnóstico de red deja la primera opción configurada de
un clic.

### 4.3 El DNS del subdominio

Plesk los genera al crear el dominio de correo, pero hay que **comprobar que estén publicados**:

```
tic.narino.gov.co.        TXT   "v=spf1 a mx ip4:<IP-del-servidor> ~all"
default._domainkey.tic…   TXT   "v=DKIM1; k=rsa; p=…"     ← Plesk → Correo → DKIM
_dmarc.tic.narino.gov.co. TXT   "v=DMARC1; p=none;"
```

### 4.4 Lo que hay que saber, y no es evidente

**El correo local acepta el mensaje, pero después tiene que entregarlo.** En la cola de Plesk se
vio esto:

```
Pospuestos · 4.4.1 connect to smtp.google.com[74.125.197.27]:25: …
noreply@tic.narino.gov.co → hosting@narino.gov.co
```

La plataforma entregó bien; **Postfix no pudo salir por el puerto 25** hacia Google y los
mensajes quedaron encolados. Con la salida SMTP bloqueada, el correo local sirve para dominios
del propio servidor pero **no para entregar fuera**.

**Solución:** configurar Postfix para que relaye por Gmail en el 587, autenticado. Postfix corre
como el usuario `postfix`, que **sí está exento** de la regla del cortafuegos.

```bash
# /etc/postfix/main.cf
relayhost = [smtp.gmail.com]:587
smtp_use_tls = yes
smtp_sasl_auth_enable = yes
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_sasl_security_options = noanonymous
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt

# /etc/postfix/sasl_passwd
[smtp.gmail.com]:587 hosting@narino.gov.co:LAS16LETRASSINESPACIOS
```

```bash
chmod 600 /etc/postfix/sasl_passwd
postmap /etc/postfix/sasl_passwd
systemctl reload postfix
postqueue -f          # reintentar lo encolado
mailq                 # ver si se vació
```

Con esto la plataforma envía a `localhost`, Postfix relaya por Gmail autenticado, y **no hace
falta tocar el cortafuegos**.

---

<a id="5"></a>
## 5. El bloqueo de SMTP saliente, paso a paso

### 5.1 Confirmarlo

Desde SSH **como root**:

```bash
nc -zv smtp.gmail.com 587
```

- **Conecta** → la máquina puede salir; el bloqueo es **por usuario**. Sigue en 5.2.
- **No conecta** → el bloqueo es del proveedor. Pídele que abra 587 y 465, o usa la API (§ 6 de
  `config-mail.md`).

Y desde el navegador: **Administración → Configuración → Acceso y correo → Probar la salida de
red**. Eso corre
**como el usuario de PHP**, que es el que importa. Si ahí sale `Connection refused` y por SSH
conecta, está confirmado.

### 5.2 Encontrar la regla

```bash
iptables-save | grep owner
nft list ruleset | grep -i skuid
grep -E "^SMTP_BLOCK|^SMTP_ALLOWUSER" /etc/csf/csf.conf
```

La regla estándar de Plesk cubre solo el 25; si falla en 587 y 465, está ampliada (Imunify360 lo
hace).

### 5.3 Levantarlo para el usuario del dominio

El UID sale en la pantalla de diagnóstico, campo **«usuario de PHP»**. También:

```bash
ps -o user,cmd -C php-fpm | head
```

**Con ConfigServer Firewall** (lo más común en Plesk):

```bash
# /etc/csf/csf.conf
SMTP_ALLOWUSER = "root,mailnull,mail,postfix,<usuario-del-dominio>"
csf -r
```

**Con iptables a mano:**

```bash
iptables-save > /root/reglas-antes-de-correo.txt
iptables -I OUTPUT 1 -m owner --uid-owner <UID> -p tcp -m multiport --dports 587,465 -j ACCEPT
service iptables save        # RHEL/AlmaLinux
netfilter-persistent save    # Debian/Ubuntu
```

> **Esa regla existe por algo.** Cierra la salida SMTP para que un script comprometido no
> convierta el servidor en relé de spam. **Autoriza al usuario del dominio; no la desactives
> para todos.**

### 5.4 Comprobar

```bash
php herramientas/correo.php estado
php herramientas/correo.php probar
```

Y en el navegador, **Probar la salida de red** otra vez: los puertos deben salir en verde.

---

<a id="6"></a>
## 6. WhatsApp

Sale por **HTTPS al 443**: funciona aunque el SMTP siga bloqueado.

### 6.1 Con Meta (WhatsApp Cloud API) — recomendado

1. `developers.facebook.com` → **Crear app** → tipo *Business*.
2. Añadir el producto **WhatsApp**.
3. En **API Setup**, copiar el **Phone number ID** → campo «Cuenta».
4. **Business Settings → System users** → crear uno, darle acceso a la app, generar un
   **token permanente** con permisos `whatsapp_business_messaging` → campo «Token».
5. Registrar y verificar el número de la Gobernación.

### 6.2 Con Twilio

«Cuenta» = `Account SID` (empieza por AC), «Token» = *Auth Token*, «Número emisor» = el número
de WhatsApp aprobado con indicativo.

### 6.3 La plantilla, que es obligatoria

WhatsApp **solo deja texto libre dentro de las 24 h siguientes a que la persona escriba**. Para
iniciar la conversación hace falta una **plantilla aprobada**:

- Categoría **Authentication** (se aprueba en horas y es gratuita).
- Cuerpo: `{{1}} es tu código de verificación.`

Sin plantilla, el primer mensaje a alguien que nunca escribió será rechazado.

### 6.4 En la plataforma

**Administración → Configuración → Acceso y correo** → marcar *WhatsApp* → completar proveedor,
cuenta y token.
El teléfono de cada persona sale de su preregistro; se acepta en cualquier formato y se
normaliza a `+57…`.

---

<a id="7"></a>
## 7. SMS

### 7.1 Con Twilio

«Cuenta» = `Account SID`, «Token» = *Auth Token*, «Emisor» = un número de *Phone Numbers* con
SMS habilitado hacia Colombia.

### 7.2 Con una pasarela local

Para Colombia suele dar mejor entrega. En «Cuenta» va la URL completa que dé el proveedor, con
marcadores:

```
https://pasarela.example/api/enviar?to={telefono}&msg={texto}&from={remitente}
```

Se sustituyen `{telefono}`, `{texto}` y `{remitente}`, y el token va como
`Authorization: Bearer`.

### 7.3 Costo

Cada SMS se cobra. El límite de la plataforma —cuatro por hora y destinatario— acota el gasto,
pero conviene revisar el consumo durante el evento.

---

<a id="8"></a>
## 8. Comprobación final

Antes de abrir al público:

- [ ] Al menos **dos métodos** de acceso activos, y uno es el **QR**.
- [ ] `php herramientas/correo.php probar` termina en verde.
- [ ] **Probar la salida de red** en el panel: sin rojos.
- [ ] Prueba de envío recibida en la **bandeja de entrada**, no en no deseado.
- [ ] En Gmail, *Mostrar original*: `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`.
- [ ] `mailq` vacío: nada encolado.
- [ ] Si hay WhatsApp: plantilla *Authentication* aprobada y probada.
- [ ] Si hay SMS: un mensaje recibido de verdad en un teléfono colombiano.
- [ ] Un participante de prueba entra por **cada** método activo.

---

<a id="9"></a>
## 9. Diagnóstico rápido

| Síntoma | Causa | Dónde mirar |
|---|---|---|
| `Connection refused` en 587/465/25, inmediato | Cortafuegos por UID | § 5 |
| `Network is unreachable` | IPv6 sin ruta | Marcar «Usar solo IPv4» |
| `Connection timed out` | Puerto filtrado por el proveedor | Pedir apertura |
| `535-5.7.8` | Contraseña que no es de aplicación | § 3.1 |
| `550-5.7.1` | Remitente ≠ cuenta autenticada | § 3.2 |
| Mensajes en la cola con `4.4.1` | Postfix no puede salir por el 25 | § 4.4 |
| Llega a no deseado | SPF/DKIM sin publicar | § 3.3 y § 4.3 |
| WhatsApp: `template not found` | Falta la plantilla aprobada | § 6.3 |
| Nadie puede entrar y el correo falló | Un solo método activo | § 1 |

### Órdenes útiles

```bash
# Desde la plataforma
php herramientas/correo.php estado          # DNS, puertos, relé local, PHP
php herramientas/correo.php probar          # conectar y autenticar
php herramientas/correo.php enviar --a=…    # envío completo

# Desde el servidor
nc -zv smtp.gmail.com 587                   # ¿sale la máquina?
mailq                                        # ¿hay algo encolado?
postqueue -f                                 # reintentar la cola
tail -f /var/log/maillog                     # qué dice Postfix
grep -E "^SMTP_BLOCK|^SMTP_ALLOWUSER" /etc/csf/csf.conf
ps -o user,cmd -C php-fpm | head             # con qué usuario corre PHP
```

---

## Historial

| Fecha | Qué |
|---|---|
| 2026-08-17 | Documento creado con la versión 3.0. Recoge Gmail, buzón interno, WhatsApp y SMS en un solo sitio. |
| 2026-08-17 | Hallazgo nuevo desde la cola de Plesk: el correo local **acepta** los mensajes pero Postfix **no puede entregarlos** por el 25 (`4.4.1`). Se documenta el relayhost autenticado, que funciona porque `postfix` está exento de la regla del cortafuegos. |
| 2026-08-17 | El rótulo de la contraseña se adapta: «de aplicación» solo con Google o Microsoft; «del buzón» con un servidor propio. |
