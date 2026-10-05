# Configuración de correo

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño

> **Para configurar desde cero, usa [CORREO-Y-AUTENTICACION.md](CORREO-Y-AUTENTICACION.md).**
> Ese documento es la guía operativa: Gmail, buzón interno, WhatsApp, SMS y el cortafuegos, en
> el orden en que hay que hacerlo. Este de aquí es la **bitácora**: qué se fue descubriendo, en
> qué orden y por qué se descartó cada hipótesis. Sirve para no repetir un camino ya recorrido.

Este documento recoge cómo queda configurado el envío de correo para
**https://tic.narino.gov.co/cumbreAI/**, por qué se hizo así, y qué mirar cuando algo falle.

---

## 0. Por qué importa

Sin correo funcionando, la plataforma pierde la mitad de lo que hace:

- **El asistente que cierre sesión no puede volver a entrar.** No tiene contraseña: entra con
  un código de seis dígitos que se le manda al correo. Si el correo no sale, se queda fuera.
- **El carnet no llega.** Se genera en pantalla al terminar el preregistro, y quien cierre esa
  pestaña sin guardarla pierde el enlace.
- **El equipo organizador no puede restablecer contraseñas** sin pasar por la consola.

Por eso el módulo no se limita a guardar unos campos: comprueba, prueba contra el servidor de
verdad y explica cada código de error.

---

## 1. Qué hay que saber del correo institucional

| | |
|---|---|
| **Cuenta** | `hosting@narino.gov.co` |
| **Proveedor** | Google Workspace |
| **Servidor SMTP** | `smtp.gmail.com` |
| **Puerto** | `587` con STARTTLS (alternativa: `465` con SSL directo) |
| **Usuario** | La dirección completa: `hosting@narino.gov.co` |
| **Contraseña** | Una **contraseña de aplicación** de 16 letras. **No sirve la contraseña normal de la cuenta.** |
| **Cupo diario** | ~2.000 mensajes (Workspace). Una cuenta gratuita son 500. |

### Por qué SMTP y no la función `mail()` de PHP

`mail()` entrega al servidor de correo local del servidor web. Ese servidor **no es el que
gestiona narino.gov.co**: el buzón está en Google. Un mensaje que salga por ahí diciendo venir
de `@narino.gov.co` no pasa SPF ni lleva firma DKIM del dominio, así que llega a no deseado en
el mejor caso y rebota en el peor.

Hablando SMTP directamente con Google, el mensaje sale autenticado como la cuenta institucional,
con la firma que Google le pone, y llega a la bandeja de entrada.

> **`mail()` no está descartado.** Funciona en este servidor —WordPress envía desde ahí— y es la
> salida cuando el proveedor no deja salir por SMTP. Lo que hay que cambiar entonces es el
> **remitente**, no el modo: ver el apartado 5.2.

---

## 2. Obtener la contraseña de aplicación

1. Entrar a `myaccount.google.com` con `hosting@narino.gov.co`.
2. **Seguridad → Verificación en dos pasos.** Tiene que estar activa; sin ella Google no ofrece
   contraseñas de aplicación.
3. Al final de esa página: **Contraseñas de aplicaciones**.
4. Crear una con un nombre reconocible — por ejemplo `eventos`.
5. Google muestra **16 letras en cuatro grupos**. Se copian en el panel; los espacios se quitan
   solos al guardar.

> **Solo se ven una vez.** Si se pierden, se revoca esa y se genera otra: no se pueden recuperar.

> **Una contraseña de aplicación da acceso completo a la cuenta.** Si se filtra —por chat, por
> correo, en una captura de pantalla— hay que **revocarla desde esa misma pantalla** y generar
> otra. No basta con cambiarla en la plataforma.

Si la opción no aparece, la cuenta es de Workspace y el administrador del dominio la tiene
bloqueada en la consola de administración (**Seguridad → Controles de API / Acceso de
aplicaciones menos seguras**).

---

## 3. Configurar en la plataforma

**Administración → Configuración → Acceso y correo** (solo rol administrador). Hasta la 3.6 era
una entrada propia del menú, *Correo*; la dirección `/admin/correo` sigue funcionando.

| Campo | Valor |
|---|---|
| Modo de envío | **Servidor SMTP** |
| Remitente | `hosting@narino.gov.co` |
| Nombre del remitente | `Secretaría TIC · Gobernación de Nariño` |
| Servidor | `smtp.gmail.com` |
| Puerto | `587` |
| Seguridad | **STARTTLS** |
| Usuario | `hosting@narino.gov.co` |
| Contraseña de aplicación | las 16 letras |
| Espera máxima | `15` segundos |
| Verificar certificado | **activado** |

