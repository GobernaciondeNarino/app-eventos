<?php
/**
 * Marca del evento: el logo cargado o, si no hay, la inicial del nombre.
 *
 * Devuelve una función para poder pedir varios tamaños desde la misma vista
 * sin repetir la lógica de «¿hay logo o no?».
 *
 * @var array      $tema
 * @var array|null $evento
 */

defined('EVENTOS_TIC') || exit;

return static function (string $tamano = '') use ($tema, $evento): string {
    $clase = 'brandmark' . ($tamano !== '' ? ' brandmark--' . $tamano : '');

    $logo = urlLogo($tema, $evento);
    if ($logo !== '') {
        return '<span class="' . $clase . '"><img src="' . e($logo) . '" alt=""></span>';
    }

    $inicial = mb_strtoupper(mb_substr(trim((string) ($evento['nombre'] ?? 'E')), 0, 1));
    return '<span class="' . $clase . '" aria-hidden="true">' . e($inicial) . '</span>';
};
