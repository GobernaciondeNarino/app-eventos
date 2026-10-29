# API de consulta

Plataforma de Eventos TIC · versión 1 de la API, desde la 3.14; el enlace de la fotografía, desde
la 3.16

Para que otro sistema —el sitio web de la Secretaría, un tablero, otra dependencia— consulte en
JSON quiénes se registraron al evento: expositores, participantes y los demás perfiles, con
filtros en la dirección.

**Solo lee.** Por la API no se crea, se cambia ni se borra nada.

---

## 1. En un minuto

1. En el panel, **Configuración → API**, escribe para qué sistema es y pulsa **Crear token**.
   Cópialo: es la única vez que se ve.
2. Consulta:

   ```bash
   curl "https://tic.narino.gov.co/cumbreAI/api/v1/expositores?estado=aprobada&token=TU_TOKEN"
   ```

   O, mejor, con el token en una cabecera en vez de en la dirección (ver 2.3):

   ```bash
   curl -H "Authorization: Bearer TU_TOKEN" \
        "https://tic.narino.gov.co/cumbreAI/api/v1/expositores?estado=aprobada"
   ```

3. `https://…/api/v1?token=TU_TOKEN` responde con todo lo que se puede consultar y los filtros
   de cada consulta.

---

## 2. El token

### 2.1 Uno por sistema

Cada sistema que consulta tiene su propio token, con un nombre que dice cuál es («Sitio web de la
Secretaría TIC», «Tablero de asistencia»). Así se sabe quién usa la API, cuándo fue la última vez
y cuántas consultas lleva, y se le corta a uno sin dejar sin servicio a los demás.

Solo un **administrador** crea y revoca tokens. Queda en la bitácora del panel quién lo hizo y
para qué sistema.

### 2.2 Qué puede ver: el alcance

| Alcance | Qué entrega |
|---|---|
| **Sin datos de contacto** (`basico`) | Nombre, perfil, entidad, municipio, departamento, número del carnet, días que entró, si aceptó el directorio, fecha de registro y la propuesta de quien expone |
| **Con correo y teléfono** (`contacto`) | Lo mismo, y además el correo, el teléfono y si autorizó compartirlo |

Elige el menor que le sirva al sistema. Un sitio web que publica la lista de expositores no
necesita el correo de nadie; un tablero interno de la organización que llama a los asistentes,
sí.

Con alcance básico, el filtro `q` **no busca en el correo**: si buscara, serviría para averiguar
si un correo está registrado, que es justo lo que ese alcance no deja ver.

### 2.3 Dónde va

En cualquiera de estos tres sitios; si viene en varios, vale el primero:

1. En la dirección: `?token=TU_TOKEN`.
2. En la cabecera `Authorization: Bearer TU_TOKEN`.
3. En la cabecera `X-Api-Token: TU_TOKEN`.

**La cabecera es mejor.** La dirección completa —con el token— queda escrita en los registros de
acceso del servidor web, en el historial del navegador y en los de cualquier proxy por el que
pase. La cabecera no.

Si con Apache la cabecera `Authorization` no llega a PHP, el `.htaccess` de la plataforma trae
desde la 3.14 la línea que lo resuelve. Mientras tanto, `X-Api-Token` funciona siempre.

### 2.4 Cuidado con él

Quien tenga el token puede consultar los registros. Por eso:

- **No lo pongas en una página web pública ni en el JavaScript de un navegador.** Cualquiera que
  abra la página lo leería. La API se consulta desde un servidor —el del sitio web, un
  programa, una hoja de cálculo con su propio guion— y lo que se muestra al público se arma
  allí.
- La API no envía cabeceras CORS a propósito: un navegador no deja que una página de otro sitio
  lea sus respuestas.
- Entrégalo por un canal seguro a quien administra ese sistema, no por un grupo de chat.
- Si se filtró, **revócalo** en Configuración → API y crea otro. Deja de servir en el acto.
- Los enlaces de la fotografía (2.6) sí pueden ir en una página pública: no llevan el token.

En la base de datos queda solo la huella SHA-256 de cada token, no el token: quien lea la base
no puede usarlo. Por eso no se puede volver a mostrar; si se pierde, se crea otro.