Después, **Probar ahora** con una dirección propia. Sin dirección, solo comprueba que se puede
conectar y autenticar; con dirección, envía un mensaje completo.

### El remitente tiene que ser la cuenta autenticada

Google solo deja enviar «como» la cuenta con la que uno se autentica, o como un alias dado de
alta y **verificado** en *Gmail → Configuración → Cuentas → Enviar como*. Poner en «Remitente»
una dirección distinta sin haberla verificado termina en `550-5.7.1`, o en que Google reescriba
el remitente sin avisar.

Lo más simple: la misma dirección en «Remitente» y en «Usuario».

---

## 4. Dónde queda guardado

En `config/config.php`, junto al resto de la configuración:

```php
'modo_correo'                => 'smtp',
'correo_remitente'           => 'hosting@narino.gov.co',
'correo_nombre'              => 'Secretaría TIC · Gobernación de Nariño',
'smtp_host'                  => 'smtp.gmail.com',
'smtp_puerto'                => 587,
'smtp_seguridad'             => 'tls',        // 'tls' | 'ssl' | 'ninguna'
'smtp_usuario'               => 'hosting@narino.gov.co',
'smtp_clave'                 => '····················',
'smtp_espera'                => 15,
'smtp_verificar_certificado' => true,
'smtp_solo_ipv4'             => false,       // true = no intentar IPv6

// Solo con modo_correo = 'api'
'api_proveedor'              => 'brevo',     // 'brevo' | 'sendgrid' | 'resend'
'api_clave'                  => '····················',
```

Sobre cómo se guarda la contraseña, con honestidad: **queda en claro en ese archivo**, igual que
la contraseña de la base de datos y la llave de cifrado. Cifrarla no aportaría nada real, porque
la llave con la que se descifraría vive en el mismo archivo. Lo que la protege es:

- El archivo tiene permisos `0640` y pertenece al usuario del dominio.
- `.htaccess` bloquea toda la carpeta `config/` por web.
- Está en `.gitignore`: nunca llega al repositorio.
- **La plataforma no la devuelve nunca al navegador.** El formulario muestra un campo vacío y la
  nota «hay una guardada»; dejarlo en blanco significa «no la cambies».
- **No aparece en la transcripción SMTP** que se enseña en pantalla: esa línea sale como
  `[contraseña en base64]`.
- **No entra en la bitácora.** Solo se anota que se cambió, no cuál.

Quien pueda leer `config/config.php` ya tiene la base de datos entera y la llave de cifrado; el
correo no es el eslabón débil ahí.

---

## 5. Cuando algo falla

**Configuración → Acceso y correo** enseña, en este orden:

1. **Estado de la configuración** — lo que está mal *antes* de tocar la red: modo equivocado,
   puerto que no cuadra con la seguridad, contraseña que no parece de aplicación, remitente que
   no es la cuenta autenticada, `openssl` ausente.
2. **Resultado de la última prueba** — el código del servidor, su texto, qué hacer, y la
   **conversación completa** con el servidor.
3. **Tabla de códigos** — de referencia, siempre visible.

### Códigos y qué significan

| Código | Qué dice el servidor | Qué pasa | Qué hacer |
|---|---|---|---|
| `535-5.7.8` | Username and Password not accepted | La contraseña no es de aplicación, o el usuario no lleva dominio | Generar contraseña de aplicación; usuario = dirección completa |
| `534-5.7.9` | Application-specific password required | La cuenta tiene 2FA y se mandó la contraseña normal | Contraseña de aplicación |
| `534-5.7.14` | Please log in via your web browser | Google no reconoce el servidor | Abrir `accounts.google.com/DisplayUnlockCaptcha` y reintentar en 10 min |
| `530-5.5.1` | Authentication Required | Sin credenciales, o antes de cifrar | Completar usuario y contraseña; STARTTLS en el 587 |
| `454-4.7.0` | Too many login attempts | Demasiados intentos seguidos | Esperar unos minutos |
| `550-5.7.1` | Not allowed to send as | Remitente que no es la cuenta ni un alias verificado | Igualar «Remitente» y «Usuario» |
| `550-5.4.5` | Daily user sending limit exceeded | Cupo diario agotado | Repartir los envíos; ver el apartado 7 |
| `550-5.1.1` | User unknown | La dirección de destino no existe | Revisar cómo está escrita |
| `553-5.1.8` | Domain of sender address does not exist | El dominio del remitente no resuelve | Usar un dominio real con MX |
| `552-5.3.4` | Message too large | Mensaje demasiado grande | No debería pasar aquí |
| `421-4.7.0` | Try again later | Fallo temporal o límite de frecuencia | Reintentar más tarde |
| `550-5.7.30` | Basic authentication is not supported | **Microsoft 365**: la autenticación básica está apagada de forma permanente desde octubre de 2022 | Usuario y contraseña ya no sirven; hace falta OAuth2 o el relé SMTP del tenant con la IP autorizada |
| `550-5.7.515` | Does not meet the required authentication level | **Microsoft**: falta SPF/DKIM/DMARC y se superan los 5.000 diarios | Publicar los tres registros del dominio |
| — | Connection refused · Timed out | El puerto de salida está cerrado | Probar 465/SSL si el 587 no responde |
| — | TLS / certificado | El cifrado no se estableció | `openssl` activo y reloj del servidor en hora |

