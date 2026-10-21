<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * El orden de un listado, elegido desde el título de cada columna.
 *
 * Ordena la base de datos, no el navegador: con el listado partido en páginas,
 * ordenar solo lo que se ve pondría primero a la «A» de la página 3, que no es
 * la primera del evento.
 *
 * De la dirección llega solo una clave —?orden=entidad&dir=desc— que se busca
 * en las columnas que declara cada pantalla. La expresión SQL sale siempre de
 * esa lista: nada de lo que escriba quien visita llega a la consulta.
 */
final class Orden
{
    /**
     * @param array<string, array{0: string|array<int, string>, 1?: string}> $columnas
     *        clave => [expresión o expresiones, condición de «vacío»]. Las filas
     *        vacías van al final en los dos sentidos: una lista que empieza por
     *        veinte entidades en blanco no le sirve a nadie.
     */
    private function __construct(
        public readonly string $clave,
        public readonly string $direccion,
        private readonly array $columnas,
        private readonly string $desempate
    ) {
    }

    /**
     * @param string $desempate lo que va al final, para que dos filas iguales
     *        salgan siempre en el mismo orden. Sin él, la misma persona podía
     *        aparecer en dos páginas seguidas y otra en ninguna.
     */
    public static function desde(
        Peticion $peticion,
        array $columnas,
        string $porDefecto,
        string $direccionPorDefecto = 'asc',
        string $desempate = ''
    ): self {
        $clave = $peticion->query('orden');
        $elegida = isset($columnas[$clave]);
        if (!$elegida) {
            $clave = $porDefecto;
        }

        $direccion = $peticion->query('dir');
        if (!in_array($direccion, ['asc', 'desc'], true)) {
            $direccion = $elegida ? 'asc' : $direccionPorDefecto;
        }

        return new self($clave, $direccion, $columnas, $desempate);
    }

    /** Lo que va después de ORDER BY. */
    public function sql(): string
    {
        $columna = $this->columnas[$this->clave];
        $expresiones = (array) $columna[0];
        $vacio = (string) ($columna[1] ?? '');

        $partes = [];
        if ($vacio !== '') {
            $partes[] = '(' . $vacio . ') ASC';
        }
        foreach ($expresiones as $expresion) {
            $partes[] = $expresion . ' ' . strtoupper($this->direccion);
        }
        if ($this->desempate !== '') {
            $partes[] = $this->desempate;
        }
        return implode(', ', $partes);
    }

    public function activa(string $clave): bool
    {
        return $clave === $this->clave;
    }

    /**
     * Los parámetros del enlace del título de una columna: la primera vez
     * ordena de menor a mayor; si ya ordena por ella, invierte el sentido.
     *
     * @return array{orden: string, dir: string}
     */
    public function consultaPara(string $clave): array
    {
        $direccion = $this->activa($clave) && $this->direccion === 'asc' ? 'desc' : 'asc';
        return ['orden' => $clave, 'dir' => $direccion];
    }

    /**
     * El orden actual, para conservarlo en los enlaces de las páginas.
     *
     * @return array{orden?: string, dir?: string}
     */
    public function consulta(): array
    {
        return ['orden' => $this->clave, 'dir' => $this->direccion];
    }
}
