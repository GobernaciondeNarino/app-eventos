<?php
/**
 * Genera docs/ESQUEMA-DATOS.md a partir de app/Esquema.php.
 *
 * El esquema se documenta desde su definición y no a mano, para que el
 * documento no se desactualice en silencio la primera vez que alguien agregue
 * una columna.
 *
 * Uso:  php herramientas/generar-doc-esquema.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'herramienta');

require RAIZ . '/app/Esquema.php';

use App\Esquema;

$prefijo = 'evt_';
$md = [];
$p = static function (string ...$lineas) use (&$md): void {
    foreach ($lineas as $l) {
        $md[] = $l;
    }
};

$p(
    '# Esquema de datos',
    '',
    'Plataforma de Eventos TIC · versión del esquema **' . Esquema::VERSION . '**',
    '',
    '> Documento generado con `php herramientas/generar-doc-esquema.php` a partir de',
    '> `app/Esquema.php`, la misma definición que el instalador usa para crear y actualizar',
    '> las tablas. No lo edites a mano: edita el esquema y vuelve a generarlo.',
    '',
    'Las tablas llevan el prefijo elegido durante la instalación (`evt_` por defecto), para',
    'poder compartir la base con otras aplicaciones del alojamiento.',
    '',
    '## Resumen',
    '',
    '| Tabla | Columnas | Para qué existe |',
    '|---|---:|---|'
);

foreach (Esquema::tablas() as $nombre => $definicion) {
    $p('| `' . $prefijo . $nombre . '` | ' . count($definicion['columnas']) . ' | ' . $definicion['nota'] . ' |');
}

$p(
    '',
    '## Cómo se relacionan',
    '',
    '```',
    'evento ─┬─ evento_tema        identidad visual: paleta, tipografía y logo',
    '        ├─ evento_dia ────┬── asistencia        un ingreso por persona y jornada',
    '        │                 └── charla            la agenda publicada',
    '        └─ persona ───┬─── persona_caracterizacion   datos sensibles, aparte',
    '                      ├─── credencial                el carnet y su token',
    '                      ├─── contacto                  intercambios por QR',
    '                      ├─── codigo_acceso             códigos de un solo uso',
    '                      └─── propuesta ─── charla      al aprobarse',
    '',
    'usuario ─── sesion            equipo organizador y sus sesiones',
    'recuperacion_clave            enlaces para poner una contraseña nueva, de personas y del equipo',
    'api_token                     quién puede consultar la API; solo el hash de cada token',
    'intento                       contador para el límite de fuerza bruta',
    'bitacora                      auditoría; solo inserciones',
    'migracion                     versión del esquema aplicada',
    '```',
    '',
    'Cuatro decisiones explican la forma del modelo:',
    '',
    '1. **Todo cuelga de `evento`.** La plataforma es multievento desde el principio, así que',
    '   la identidad, las jornadas y las personas pertenecen a un evento y no al sistema.',
    '2. **La caracterización está separada de `persona`.** Son datos sensibles según el',
    '   artículo 5 de la Ley 1581 de 2012; teniéndolos aparte, las consultas del día a día no',
    '   los tocan y su lectura se puede auditar por separado.',
    '3. **El código QR cuelga de `evento_dia`, no de `evento`.** Es lo que permite que cambie',
    '   cada jornada y que el del día anterior deje de servir.',
    '4. **`persona` y `usuario` son tablas distintas.** Un asistente y un operador tienen',
    '   ciclos de vida, riesgos y formas de identificarse muy diferentes; mezclarlos obliga a',
    '   poner banderas por todas partes y termina en que alguien se autentica por el camino',
    '   equivocado.',
    '',
    '## Detalle de cada tabla',
    ''
);

foreach (Esquema::tablas() as $nombre => $definicion) {
    $p('### `' . $prefijo . $nombre . '`', '', $definicion['nota'], '', '| Columna | Tipo |', '|---|---|');
    foreach ($definicion['columnas'] as $columna => $tipo) {
        $p('| `' . $columna . '` | `' . $tipo . '` |');
    }
    $p('', 'Llaves e índices:', '');
    foreach ($definicion['llaves'] as $llave) {
        $p('- `' . preg_replace('/\{([a-z_]+)\}/', '`' . $prefijo . '$1`', $llave) . '`');
    }
    $p('');
}

$p(
    '## Modos del instalador',
    '',
    'El asistente compara lo que hay en la base con esta definición y ofrece tres caminos:',
    '',
    '| Modo | Qué hace | Cuándo usarlo |',
    '|---|---|---|',
    '| **Limpio** | Elimina las tablas con este prefijo y las crea desde cero | Instalación nueva, o entorno de pruebas que se quiere reiniciar |',
    '| **Actualizar** | Conserva los datos y solo agrega las tablas y columnas que falten | Al subir de versión una instalación en uso |',
    '| **Anexar** | Crea únicamente las tablas que falten; no toca ninguna existente | Base compartida con otra aplicación, o reparación parcial |',
    '',
    'El modo limpio es el único destructivo y la interfaz lo advierte en rojo con el conteo',
    'de tablas que se perderían.',
    '',
    'Las columnas que faltan se agregan consultando antes `information_schema`, y no con',
    '`ADD COLUMN IF NOT EXISTS`: esa sintaxis es de MariaDB y en MySQL 8 falla. La plataforma',
    'tiene que instalarse igual en los dos.',
    '',
    '## Versionado',
    '',
    'La tabla `' . $prefijo . 'migracion` guarda qué versión del esquema está aplicada. Sin ese registro el',
    'asistente no podría distinguir una instalación nueva de una que solo necesita',
    'actualizarse, y ofrecería borrar datos que debía conservar.',
    ''
);

$destino = RAIZ . '/docs/ESQUEMA-DATOS.md';
@mkdir(dirname($destino), 0755, true);
file_put_contents($destino, implode("\n", $md));

printf(
    "docs/ESQUEMA-DATOS.md generado · %d tablas · %d líneas\n",
    count(Esquema::tablas()),
    count($md)
);