### Si el puerto está cerrado

Muchos proveedores —OVH entre ellos— cierran la salida SMTP para frenar el envío masivo. El 25
está cerrado casi siempre; el 587 y el 465 suelen estar abiertos, pero no siempre los dos.

Desde SSH:

```bash
nc -vz smtp.gmail.com 587
nc -vz smtp.gmail.com 465
```

Si ninguno responde, hay que pedir la apertura al proveedor. Un proxy de salida HTTP no ayuda:
PHP no lo usa para SMTP.

---

## 5.1 Cuando la conexión ni siquiera se abre

Es un caso distinto y hay que tratarlo aparte: si el error aparece **antes** de que el servidor
salude, el problema es de red y no de correo. Los tres mensajes que salen y lo que significa
cada uno:

| Mensaje | Qué está pasando | A quién se le pide |
|---|---|---|
| `Network is unreachable` · `No route to host` | El sistema **no sabe por dónde salir**. Falla al instante. Casi siempre es IPv6. | A nadie: se arregla en la plataforma |
| `Connection timed out` | Los paquetes se descartan en silencio: **puerto filtrado**. Tarda hasta agotar la espera. | Al proveedor: que abra la salida |
| `Connection refused` | Algo respondió «aquí no». Cortafuegos local o proxy de salida. | Al administrador del servidor |

### El caso de IPv6, que es el que se dio aquí

`smtp.gmail.com` publica **dos** direcciones:

```
A     192.178.212.108
AAAA  2607:f8b0:4001:c74::6c
```

Si el servidor resuelve la IPv6 pero no tiene ruta de salida por ahí —lo normal en un VPS al
que nadie le configuró IPv6—, `stream_socket_client()` falla **al instante** con
`Network is unreachable`. Y como el mensaje sale igual en el 587 y en el 465, parece que el
proveedor bloquea todo el SMTP, cuando por IPv4 la conexión funciona perfectamente.

**Aplicado:** el cliente resuelve el nombre y prueba las direcciones **IPv4 primero**, IPv6 de
reserva. Cada intento queda anotado en la transcripción, así que se ve cuál salió y cuál no. Si
se quiere no intentar IPv6 siquiera, hay una casilla **«Usar solo IPv4»** en la pantalla.

### El botón «Probar la salida de red»

En **Configuración → Acceso y correo**, debajo de «Probar ahora». No manda ningún correo: resuelve el
nombre y prueba a abrir los puertos **587, 465 y 25**, por IPv4 y por IPv6, con espera corta.
Devuelve una tabla y una conclusión:

```
Puerto  Familia  Dirección              Resultado
587     v4       192.178.212.108        ✓ abre · 42 ms
587     v6       2607:f8b0:4001:c74::6c ✕ Network is unreachable
465     v4       192.178.212.108        ✓ abre · 39 ms
...
```

Con eso se sabe en diez segundos si hay que pedirle algo al proveedor o no. También informa de
cómo está PHP en el servidor: `mail()`, `sendmail_path`, `openssl` y `disable_functions`.

---

## 5.2 Si el proveedor no deja salir por SMTP

Hay una salida, y en este servidor está comprobada: **WordPress envía correo desde la misma
máquina**. Eso demuestra que la entrega local funciona, y la plataforma puede usarla con el modo
**«Función mail() del servidor»**.

Pero hay que entender el precio, porque no es gratis:

> `mail()` entrega al servidor de correo **local**. El mensaje sale desde la IP de este
> servidor. Si dice venir de `@narino.gov.co` —cuyo SPF autoriza solo a Google—, el receptor ve
> un remitente no autorizado y lo manda a no deseado, o lo rechaza.

**La solución práctica:** usar como remitente una dirección del **subdominio que este Plesk sí
gestiona**. Si `tic.narino.gov.co` tiene su buzón en este servidor:

1. **Plesk → Correo → Crear dirección**: `no-responder@tic.narino.gov.co`.
2. En la plataforma, modo **Función mail()** y remitente `no-responder@tic.narino.gov.co`.
3. Comprobar que el DNS de `tic.narino.gov.co` tenga SPF con la IP del servidor y DKIM activo
   —Plesk los genera solo al crear el dominio de correo.