### 2.5 Topes

- **600 consultas cada 10 minutos por token.** Pasado eso, `429` con la cabecera `Retry-After`.
  Los demás tokens no se ven afectados.
- **20 intentos con un token que no sirve, en 5 minutos, desde la misma dirección IP**, bloquean
  esos intentos 15 minutos (`429`). Un sistema con un token vigente, desde esa misma dirección,
  sigue funcionando.
- Abrir el enlace de una fotografía (2.6) **no cuenta** como consulta: una página con cincuenta
  expositores pide cincuenta imágenes cada vez que alguien la abre.

### 2.6 La fotografía

Aparte del alcance, a un token se le puede dar **el enlace de la fotografía** de cada persona:
al crearlo, con la casilla **Incluir el enlace de la fotografía**, o después, con el botón
**Incluir la fotografía** de la lista de tokens, sin cambiar el token. El botón **Quitar la
fotografía** lo deshace. Los tokens creados antes de la 3.16 no lo tienen.

Con el permiso, cada persona trae el campo `foto`: el enlace a la suya, o `null` si no tiene.
Sin el permiso, el campo no aparece. `/api/v1` dice en `"fotografia": true` o `false` si el token
lo tiene.

```json
"foto": "https://tic.narino.gov.co/cumbreAI/api/v1/fotos/128/3/9f2c41d07b5e8a63c1f0e27d4b98a5e1"
```

El enlace:

- **Se abre sin el token.** Va firmado para ese token —la firma es la llave—, así que se pone tal
  cual en una página pública, `<img src="…">`, y el token sigue sin salir del servidor (2.4).
- Responde la imagen —JPEG, cuadrada— con `Cache-Control: private, max-age=86400`: el navegador
  de quien visita la guarda un día; los proxies y las CDN, no.
- Pide a los buscadores que no la indexen (`X-Robots-Tag: noindex, noimageindex`).
- **Deja de servir** —responde `404`— si se revoca el token, si se le quita el permiso o si la
  persona cambia de foto. Si se le vuelve a dar el permiso, el token recibe **enlaces nuevos**:
  los de antes no reviven. Por eso no conviene guardarlo para siempre: cada vez que el sistema
  actualice sus datos desde la API, toma el enlace que venga.
- Con la firma, la persona o el token cambiados responde `404`, igual que una persona sin foto.
  No se arma a mano: la firma solo la calcula la plataforma.

En la página del sitio web, por ejemplo con PHP —los datos ya traídos de la API desde el
servidor, como en 5—:

```php
<?php foreach ($expositores as $p): if (!$p['en_directorio']) continue; ?>
  <figure>
    <?php if ($p['foto'] !== null): ?>
      <img src="<?= htmlspecialchars($p['foto']) ?>" alt="" width="160" height="160" loading="lazy">
    <?php endif; ?>
    <figcaption><?= htmlspecialchars($p['nombre']) ?></figcaption>
  </figure>
<?php endforeach; ?>
```

**La foto es un dato personal.** Publica solo la de quien lo autorizó: `en_directorio` dice quién
aceptó aparecer en el directorio del evento. Quien quiera guardar copia de las fotos en su propio
sistema las baja con el mismo enlace; cada una llega como `foto-ID.jpg`.

---

## 3. Las consultas

Todas son `GET` (también responden a `HEAD`). La dirección base es la de la plataforma más
`/api/v1`: en producción, `https://tic.narino.gov.co/cumbreAI/api/v1`.

Los filtros son todos opcionales. **Uno que no se entiende responde `400` diciendo qué admite**:
un nombre mal escrito (`perfiles=` por `perfil=`), un perfil o un estado que no existe, un día que
el evento no tiene. Nunca se ignora en silencio para devolver algo distinto de lo que se pidió.

Por omisión se consulta el **evento activo**. Con `evento=N` se consulta otro; los números están
en `/api/v1`.

### 3.1 `GET /api/v1` — qué se puede consultar

El evento, los días con su fecha, la lista de eventos —con su fecha de inicio, su estado y
cuál es el activo—, el alcance del token y, para cada consulta, su dirección y sus filtros con
lo que hace cada uno.

