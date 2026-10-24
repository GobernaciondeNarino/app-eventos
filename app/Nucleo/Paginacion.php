<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Una página de un listado largo.
 *
 * Hasta la 3.12 los listados del panel traían como mucho doscientas filas y no
 * lo decían: con 396 registros, «Registros» mostraba 200 y la cuenta de arriba
 * decía «200 en el filtro», como si no hubiera más. Ahora se cuenta todo en la
 * base y se trae solo la página que se ve.
 *
 * La página y el tamaño viajan en la dirección (?pagina=3&por=50) junto con
 * los filtros y el orden: la página 3 de un filtro se puede recargar,
 * compartir y recorrer con «atrás» del navegador.
 */
final class Paginacion
{
    /** Los tamaños que se ofrecen. Uno distinto en la dirección se ignora. */
    public const TAMANOS = [25, 50, 100];

    private function __construct(
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina
    ) {
    }

    /** Lee ?pagina= y ?por= de la petición, con el total ya contado. */
    public static function desde(Peticion $peticion, int $total, int $porPagina = 50): self
    {
        $por = $peticion->entero('por');
        if (!in_array($por, self::TAMANOS, true)) {
            $por = $porPagina;
        }
        $total = max(0, $total);
        $paginas = max(1, (int) ceil($total / $por));

        // Una página que ya no existe —se borró gente, o el filtro deja menos—
        // lleva a la última, no a una tabla vacía que hace pensar que no hay
        // nadie.
        $pagina = min(max(1, $peticion->entero('pagina', 1)), $paginas);

        return new self($total, $pagina, $por);
    }

    /**
     * Para la API: cualquier tamaño entre 1 y el tope, no solo los de la
     * pantalla —un programa sabe cuántas filas le conviene pedir—.
     *
     * Y una página pasada la última llega vacía, en vez de llevar a la última
     * como en el panel: un programa que pide páginas hasta que una venga vacía
     * recibiría la última una y otra vez, sin terminar nunca.
     */
    public static function conTope(Peticion $peticion, int $total, int $porPagina, int $tope): self
    {
        $por = min(max(1, $peticion->entero('por', $porPagina)), $tope);
        $pagina = max(1, $peticion->entero('pagina', 1));

        return new self(max(0, $total), $pagina, $por);
    }

    public function paginas(): int
    {
        return max(1, (int) ceil($this->total / $this->porPagina));
    }

    /** Cuántas filas saltar en la consulta. */
    public function desplazamiento(): int
    {
        return ($this->pagina - 1) * $this->porPagina;
    }

    /** El número de la primera fila de la página, contando desde 1. */
    public function primero(): int
    {
        return $this->total === 0 ? 0 : $this->desplazamiento() + 1;
    }

    public function ultimo(): int
    {
        return min($this->total, $this->desplazamiento() + $this->porPagina);
    }

    /**
     * Los números de página que se muestran: la primera, la última y dos a
     * cada lado de la actual, con null donde va «…». Con cuarenta páginas no
     * caben cuarenta botones en un teléfono.
     *
     * @return array<int, int|null>
     */
    public function numeros(): array
    {
        $ultima = $this->paginas();
        $lista = [];
        $anterior = 0;
        for ($n = 1; $n <= $ultima; $n++) {
            if ($n !== 1 && $n !== $ultima && abs($n - $this->pagina) > 2) {
                continue;
            }
            if ($anterior > 0 && $n - $anterior > 1) {
                $lista[] = null;
            }
            $lista[] = $n;
            $anterior = $n;
        }
        return $lista;
    }
}