Así el mensaje sale autenticado por el dominio que de verdad lo envía, y llega. Es lo mismo que
hace WordPress ahí.

La pantalla de correo avisa de esto sola: si el modo es `mail()` y el dominio del remitente no
coincide con el del servidor, aparece el aviso con la dirección concreta que habría que usar.

**Cuál elegir:**

| | SMTP a Google | mail() con remitente del subdominio |
|---|---|---|
| Necesita salida al 587/465 | **Sí** | No |
| Remitente institucional | `hosting@narino.gov.co` | `no-responder@tic.narino.gov.co` |
| SPF/DKIM | Los de Google, ya listos | Los del subdominio, los pone Plesk |
| Cupo | 2.000/día | El del servidor |
| Preferible | **Sí**, si hay salida | Cuando no la hay |

---

## 5.3 La salida SMTP está bloqueada: usar el correo local

Es lo que pasa en este servidor, y conviene entenderlo bien porque la solución es de un clic.

### Cómo se sabe

El diagnóstico de red devolvió esto:

```
Puerto  Familia  Dirección              Resultado
587     v4       74.125.197.108         ✕ Connection refused
587     v6       2607:f8b0:4020:c0b::6d ✕ Network is unreachable
465     v4       74.125.197.108         ✕ Connection refused
465     v6       2607:f8b0:4020:c0b::6d ✕ Network is unreachable
25      v4       74.125.197.108         ✕ Connection refused
25      v6       2607:f8b0:4020:c0b::6d ✕ Network is unreachable
```

**`Connection refused` inmediato en los tres puertos por IPv4** no es un puerto filtrado —ese se
queda esperando hasta agotar el tiempo— ni una red sin ruta —esa da `unreachable`—. Es un
cortafuegos con **regla de rechazo**: alguien decidió cerrar la salida SMTP. Es lo habitual en
alojamiento compartido, para que nadie use el servidor como relé de spam.

Ningún arreglo del cliente puede saltarse eso.

### La salida: 127.0.0.1 no es tráfico saliente

El bloqueo aplica al tráfico **hacia internet**. El servidor de correo de la propia máquina
—el Postfix o qmail que monta Plesk— sigue escuchando en `127.0.0.1:25`, y conectarse ahí no
sale a ninguna parte.

**Es exactamente la ruta por la que WordPress envía en este mismo servidor.** Lo confirma el
propio diagnóstico: `mail()` disponible y `sendmail_path = /usr/sbin/sendmail -t -i`.

Dos formas de usarla, y la primera es mejor:

| | SMTP a localhost | Modo `mail()` |
|---|---|---|
| Configuración | host `localhost`, puerto `25`, sin cifrar, sin credenciales | modo «Función mail()» |
| Ruta de entrega | La misma | La misma |
| **Transcripción del envío** | **Sí** — se ve la conversación completa | No |
| Códigos de error del servidor | Sí, con su explicación | Solo «devolvió falso» |

Hablar SMTP con el relé local da lo mismo que `mail()` pero **deja ver qué pasó**. Cuando un
mensaje no llegue, esa diferencia es la que permite averiguar por qué.

**Aplicado:** el diagnóstico de red prueba también `127.0.0.1` en los puertos 25, 587 y 465, lee
el saludo del servidor para confirmar que hay correo detrás, y si responde ofrece un botón
**«Usar el correo local»** que deja la configuración lista.

### El precio, y cómo se paga

> Un mensaje que sale del servidor local diciendo venir de `@narino.gov.co` **no pasa SPF**: el
> registro de ese dominio autoriza solo a Google. Sale, pero llega a no deseado o lo rechazan.

**La solución es cambiar el remitente, no el modo.** Usar una dirección del subdominio que este
Plesk sí gestiona:

1. **Plesk → Correo → Crear dirección**: `no-responder@tic.narino.gov.co`.
2. En la plataforma, ese correo como **Remitente**.
3. Comprobar que `tic.narino.gov.co` tenga SPF con la IP del servidor y DKIM activo — Plesk los
   genera solo al crear el dominio de correo.

La pantalla avisa de esto sola: si el dominio del remitente no coincide con el del servidor,
aparece el aviso con la dirección concreta que habría que usar.

### Si se quiere SMTP a Google de todas formas

Hay que pedirle al proveedor del servidor que **abra la salida a los puertos 587 y 465 hacia
smtp.gmail.com**. Es una petición normal y se suele conceder para un origen concreto. Mientras
tanto, el correo local funciona.