### 3.2 `GET /api/v1/perfiles` — cuántos hay de cada perfil

```json
{
    "evento": { "id": 2, "nombre": "Cumbre Tecnológica CIOS Nariño", "dias": [ … ] },
    "total": 396,
    "datos": [
        { "clave": "participante", "nombre": "Participante", "total": 351 },
        { "clave": "expositor", "nombre": "Expositor", "total": 38 },
        { "clave": "prensa", "nombre": "Prensa", "total": 7 }
    ]
}
```

La `clave` es la que se usa en el filtro `perfil` de `/personas`. Están los perfiles del
formulario y también los que tenga alguien aunque ya no estén en él, así que la suma da el total.

Filtros: solo `evento`.

### 3.3 `GET /api/v1/personas` — quienes se registraron

Todos los perfiles: participantes, expositores, prensa, staff…

| Filtro | Qué hace | Ejemplo |
|---|---|---|
| `perfil` | Clave del perfil, o varias separadas por comas | `perfil=participante` · `perfil=prensa,comunicaciones` |
| `dia` | Quienes registraron su ingreso ese día del evento | `dia=1` |
| `completo` | `1`: solo los registros completos. `0`: solo los que quedaron a medias —crearon su acceso y no terminaron el formulario— | `completo=1` |
| `q` | Texto en el nombre, la entidad o el municipio; con alcance de contacto, también en el correo | `q=alcaldía` |
| `entidad` | La entidad contiene este texto | `entidad=gobernación` |
| `municipio` | El municipio contiene este texto | `municipio=tumaco` |
| `departamento` | El departamento contiene este texto | `departamento=nariño` |
| `registrado_desde` | Registrados ese día o después (`AAAA-MM-DD`) | `registrado_desde=2026-10-01` |
| `registrado_hasta` | Registrados ese día o antes (`AAAA-MM-DD`), el día entero incluido | `registrado_hasta=2026-10-15` |
| `orden` | `nombre`, `entidad`, `municipio`, `perfil`, `ingresos` (cuántos días entró) o `registro` (fecha). Por omisión, `nombre` | `orden=registro` |
| `dir` | `asc` o `desc`. Por omisión, `asc` | `dir=desc` |
| `pagina`, `por` | Ver 5 | `pagina=2&por=100` |
| `evento` | Otro evento que no sea el activo | `evento=1` |

Los textos se buscan sin distinguir mayúsculas. Al ordenar por nombre, entidad o municipio, los
que lo tienen vacío van al final en los dos sentidos.

### 3.4 `GET /api/v1/expositores` — quienes exponen

Los mismos que muestra **Expositores** en el panel: quien tiene el perfil Expositor, quien envió
una propuesta y quien se registró por el enlace privado de expositores, aunque todavía no la haya
enviado. Cada uno con su propuesta y, si ya está en la agenda, su día, hora y salón.

| Filtro | Qué hace | Ejemplo |
|---|---|---|
| `estado` | De la propuesta: `pendiente`, `observada`, `aprobada`, `rechazada` o `sin-propuesta` | `estado=aprobada` |
| `categoria` | La categoría de la propuesta, escrita igual que en ella | `categoria=Ponencia` |
| `dia` | Las exposiciones agendadas ese día del evento | `dia=2` |
| `q` | Como en `/personas`, y además en el título de la propuesta | `q=inteligencia` |
| `entidad`, `municipio` | Como en `/personas` | |
| `orden` | `nombre`, `entidad`, `registro`, `estado` o `jornada` —por día y hora de la agenda; lo que no está en ella va al final—. Por omisión, `nombre` | `orden=jornada` |
| `dir`, `pagina`, `por`, `evento` | Como en `/personas` | |

La agenda que entrega es la misma que la pública: solo lo publicado.

### 3.5 `GET /api/v1/fotos/{persona}/{token}/{firma}` — la fotografía

El enlace que llega en `foto` con el permiso de la fotografía (2.6). No lleva el token ni
filtros, y responde la imagen, no JSON; cuando no sirve, el error sí llega en JSON (6).

---

## 4. La respuesta

Todas las respuestas son JSON en UTF-8, también los errores, con `Cache-Control: no-store`: son
datos personales y no deben quedar en ninguna caché. Las fechas van en ISO 8601 con la hora de
Colombia (`2026-10-21T14:49:29-05:00`).

