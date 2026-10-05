<?php
/**
 * Manifiesto de navegación.
 *
 * Una sola lista alimenta la barra lateral, la barra superior y la navegación
 * inferior del móvil. Cada entrada declara el rol que hace falta para verla, y
 * ese rol es el mismo que el guardia de la ruta comprueba en el servidor: la
 * lista decide qué se muestra, nunca qué se permite.
 *
 * @var array|null $persona
 * @var array|null $usuario
 */

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Guardia;

$iconos = [
    'home'     => 'M3 10.6 12 3.5l9 7.1V20a1 1 0 0 1-1 1h-4.5v-6.5h-7V21H5a1 1 0 0 1-1-1z',
    'form'     => 'M4 20.5h4l10.5-10.5-4-4L4 16.5zM14.5 6l4 4M4 3.5h6',
    'card'     => 'M3 6h18v12H3zM7 10.5h3.5M7 14h6.5M16 9.5h3v3.5h-3z',
    'qr'       => 'M4 8.5V4.5h4M20 8.5V4.5h-4M4 15.5v4h4M20 15.5v4h-4M4.5 12h15',
    'users'    => 'M15.5 19.5v-1a3.5 3.5 0 0 0-7 0v1M12 11.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.5 19.5v-1a3 3 0 0 0-2.2-2.9',
    'cal'      => 'M4 6.5h16v14H4zM4 10.5h16M8.5 3.5v4M15.5 3.5v4M8 14h3M8 17.5h8',
    'scan'     => 'M4 8V5a1 1 0 0 1 1-1h3M20 8V5a1 1 0 0 0-1-1h-3M4 16v3a1 1 0 0 0 1 1h3M20 16v3a1 1 0 0 1-1 1h-3M7 12h10',
    'table'    => 'M3.5 5h17v14h-17zM3.5 10h17M9.5 10v9M15 10v9',
    'grid'     => 'M4 4.5h6v6H4zM14 4.5h6v6h-6zM4 14h6v6H4zM14 14h2.5v2.5H14M18 17.5h2v2h-2',
    'theme'    => 'M12 3.5a8.5 8.5 0 1 0 0 17 1.9 1.9 0 0 0 0-3.8 4.7 4.7 0 0 1 0-9.4 8.5 8.5 0 0 0 0-3.8M8 8.5h.01M7 13h.01M12 7h.01',
    'user'     => 'M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8M5 20.5a7 7 0 0 1 14 0',
    'panel'    => 'M4 4.5h6.5v6H4zM13.5 4.5H20v10h-6.5zM4 13.5h6.5v6H4zM13.5 17.5H20v2h-6.5z',
    'mic'      => 'M12 3.5a2.5 2.5 0 0 1 2.5 2.5v6a2.5 2.5 0 0 1-5 0V6A2.5 2.5 0 0 1 12 3.5M6 11.5a6 6 0 0 0 12 0M12 17.5v3',
    'evento'   => 'M3.5 7.5h17v12h-17zM3.5 11.5h17M8 4v3.5M16 4v3.5M7.5 15h4',
    'salir'    => 'M9 5.5H5.5v13H9M14 8.5l3.5 3.5L14 15.5M17 12H9',
    'mail'     => 'M3.5 6h17v12h-17zM3.5 7l8.5 6 8.5-6',
    'llave'    => 'M14.5 9.5a3.5 3.5 0 1 1-3.4 4.4L4 20.5H2.5V18l6.6-6.6a3.5 3.5 0 0 1 5.4-1.9M16 8h.01',
    'ajustes'  => 'M4 6.5h9M17 6.5h3M4 12h3M11 12h9M4 17.5h11M19 17.5h1M15 4.5v4M9 10v4M17 15.5v4',
];

/** Dibuja un icono del juego de arriba. */
$icono = static function (string $clave, int $tam = 17, float $grosor = 1.5) use ($iconos): string {
    $d = $iconos[$clave] ?? '';
    return '<svg viewBox="0 0 24 24" width="' . $tam . '" height="' . $tam . '" fill="none" '
        . 'stroke="currentColor" stroke-width="' . $grosor . '" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"></path></svg>';
};

// --- Participante ---------------------------------------------------------
$grupoParticipante = [
    ['clave' => 'ingreso', 'etiqueta' => 'Ingreso', 'icono' => 'home', 'ruta' => '/'],
    ['clave' => 'registro', 'etiqueta' => 'Registro', 'icono' => 'form', 'ruta' => '/registro'],
    ['clave' => 'agenda', 'etiqueta' => 'Agenda', 'icono' => 'cal', 'ruta' => '/agenda'],
];