Una tercera vía, si el proveedor no cede: los servicios transaccionales con **API HTTP**
(SendGrid, Brevo, Mailgun, Amazon SES) envían por el puerto 443, que nunca está bloqueado. La
plataforma todavía no los habla; si hiciera falta, se añade como un modo más.

---

## 5.4 El bloqueo es por usuario, no de la máquina

Este es el diagnóstico final, y cambia por completo lo que hay que hacer.

### La prueba que lo demuestra

Desde SSH como **root**, en ese mismo servidor:

```
root@agitated-taussig:~# nc -zv smtp.gmail.com 587
Ncat: Connected to 74.125.197.109:587.

root@agitated-taussig:~# nc -zv smtp.gmail.com 465
Ncat: Connected to 74.125.197.109:465.
```

**Conecta.** La máquina sí puede salir a smtp.gmail.com. Y sin embargo PHP, desde la misma
máquina y al mismo destino, recibe `Connection refused` al instante en los tres puertos.

Solo hay una explicación: **el bloqueo está puesto por usuario**. Root queda exento; el usuario
con el que corre PHP-FPM para el dominio, no.

### Quién lo pone

En un Plesk, casi siempre una de estas dos cosas:

**ConfigServer Firewall (CSF)** — es lo más frecuente. Trae una opción `SMTP_BLOCK` que cierra
la salida a los puertos de correo para todos los usuarios salvo los de una lista:

```bash
grep -E "^SMTP_BLOCK|^SMTP_ALLOWUSER|^SMTP_PORTS" /etc/csf/csf.conf
```

Si sale `SMTP_BLOCK = "1"`, esa es la causa. Se añade el usuario del dominio a la lista y se
recarga:

```bash
# /etc/csf/csf.conf
SMTP_ALLOWUSER = "root,mailnull,mail,postfix,<usuario-del-dominio>"

csf -r
```

**Una regla de iptables con el módulo `owner`**, que hace lo mismo a mano:

```bash
iptables -L OUTPUT -n -v --line-numbers | grep -E "25|465|587"
```

Y el interruptor propio de Plesk, por si acaso:

```bash
plesk bin server_pref --show-outgoing-messages
```

### Qué usuario hay que autorizar

El que aparece en **Configuración → Acceso y correo → Probar la salida de red → Cómo está PHP
en este servidor**, campo **«usuario de PHP»**. También:

```bash
ps -o user,cmd -C php-fpm | head
```

> **Ese bloqueo existe por una razón.** Cierra la salida SMTP para que un script comprometido no
> convierta el servidor en un relé de spam y acabe con la IP en listas negras. Lo correcto es
> **autorizar al usuario del dominio**, no desactivar `SMTP_BLOCK` para todos.

### Mientras tanto

`php herramientas/correo.php probar` hace la prueba **desde la consola**, donde el bloqueo no
aplica. Sirve para separar las dos preguntas que desde el navegador se confunden en una:

| | Lo responde |
|---|---|
| ¿Las credenciales sirven? | `herramientas/correo.php` desde SSH |
| ¿El usuario de PHP puede salir? | La pantalla web |

Si la consola dice que sí y la web sigue diciendo `Connection refused`, **no hay nada que
arreglar en la aplicación**.

Y si levantar el bloqueo se demora, el **correo local** (apartado 5.3) funciona desde ya.

---

## 5.5 Probar desde la consola

```bash
# Qué se ve desde este servidor: DNS, puertos, relé local, PHP
php herramientas/correo.php estado

# Conectar y autenticar con lo que hay en config.php, sin enviar nada
php herramientas/correo.php probar

# Con credenciales distintas, sin guardarlas
php herramientas/correo.php probar \
    --host=smtp.gmail.com --puerto=587 --seguridad=tls \
    --usuario=hosting@narino.gov.co --clave='xxxx xxxx xxxx xxxx'

# Un envío completo
php herramientas/correo.php enviar --a=alguien@narino.gov.co

# Si sale bien, guardar esos datos en config.php
php herramientas/correo.php probar --usuario=… --clave='…' --guardar
```

Imprime la conversación completa con el servidor. **La contraseña nunca aparece**: sale como
`[contraseña en base64]`.

> Pasar la contraseña por la línea de órdenes la deja en el historial del intérprete. Conviene
> `history -c` después, o usar `--guardar` una sola vez y trabajar sin ella.

En Plesk sin SSH: **Sitios web y dominios → Tareas programadas → Ejecutar un script PHP**, ruta
`cumbreAI/herramientas/correo.php` y los argumentos en su campo. Ojo: una tarea programada de
Plesk corre como el usuario del dominio, no como root, así que **reproducirá el bloqueo** —que
para el caso también es información útil.

---

## 5.6 API por HTTPS: la salida que el cortafuegos no toca