### 4.1 Una lista

`/personas` y `/expositores` responden igual:

```json
{
    "evento": {
        "id": 2,
        "nombre": "Cumbre Tecnológica CIOS Nariño",
        "dias": [
            { "numero": 1, "fecha": "2026-10-22" },
            { "numero": 2, "fecha": "2026-10-23" }
        ]
    },
    "filtros": { "estado": "aprobada" },
    "orden": { "campo": "jornada", "dir": "asc" },
    "paginacion": {
        "pagina": 1,
        "por_pagina": 50,
        "total": 38,
        "paginas": 1,
        "siguiente": null,
        "anterior": null
    },
    "datos": [ … ]
}
```

`filtros` repite los que se aplicaron, ya interpretados: `perfil` como lista, `dia` como número,
`completo` como verdadero o falso.

### 4.2 Una persona

```json
{
    "id": 128,
    "nombre": "Lucía Villota Erazo",
    "perfil": { "clave": "expositor", "nombre": "Expositor" },
    "entidad": "Universidad de Nariño",
    "municipio": "Pasto",
    "departamento": "Nariño",
    "registro_completo": true,
    "carnet": "STIC-2026-000128",
    "ingresos": [1, 2],
    "en_directorio": true,
    "registrado_en": "2026-10-03T09:12:44-05:00",
    "propuesta": {
        "id": 31,
        "titulo": "Inteligencia artificial en la gestión pública",
        "categoria": "Ponencia",
        "resumen": "…",
        "duracion_min": 30,
        "estado": "aprobada",
        "dia_preferido": 2,
        "agenda": { "dia": 2, "fecha": "2026-10-23", "hora": "10:30", "salon": "Auditorio principal" }
    }
}
```

| Campo | Qué es |
|---|---|
| `id` | Número interno de la persona. No cambia |
| `nombre` | Como lo escribió. Vacío si quedó a medias |
| `perfil` | Su perfil de asistencia: la clave y el nombre que se muestra |
| `entidad`, `municipio`, `departamento` | Pueden ir vacíos si el formulario no los pide |
| `registro_completo` | Si terminó el formulario: tiene nombre y, si el evento la exige, identificación |
| `carnet` | El número impreso en el carnet, o `null`. **No** es el código del QR, que nunca sale por aquí |
| `ingresos` | Los días del evento en que registró su ingreso |
| `en_directorio` | Si aceptó aparecer en el directorio de asistentes. Quien publique nombres debería respetarlo |
| `registrado_en` | Cuándo se registró |
| `correo` | Solo con alcance de contacto |
| `telefono` | Solo con alcance de contacto; `null` si no lo dio |
| `comparte_telefono` | Solo con alcance de contacto: si autorizó compartir el teléfono con otros asistentes |
| `foto` | Solo si el token tiene el permiso de la fotografía (2.6): el enlace a la suya, o `null` si no tiene |
| `propuesta` | La más reciente, o `null` si no ha enviado ninguna |
| `propuesta.estado` | `pendiente`, `observada` (devuelta para corregir), `aprobada` o `rechazada` |
| `propuesta.dia_preferido` | El día que pidió, o `null` |
| `propuesta.agenda` | Día, fecha, hora y salón si está en la agenda publicada, o `null` |

En la versión 1 pueden aparecer campos nuevos: el programa que consulta debe ignorar los que no
conozca. Un cambio que quite o renombre un campo iría a `/api/v2`.

**Los textos llegan tal como los escribió cada persona.** Si se muestran en una página web,
escápalos: un nombre puede traer `<script>`.

---

## 5. Páginas

Las listas van por páginas de 50. Con `por` se piden de 1 a 200; con `pagina`, cuál.

Una página pasada la última **llega vacía** (`"datos": []`), con `siguiente: null` y `anterior`
apuntando a la última que tiene datos. Para traer todo, se piden páginas hasta que `siguiente`
sea `null`, o hasta que una llegue vacía: las dos formas terminan.

