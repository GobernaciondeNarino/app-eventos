<?php
/**
 * La configuración del formulario de registro, sin base de datos.
 *
 * Lo que escribe un administrador en Configuración → Registro pasa por
 * Formulario::leerListas() antes de guardarse. Aquí se prueba esa traducción:
 * que lo bien escrito quede como se quiso, que lo mal escrito se rechace con
 * un motivo que se entienda, y que ningún envío manipulado deje el registro
 * público sin opciones o con valores que no existen.
 *
 * Uso:  php pruebas/formulario.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }
    }
});
require RAIZ . '/app/ayudas.php';

use App\Modelos\Formulario;
use App\Modelos\Persona;

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  ✗ $nombre" . ($extra !== '' ? "  → $extra" : '') . "\n";
    }
}

echo "Configuración del formulario de registro\n" . str_repeat('=', 58) . "\n";

$fabrica = [];
foreach (array_keys(Formulario::LISTAS) as $clave) {
    $fabrica[$clave] = Formulario::listaPorDefecto($clave);
}

/** Lo que mandaría la pantalla sin tocar nada. */
$sinCambios = static function () use ($fabrica): array {
    $entrada = [];
    foreach (Formulario::LISTAS as $clave => $def) {
        $entrada[$clave] = match ($def['clase']) {
            'codigos', 'perfiles' => array_map(static fn(array $o): array => $o + [], $fabrica[$clave]),
            'territorio' => Formulario::territorioComoTexto($fabrica[$clave]),
            'minutos'    => implode(', ', $fabrica[$clave]),
            default      => implode("\n", $fabrica[$clave]),
        };
    }
    return $entrada;
};

echo "\nSin tocar nada\n";
[$listas, $errores] = Formulario::leerListas($sinCambios(), $fabrica);
comprobar('guardar sin cambios no da errores', $errores === [], json_encode($errores, JSON_UNESCAPED_UNICODE));
comprobar('y deja las listas como estaban', $listas == $fabrica);

echo "\nTipos de documento\n";
$entrada = $sinCambios();
$entrada['tipo_documento'][] = ['codigo' => 'ppt', 'etiqueta' => 'Permiso por Protección Temporal', 'activo' => '1'];
$entrada['tipo_documento'][] = ['codigo' => '', 'etiqueta' => '', 'activo' => '1'];   // la fila vacía de agregar
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
$codigos = array_column($listas['tipo_documento'], 'valor');
comprobar('se agrega un tipo nuevo con su sigla en mayúsculas', in_array('PPT', $codigos, true), implode(',', $codigos));
comprobar('la fila vacía de agregar no crea nada', count($codigos) === count(\App\Datos::TIPOS_DOCUMENTO) + 1);

$entrada = $sinCambios();
$entrada['tipo_documento'][] = ['codigo' => 'P P T', 'etiqueta' => 'Con espacios', 'activo' => '1'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una sigla con espacios se rechaza y se dice por qué',
    str_contains($errores['tipo_documento'] ?? '', 'sigla'), $errores['tipo_documento'] ?? '');
comprobar('y la lista se queda como estaba', $listas['tipo_documento'] == $fabrica['tipo_documento']);

$entrada = $sinCambios();
foreach ($entrada['tipo_documento'] as $i => $o) {
    unset($entrada['tipo_documento'][$i]['activo']);
}
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('apagarlas todas no se puede', isset($errores['tipo_documento']));

$entrada = $sinCambios();
unset($entrada['tipo_documento'][0]['activo']);   // se apaga la cédula
$entrada['tipo_documento'][1]['etiqueta'] = 'Cédula de extranjería (CE)';
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
$cc = array_values(array_filter($listas['tipo_documento'], static fn(array $o): bool => $o['valor'] === 'CC'))[0] ?? [];
comprobar('una de fábrica se apaga, no desaparece', ($cc['activo'] ?? null) === false, json_encode($cc));
comprobar('y la etiqueta se cambia sin tocar el valor',
    $listas['tipo_documento'][1]['valor'] === 'CE' && $listas['tipo_documento'][1]['etiqueta'] === 'Cédula de extranjería (CE)');