Si levantar la regla del cortafuegos se demora —una entidad pública puede tardar semanas en
aprobar un cambio así—, hay una vía que no depende de nadie: **entregar por HTTPS al puerto
443**, el mismo por el que este servidor sirve la web. Ninguna regla de correo lo bloquea.

**Configuración → Acceso y correo → Modo de envío → «API por HTTPS»**.

| Proveedor | Capa gratuita | Clave en |
|---|---|---|
| **Brevo** | 300 correos al día | app.brevo.com → SMTP & API → API Keys |
| **SendGrid** | 100 correos al día | app.sendgrid.com → Settings → API Keys |
| **Resend** | 100 al día, 3.000 al mes | resend.com/api-keys |

Los tres se hablan con un solo POST y una clave en una cabecera. Nada de OAuth, refresh tokens
ni bibliotecas.

> **Por qué no la API de Gmail.** También saldría por 443, pero exige un proyecto en Google
> Cloud, client ID y secret, y un refresh token que caduca y hay que renovar. Mucho más aparato
> para el mismo resultado, y con más piezas que se rompen solas. Si algún día la política lo
> exige, se añade.

### Lo que hay que hacer, y no es opcional

**Verificar el dominio en el proveedor.** Ninguno de los tres deja enviar en nombre de un
dominio ajeno: hay que publicar en el DNS de `narino.gov.co` los registros DKIM y de retorno que
cada uno indique. Sin eso el envío se rechaza con `422`, o pasa y llega a no deseado.

Es el mismo trabajo de DNS del apartado 6, con otro proveedor. La pantalla lo marca como aviso
permanente mientras el modo esté activo.

### Cuándo elegir qué

| | SMTP a Google | API por HTTPS | Correo local |
|---|---|---|---|
| Necesita tocar el cortafuegos | **Sí** | No | No |
| Cuenta con un tercero | No | **Sí** | No |
| DNS que hay que publicar | Ya está (Google) | DKIM del proveedor | SPF/DKIM del subdominio |
| Remitente institucional | `hosting@narino.gov.co` | el que se verifique | `…@tic.narino.gov.co` |
| Cupo | 2.000/día | 100–300/día en gratis | el del servidor |
| **Cuándo** | Es lo mejor: hazlo si se puede | Si el cortafuegos no se toca | Puente inmediato |

El orden recomendado sigue siendo: **arreglar el cortafuegos** (dos líneas, apartado 5.4) →
**correo local** como puente → **API** si lo primero se descarta por política.

---

## 6. Que los mensajes lleguen a la bandeja de entrada

Que el envío funcione no garantiza que el mensaje se lea. Esto se configura **en el DNS del
dominio**, no en la plataforma, y lo hace el área de sistemas:

- **SPF** — el TXT de `narino.gov.co` debe incluir `include:_spf.google.com`.
- **DKIM** — se activa en la consola de Workspace (*Aplicaciones → Google Workspace → Gmail →
  Autenticar correo*) y se publica el TXT que genera.
- **DMARC** — un TXT en `_dmarc.narino.gov.co`. Empezar con `p=none` para poder observar antes
  de endurecer.

También cuenta el **PTR** (DNS inverso) de la IP que envía: si falta o es genérico, la
entregabilidad baja aunque SPF, DKIM y DMARC pasen. Se pide al proveedor del servidor.