```php
<?php
// Todos los expositores aprobados, página por página.
$base = 'https://tic.narino.gov.co/cumbreAI/api/v1/expositores?estado=aprobada&por=200';
$token = getenv('EVENTOS_TOKEN');   // fuera del código: en una variable de entorno
$todos = [];
for ($pagina = 1; $pagina !== null; ) {
    $contexto = stream_context_create(['http' => [
        'header' => "Authorization: Bearer $token\r\n",
        'ignore_errors' => true,
    ]]);
    $r = json_decode((string) file_get_contents("$base&pagina=$pagina", false, $contexto), true);
    if (isset($r['error'])) {
        throw new RuntimeException($r['error']['titulo'] . ': ' . $r['error']['mensaje']);
    }
    array_push($todos, ...$r['datos']);
    $pagina = $r['paginacion']['siguiente'];
}
```

```python
import os, requests

url = "https://tic.narino.gov.co/cumbreAI/api/v1/personas"
cabeceras = {"Authorization": "Bearer " + os.environ["EVENTOS_TOKEN"]}
personas, pagina = [], 1
while pagina:
    r = requests.get(url, headers=cabeceras, params={"perfil": "participante", "por": 200, "pagina": pagina})
    r.raise_for_status()
    datos = r.json()
    personas += datos["datos"]
    pagina = datos["paginacion"]["siguiente"]
```

En una hoja de cálculo de Google, con **Extensiones → Apps Script** (el token, en las
propiedades del guion, no escrito en el código):

```javascript
function traerExpositores() {
  var token = PropertiesService.getScriptProperties().getProperty('EVENTOS_TOKEN');
  var url = 'https://tic.narino.gov.co/cumbreAI/api/v1/expositores?estado=aprobada&orden=jornada&por=200';
  var r = JSON.parse(UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + token } }).getContentText());
  var filas = r.datos.map(function (p) {
    var a = p.propuesta && p.propuesta.agenda;
    return [p.nombre, p.entidad, p.propuesta ? p.propuesta.titulo : '', a ? a.fecha : '', a ? a.hora : '', a ? a.salon : ''];
  });
  var hoja = SpreadsheetApp.getActiveSheet();
  hoja.clearContents();
  hoja.appendRow(['Nombre', 'Entidad', 'Título', 'Fecha', 'Hora', 'Salón']);
  if (filas.length) hoja.getRange(2, 1, filas.length, 6).setValues(filas);
}
```

---

## 6. Errores

Siempre con la misma forma, y con un mensaje en español que dice qué hacer:

```json
{
    "error": {
        "codigo": 400,
        "titulo": "Filtro desconocido",
        "mensaje": "«perfiles» no es un filtro de esta consulta. Admite: perfil, dia, completo, …"
    }
}
```

| Código | Cuándo |
|---|---|
| `400` | Un filtro que no existe o con un valor que no se entiende. El mensaje dice los valores válidos |
| `401` | Falta el token, no existe o fue revocado |
| `404` | La consulta no existe, el evento pedido con `evento=`, o un enlace de fotografía que no sirve (2.6) |
| `405` | Se usó otro método que no es `GET` |
| `429` | Pasó el tope de consultas del token, o demasiados intentos con tokens que no sirven. Esperar lo que diga `Retry-After` (segundos) |
| `500` | Un fallo inesperado. Queda anotado en el registro de errores de la plataforma |
| `503` | La base de datos no responde, o todavía no se ha puesto al día con esta versión |

---

## 7. Lo que nunca sale por la API

Con ningún alcance:

- El **número de identificación**. En la base va cifrado, y la API no lo descifra.
- La **caracterización**: etnia, género, discapacidad y demás. Son datos sensibles (Ley 1581 de
  2012, art. 5).
- Lo que el **comité** le escribe al expositor al devolver una propuesta, y los **requerimientos**
  técnicos de la exposición.
- Ningún **token** ni **código**: ni el del QR del carnet, ni los de acceso, ni el de los días.
- Ninguna **contraseña**, ni su huella.

La **fotografía** sale solo como enlace, y solo a un token con ese permiso (2.6).

Lo que sí sale son datos personales. El sistema que los recibe pasa a tratarlos en nombre de la
Gobernación: solo para lo que se pidió el token, y con el mismo cuidado que aquí.
