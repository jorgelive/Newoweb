<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

use DateTimeImmutable;

/**
 * Criterios de búsqueda de la vista de caja, tal como los recibe cada módulo.
 *
 * Se pasa un objeto y no seis argumentos sueltos para que añadir un filtro mañana no
 * obligue a cambiar la firma en todos los providers a la vez.
 */
final readonly class FinMovimientoFiltro
{
    /**
     * @param string|null $medio  Código del medio en el vocabulario del módulo. Un medio que
     *                            ese módulo no conozca debe devolver lista vacía, no todo.
     * @param string|null $texto  Búsqueda libre: localizador, referencia o nombre del cliente.
     * @param int         $limite Tope POR MÓDULO. Ver la nota de `FinMovimientoRegistry::buscar()`.
     */
    public function __construct(
        public ?DateTimeImmutable $desde = null,
        public ?DateTimeImmutable $hasta = null,
        public ?string $medio = null,
        public ?string $texto = null,
        public int $limite = 500,
    ) {}
}