if ($persona !== null) {
    // Con sesión, el registro pasa a ser «mis datos» y aparecen las suyas.
    $grupoParticipante = [
        ['clave' => 'carnet', 'etiqueta' => 'Mi carnet', 'icono' => 'card', 'ruta' => '/carnet'],
        ['clave' => 'checkin', 'etiqueta' => 'Mi ingreso', 'icono' => 'qr', 'ruta' => '/checkin'],
        ['clave' => 'contactos', 'etiqueta' => 'Contactos', 'icono' => 'users', 'ruta' => '/contactos'],
        ['clave' => 'agenda', 'etiqueta' => 'Agenda', 'icono' => 'cal', 'ruta' => '/agenda'],
        ['clave' => 'registro', 'etiqueta' => 'Mis datos', 'icono' => 'form', 'ruta' => '/registro'],
    ];
}

// --- Staff ----------------------------------------------------------------
// Una persona con perfil Staff entra por la puerta del asistente —tiene su
// carnet como todo el mundo— y además acredita en la entrada. Sus dos pantallas
// van en un grupo aparte para que se vea que son otra cosa: lo suyo está arriba,
// el trabajo del evento aquí.
$grupoStaff = [];
if (Guardia::staffActual() !== null) {
    $grupoStaff = [
        ['clave' => 'acreditar', 'etiqueta' => 'Acreditar', 'icono' => 'scan', 'ruta' => '/acreditar'],
        ['clave' => 'carnets', 'etiqueta' => 'Carnets', 'icono' => 'card', 'ruta' => '/carnets'],
    ];
}

// --- Equipo organizador ---------------------------------------------------
$grupoAdmin = [];
if ($usuario !== null) {
    $candidatos = [
        ['clave' => 'panel', 'etiqueta' => 'Panel', 'icono' => 'panel', 'ruta' => '/admin', 'rol' => 'consulta'],
        ['clave' => 'admin-escaner', 'etiqueta' => 'Escanear carnet', 'icono' => 'scan', 'ruta' => '/admin/escaner', 'rol' => 'operador'],
        ['clave' => 'admin-registros', 'etiqueta' => 'Registros', 'icono' => 'table', 'ruta' => '/admin/registros', 'rol' => 'consulta'],
        // Mismo rol que el guardia 'acreditar' pide al equipo. Faltaba: la
        // pantalla existía y solo se llegaba escribiendo la dirección, porque
        // en la navegación estaba únicamente para el staff.
        ['clave' => 'carnets', 'etiqueta' => 'Carnets', 'icono' => 'card', 'ruta' => '/carnets', 'rol' => 'operador'],
        ['clave' => 'admin-qr', 'etiqueta' => 'QR por día', 'icono' => 'grid', 'ruta' => '/admin/qr-dias', 'rol' => 'operador'],
        ['clave' => 'admin-expositores', 'etiqueta' => 'Expositores', 'icono' => 'mic', 'ruta' => '/admin/expositores', 'rol' => 'administrador'],
        ['clave' => 'admin-organizadores', 'etiqueta' => 'Organizadores', 'icono' => 'users', 'ruta' => '/admin/organizadores', 'rol' => 'administrador'],
        ['clave' => 'admin-eventos', 'etiqueta' => 'Eventos', 'icono' => 'evento', 'ruta' => '/admin/eventos', 'rol' => 'administrador'],
        // Una sola entrada con pestañas: Registro, Identidad, Acceso y correo, y
        // la cuenta propia. Identidad y Autenticación eran entradas sueltas
        // hasta la 3.6; sus direcciones siguen funcionando.
        ['clave' => 'configuracion', 'etiqueta' => 'Configuración', 'icono' => 'ajustes', 'ruta' => '/admin/configuracion', 'rol' => 'consulta'],
    ];
    foreach ($candidatos as $item) {
        if (Guardia::tieneRol($usuario, $item['rol'])) {
            $grupoAdmin[] = $item;
        }
    }
}

$navegacion = [];
if ($grupoParticipante) {
    $navegacion[] = ['titulo' => $persona !== null ? 'Mi participación' : 'Participante', 'items' => $grupoParticipante];
}
if ($grupoStaff) {
    $navegacion[] = ['titulo' => 'Staff del evento', 'items' => $grupoStaff];
}
if ($grupoAdmin) {
    $navegacion[] = ['titulo' => 'Administración', 'items' => $grupoAdmin];
}

/**
 * Las cuatro entradas de la barra inferior del móvil.
 *
 * El staff trabaja en la puerta y con el teléfono en la mano, así que lo suyo va
 * primero: acreditar y los carnets, y luego sus propias pantallas.
 */
$pestanasMovil = match (true) {
    $usuario !== null => array_slice($grupoAdmin, 0, 4),
    (bool) $grupoStaff => array_slice(array_merge($grupoStaff, $grupoParticipante), 0, 4),
    default => array_slice($grupoParticipante, 0, 4),
};