Comprobación rápida: mandar la prueba a una dirección de Gmail y abrir **Mostrar original**.
Deben salir `SPF: PASS`, `DKIM: PASS` y `DMARC: PASS`. Para una revisión más completa,
[mail-tester.com](https://www.mail-tester.com) — conviene no salir a producción por debajo de
8/10.

### Cuándo deja de ser opcional

- **Google y Yahoo** exigen SPF, DKIM y DMARC alineados desde el **1 de febrero de 2024** a quien
  manda **más de 5.000 mensajes al día** a direcciones de Gmail.
- **Microsoft** aplica lo equivalente desde el **5 de mayo de 2025** para Outlook, Hotmail y
  Live, rechazando con `550 5.7.515 Access denied, sending domain does not meet the required
  authentication level`.
- Google pide además mantener la tasa de spam reportada **por debajo del 0,3 %**, medible en
  Postmaster Tools.

Una convocatoria masiva del evento puede cruzar ese umbral de 5.000 en un día. Conviene tener el
DNS resuelto antes, no después del primer envío grande.

---

## 7. Límites de envío

Google Workspace: unos **2.000 mensajes al día** por cuenta. Cuenta gratuita: **500**.

Para el uso normal de la plataforma sobra: un código de acceso aquí, un carnet allá. Donde se
queda corto es en una convocatoria masiva —mandar el carnet a mil inscritos de golpe—. En ese
caso conviene repartir el envío en varios días, o usar un servicio pensado para eso.

Cuando el cupo se agota, Google responde `550-5.4.5` y deja de aceptar hasta el día siguiente.

---

## 8. Modos alternativos

| Modo | Cuándo usarlo |
|---|---|
| **Servidor SMTP** | La opción preferible: el mensaje sale autenticado como la cuenta institucional. Necesita que el proveedor deje salir por el 587 o el 465. |
| **API por HTTPS** | Brevo, SendGrid o Resend por el puerto 443, que ninguna regla de correo bloquea. Para cuando el cortafuegos no se puede tocar. Exige verificar el dominio en el proveedor (apartado 5.6). |
| **Función mail()** | Cuando no hay salida SMTP. Entrega por el correo local, que en este servidor funciona. **Exige poner como remitente una dirección de un dominio que este Plesk gestione** (apartado 5.2); si no, el mensaje sale pero acaba en no deseado. |
| **Solo registrar** | Pruebas y desarrollo. No envía nada: escribe los mensajes en `almacen/registro/`. Útil para probar el resto de la plataforma sin gastar cupo ni molestar a nadie. |

En modo «solo registrar», la pantalla de correo lo marca como **bloqueante**: es correcto en
desarrollo y es un fallo en producción.

---

## 9. Desde la consola

El modo y el remitente se pueden fijar al instalar:

```bash
php herramientas/instalar.php … --url=https://tic.narino.gov.co/cumbreAI
```

El instalador deja `modo_correo` en `php` si `mail()` existe, y en `registro` si no. La
configuración SMTP se completa después desde el panel, que es donde se puede probar.

---

## 10. Pruebas

```bash
php pruebas/smtp.php     # el cliente SMTP contra un servidor real, TLS incluido
php pruebas/correo.php   # formato MIME, RFC 2047 e inyección de cabeceras
```

`pruebas/smtp.php` levanta un servidor SMTP de mentira en un puerto local —con certificado
autofirmado para los escenarios con TLS— y reproduce las respuestas que dan de verdad Google y
Microsoft: `535`, `534`, `550`, respuestas de varias líneas, STARTTLS, SSL directo, un servidor
que no contesta y un puerto cerrado. De cada una comprueba dos cosas: que el cliente la entienda,
y **que la explicación que sale en pantalla sea la correcta**. Detectar el 535 no sirve de nada
si al administrador se le enseña «no se pudo enviar».

También comprueba que la contraseña no aparezca en la transcripción.

---

## 11. Historial de esta configuración

| Fecha | Qué se hizo |
|---|---|
| 2026-08-14 | Se detecta que no sale ningún correo. La plataforma solo tenía `mail()`, que no sirve con el buzón en Google Workspace. |
| 2026-08-14 | Se escribe un cliente SMTP propio (`app/Nucleo/Smtp.php`), sin dependencias externas. |
| 2026-08-14 | Nueva pantalla **Administración → Correo**: configuración, revisión, prueba real y tabla de códigos. |
| 2026-08-14 | Se crea la contraseña de aplicación `eventos` para `hosting@narino.gov.co`. **Queda expuesta al compartirla en un canal de chat; se revoca y se genera otra.** |
| 2026-08-14 | Pendiente del área de sistemas: verificar SPF, DKIM y DMARC del dominio. |
| 2026-08-16 | La prueba falla con `Network is unreachable` en el 587 **y** en el 465, al instante. No era el proveedor: `smtp.gmail.com` publica A y AAAA, y el servidor resolvía la IPv6 sin tener ruta de salida por ahí. |
| 2026-08-16 | **Aplicado:** el cliente resuelve el nombre y prueba **IPv4 primero**, IPv6 de reserva, anotando cada intento. Casilla «Usar solo IPv4» para no intentarlo siquiera. |
| 2026-08-16 | **Aplicado:** botón «Probar la salida de red» — DNS, y puertos 587/465/25 por IPv4 y por IPv6, con el motivo exacto de cada fallo y una conclusión accionable. |
| 2026-08-16 | **Aplicado:** `Network is unreachable` se explica aparte de «puerto cerrado». Eran indistinguibles en pantalla y llevaban a pedir aperturas de puerto ya hechas. |
| 2026-08-16 | **Aplicado:** el modo `mail()` deja de ser «lo que no hay que usar». Se confirmó que WordPress envía desde este mismo servidor, así que la entrega local funciona; lo que decide si el mensaje llega es el **dominio del remitente**. La pantalla avisa cuando no coincide con el del servidor y propone la dirección del subdominio. |
| 2026-08-16 | Corregido: el diagnóstico de red daba todos los puertos por cerrados. Se cerraba el socket y **después** se comprobaba `is_resource()`, que ya era falso. |
| 2026-08-16 | Con IPv4 ya probándose primero, el diagnóstico reveló lo de verdad: **`Connection refused` inmediato en 587, 465 y 25**. No era IPv6 ni un puerto filtrado: la salida SMTP del servidor está **bloqueada a propósito** con una regla de rechazo. |
| 2026-08-16 | **Aplicado:** el diagnóstico prueba también el relé de la propia máquina (`127.0.0.1` en 25/587/465) y lee su saludo. Conectarse ahí no es tráfico saliente, así que el bloqueo no le aplica —es la ruta por la que WordPress envía en este servidor—. |
| 2026-08-16 | **Aplicado:** botón **«Usar el correo local»**, que deja host `localhost`, puerto 25, sin cifrar y sin credenciales. Da la misma entrega que `mail()` pero conservando la transcripción del envío, que es lo que permite depurar. |
| 2026-08-16 | **Aplicado:** «rechazado» se explica ahora como bloqueo deliberado, distinto de «filtrado» (timeout, del proveedor) y de «sin ruta» (IPv6). Eran tres problemas con tres soluciones distintas y el mismo mensaje en pantalla. |
| 2026-08-16 | **Aplicado**, de la guía de WP Mail SMTP: códigos `550-5.7.30` y `550-5.7.515` de Microsoft 365, el PTR como factor de entregabilidad, los umbrales de Google/Yahoo (feb-2024) y Microsoft (may-2025) a partir de 5.000 mensajes diarios, y la tasa de spam por debajo del 0,3 %. |
| 2026-08-16 | **Pendiente:** pedirle al proveedor del servidor que abra la salida a los puertos 587 y 465 hacia smtp.gmail.com. Mientras tanto, correo local. |
| 2026-08-17 | **Descartado lo anterior.** Desde SSH como root, `nc -zv smtp.gmail.com 587` **conecta**. La máquina sí puede salir: el bloqueo está puesto **por usuario**, y al de PHP-FPM le aplica. No hay nada que pedirle al proveedor. |
| 2026-08-17 | **Aplicado:** `herramientas/correo.php` —estado, probar y enviar desde la consola, con la transcripción completa—. Separa «¿sirven las credenciales?» de «¿puede salir el usuario de PHP?», que desde el navegador eran la misma pregunta. |
| 2026-08-17 | **Aplicado:** el diagnóstico web informa del **usuario con el que corre PHP**, que es el dato que hace falta para levantar el bloqueo, y la explicación de «rechazado» remite a `nc` y a `SMTP_ALLOWUSER` de CSF. |
| 2026-08-17 | **Verificado:** «Usar solo IPv4» funciona de punta a punta —11 comprobaciones—: con la casilla no se ofrece ninguna dirección IPv6, sin ella IPv6 queda detrás de IPv4, y el valor viaja de config.php al cliente. |
| 2026-08-17 | **Pendiente del área de sistemas:** añadir el usuario del dominio a `SMTP_ALLOWUSER` en `/etc/csf/csf.conf` y `csf -r`. Es lo único que falta para que el SMTP a Google funcione desde la web. |
| 2026-08-17 | **Aplicado:** el diagnóstico web genera las **órdenes de cortafuegos con el UID real** de este proceso, en el orden correcto —buscar la regla, guardar copia, insertar el ACCEPT por encima del REJECT, persistir— y con la alternativa de nftables y de CSF. Antes había que deducirlas. |
| 2026-08-17 | **Aplicado:** modo **API por HTTPS** (`app/Nucleo/CorreoApi.php`) con Brevo, SendGrid y Resend. Sale por el 443, así que no le afecta la regla de SMTP. La clave se tacha en la transcripción y no vuelve al navegador. 38 comprobaciones contra un servidor local que responde como los tres proveedores. |
| 2026-08-17 | **Revisada** la guía de PHPMailer que aportó la Secretaría. Confirma el diagnóstico —owner-match REJECT por UID, errno 111 ≠ DROP, root exento, y que la regla estándar de Plesk excluye `! -d 127.0.0.1`, que es lo que hace funcionar el correo local—. **No se adopta PHPMailer:** el cliente propio ya hace lo mismo (STARTTLS, SSL, AUTH LOGIN/PLAIN, punto doblado, MIME multiparte) y añadirlo no arreglaría nada, porque PHPMailer por SMTP choca con el mismo cortafuegos. Sí se adopta su recomendación de fondo: la API por 443. |

> Cuando cambie algo —la cuenta, el proveedor, los límites— **actualizar esta tabla**. Una
> configuración de correo sin historial es la que nadie se atreve a tocar dos años después.