$entrada = $sinCambios();
array_splice($entrada['tipo_documento'], 0, 1);   // la cédula ni siquiera llega
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una de fábrica que no llega se conserva apagada',
    in_array('CC', array_column($listas['tipo_documento'], 'valor'), true));

$entrada = $sinCambios();
$entrada['tipo_documento'][] = ['valor' => 'XX', 'etiqueta' => 'Inventada', 'activo' => '1'];
[$listas] = Formulario::leerListas($entrada, $fabrica);
comprobar('un valor «existente» que nunca existió se descarta',
    !in_array('XX', array_column($listas['tipo_documento'], 'valor'), true));

echo "\nGénero, etnia, discapacidad\n";
$entrada = $sinCambios();
foreach ($entrada['genero'] as $i => $o) {
    if ($o['valor'] === '') {
        unset($entrada['genero'][$i]['activo']);   // intenta apagar «prefiero no responder»
    }
}
$entrada['genero'][] = ['etiqueta' => 'Prefiero autodescribirme', 'activo' => '1'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
$vacia = array_values(array_filter($listas['genero'], static fn(array $o): bool => $o['valor'] === ''))[0] ?? [];
comprobar('«prefiero no responder» no se puede apagar', ($vacia['activo'] ?? false) === true);
comprobar('una opción nueva se guarda con su propio texto',
    in_array('Prefiero autodescribirme', array_column($listas['genero'], 'valor'), true));

$entrada = $sinCambios();
$entrada['etnia'][] = ['etiqueta' => 'Indígena', 'activo' => '1'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una opción repetida se rechaza', str_contains($errores['etnia'] ?? '', 'ya está'), $errores['etnia'] ?? '');

echo "\nListas de texto\n";
$entrada = $sinCambios();
$entrada['categoria'] = "Gobierno digital\n\n  Robótica   educativa \nGobierno digital\n";
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una por línea, sin vacías, sin repetidas y con los espacios limpios',
    $listas['categoria'] === ['Gobierno digital', 'Robótica educativa'], json_encode($listas['categoria'], JSON_UNESCAPED_UNICODE));
$entrada['categoria'] = '';
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('vacía no se puede', isset($errores['categoria']));
$entrada['rango_edad'] = str_repeat('x', 41);
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una opción más larga que la columna se rechaza', str_contains($errores['rango_edad'] ?? '', 'máximo es 40'));

echo "\nDepartamentos y municipios\n";
$entrada = $sinCambios();
$entrada['ubicacion'] = "Nariño\n- Pasto\n- Ipiales\n- Pasto\nPutumayo:\n• Mocoa\nVacío\n";
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('se leen departamentos y municipios', $listas['ubicacion'] === [
    'Nariño' => ['Pasto', 'Ipiales'], 'Putumayo' => ['Mocoa'],
], json_encode($listas['ubicacion'], JSON_UNESCAPED_UNICODE));
$entrada['ubicacion'] = "- Pasto\nNariño\n- Ipiales";
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('un municipio sin departamento arriba se rechaza diciendo la línea',
    str_contains($errores['ubicacion'] ?? '', 'línea 1'), $errores['ubicacion'] ?? '');
comprobar('el texto del editor ida y vuelta da lo mismo', (static function () use ($fabrica): bool {
    [$listas] = Formulario::leerListas(['ubicacion' => Formulario::territorioComoTexto($fabrica['ubicacion'])] + [], $fabrica);
    return $listas['ubicacion'] == $fabrica['ubicacion'];
})());

echo "\nDuraciones y perfiles\n";
$entrada = $sinCambios();
$entrada['duracion'] = '30; 90 , 30 15';
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('las duraciones se ordenan sin repetirse', $listas['duracion'] === [15, 30, 90], json_encode($listas['duracion']));
$entrada['duracion'] = '20, mucho';
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una duración que no es número se rechaza', isset($errores['duracion']));

echo "\nPerfiles de asistencia\n";
$claves = static fn(array $lista): array => array_column($lista, 'valor');
$opcionDe = static function (array $lista, string $clave): array {
    foreach ($lista as $o) {
        if ($o['valor'] === $clave) {
            return $o;
        }
    }
    return [];
};
/** La fila de un perfil como la manda la pantalla, con cambios. */
$conFila = static function (array $entrada, string $clave, array $cambios): array {
    foreach ($entrada['perfil'] as $i => $fila) {
        if ($fila['valor'] === $clave) {
            $entrada['perfil'][$i] = $cambios + $fila;
            if (array_key_exists('activo', $cambios) && $cambios['activo'] === null) {
                unset($entrada['perfil'][$i]['activo']);
            }
        }
    }
    return $entrada;
};

comprobar('de fábrica traen Rueda de Negocios y Comunicaciones, encendidos',
    ($opcionDe($fabrica['perfil'], 'rueda_de_negocios')['etiqueta'] ?? '') === 'Rueda de Negocios'
    && ($opcionDe($fabrica['perfil'], 'comunicaciones')['activo'] ?? false) === true);

$entrada = $sinCambios();
$entrada['perfil'][] = ['etiqueta' => 'Aliados  Estratégicos'];
$entrada['perfil'][] = ['etiqueta' => ''];   // la fila vacía de agregar
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('se agrega uno nuevo, con una clave sin tildes ni espacios',
    ($opcionDe($listas['perfil'], 'aliados_estrategicos')['etiqueta'] ?? '') === 'Aliados Estratégicos',
    implode(',', $claves($listas['perfil'])));
comprobar('encendido, y la fila vacía no crea nada', ($opcionDe($listas['perfil'], 'aliados_estrategicos')['activo'] ?? false) === true
    && count($listas['perfil']) === count($fabrica['perfil']) + 1);

$entrada = $sinCambios();
$entrada['perfil'][] = ['etiqueta' => 'rueda de negocios'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('uno con el nombre de otro se rechaza', str_contains($errores['perfil'] ?? '', 'ya está'), $errores['perfil'] ?? '');
$entrada = $sinCambios();
$entrada['perfil'][] = ['etiqueta' => 'Staff'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('y uno que se llame como los de administrador también', isset($errores['perfil']), $errores['perfil'] ?? '');
$entrada = $sinCambios();
$entrada['perfil'][] = ['etiqueta' => str_repeat('x', 31)];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('un nombre que no cabe en el carnet se rechaza', str_contains($errores['perfil'] ?? '', 'carnet'));

$entrada = $conFila($sinCambios(), 'comunicaciones', ['etiqueta' => 'Comunicaciones y medios']);
$entrada = $conFila($entrada, 'prensa', ['activo' => null]);
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('se renombra sin cambiar la clave', ($opcionDe($listas['perfil'], 'comunicaciones')['etiqueta'] ?? '') === 'Comunicaciones y medios');
comprobar('y se apaga uno', ($opcionDe($listas['perfil'], 'prensa')['activo'] ?? null) === false);

$entrada = $conFila($sinCambios(), 'participante', ['activo' => null, 'eliminar' => '1']);
$entrada = $conFila($entrada, 'expositor', ['eliminar' => '1']);
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('«participante» no se apaga ni se elimina', ($opcionDe($listas['perfil'], 'participante')['activo'] ?? false) === true);
comprobar('«expositor» no se elimina', in_array('expositor', $claves($listas['perfil']), true));

$entrada = $conFila($sinCambios(), 'visitante', ['eliminar' => '1']);
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica, ['participante' => 40]);
comprobar('uno que no tiene nadie se elimina', !in_array('visitante', $claves($listas['perfil']), true) && $errores === []);

$entrada = $conFila($sinCambios(), 'prensa', ['eliminar' => '1']);
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica, ['prensa' => 3]);
comprobar('uno que tienen 3 personas no se elimina, y se dice por qué',
    str_contains($errores['perfil'] ?? '', '3 personas') && in_array('prensa', $claves($listas['perfil']), true),
    $errores['perfil'] ?? '');

$entrada = $sinCambios();
array_splice($entrada['perfil'], 1, 1);   // la fila de «visitante» no llega
$entrada['perfil'][] = ['valor' => 'staff', 'etiqueta' => 'Staff', 'activo' => '1'];
[$listas, $errores] = Formulario::leerListas($entrada, $fabrica);
comprobar('una fila que no llega no elimina nada', in_array('visitante', $claves($listas['perfil']), true));
comprobar('«staff» no se cuela aunque se mande', !in_array('staff', $claves($listas['perfil']), true));

$entrada = $sinCambios();
$entrada['perfil'][] = ['etiqueta' => 'Invitados'];
[$listas] = Formulario::leerListas($entrada, $fabrica, ['invitados' => 2]);
comprobar('la clave nueva no toma la de alguien que ya la tenga', in_array('invitados_2', $claves($listas['perfil']), true),
    implode(',', $claves($listas['perfil'])));

// Lo que guardaba la 3.7: [clave => encendido] de los cuatro de entonces.
[$listas] = Formulario::leerListas(['perfil' => []], ['perfil' => [
    'participante' => true, 'visitante' => false, 'expositor' => true, 'prensa' => true,
]] + $fabrica);
comprobar('la configuración de la 3.7 se sigue leyendo',
    ($opcionDe($listas['perfil'], 'visitante')['activo'] ?? null) === false
    && ($opcionDe($listas['perfil'], 'prensa')['activo'] ?? null) === true);
comprobar('y los perfiles nuevos le llegan encendidos', ($opcionDe($listas['perfil'], 'rueda_de_negocios')['activo'] ?? null) === true);

$f = Formulario::delEvento(0);
comprobar('el formulario ofrece los encendidos', in_array('comunicaciones', $f->perfiles(), true) && !in_array('staff', $f->perfiles(), true));
comprobar('a quien tiene uno que ya no está se le sigue ofreciendo', in_array('de_antes', $f->perfiles('de_antes'), true));
comprobar('pero nunca Staff, aunque lo tenga', !in_array('staff', $f->perfiles('staff'), true));
comprobar('el equipo ve todos, con Staff y Organizador',
    isset($f->todosLosPerfiles()['staff'], $f->todosLosPerfiles()['organizador'], $f->todosLosPerfiles()['rueda_de_negocios']));
comprobar('el nombre sale de la lista', $f->nombrePerfil('rueda_de_negocios') === 'Rueda de Negocios' && $f->nombrePerfil('staff') === 'Staff');
comprobar('y uno que ya no está no queda en blanco', $f->nombrePerfil('aliados_de_antes') === 'Aliados de antes');

$ofrecidos = $f->perfiles();
comprobar('desde el formulario no se sube a Staff', Persona::rolAdmitido('staff', 'participante', $ofrecidos) === 'participante');
comprobar('ni Staff lo pierde al guardar sus datos', Persona::rolAdmitido('prensa', 'staff', $ofrecidos) === 'staff');
comprobar('un perfil nuevo se puede elegir', Persona::rolAdmitido('rueda_de_negocios', 'participante', $ofrecidos) === 'rueda_de_negocios');
comprobar('uno inventado no, y se conserva el que tenía', Persona::rolAdmitido('inventado', 'visitante', $ofrecidos) === 'visitante');

echo "\nRegistro completo\n";
comprobar('con nombre y documento, completo', Persona::registroCompleto(['nombre' => 'Ana', 'documento_huella' => 'x'], true));
comprobar('sin documento, incompleto si el evento lo exige', !Persona::registroCompleto(['nombre' => 'Ana', 'documento_huella' => null], true));
comprobar('y completo si no lo exige', Persona::registroCompleto(['nombre' => 'Ana', 'documento_huella' => null], false));
comprobar('sin nombre, nunca', !Persona::registroCompleto(['nombre' => ' ', 'documento_huella' => 'x'], false));

echo "\nIdentificación en pantalla\n";
comprobar('una cédula lleva puntos', documento('1085234567') === '1.085.234.567');
comprobar('un pasaporte conserva sus letras', documento('ab123456') === 'AB123456', documento('ab123456'));
comprobar('sin documento no se pinta «CC —»', identificacion('CC', '') === '');
comprobar('con documento va con su tipo', identificacion('PPT', '5X99812') === 'PPT 5X99812');

echo "\n" . str_repeat('─', 58) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
