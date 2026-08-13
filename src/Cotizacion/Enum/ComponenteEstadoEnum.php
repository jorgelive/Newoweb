<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * ¿Este componente sigue en pie dentro de la cotización?
 *
 * Sólo eso. **No dice si el proveedor confirmó**: esa pregunta la responde
 * `App\Operacion\Enum\EstadoReservaProveedorEnum` sobre la fila de La Biblia, que es donde
 * de verdad se gestiona y donde se edita.
 *
 * Antes tenía cuatro casos —`pendiente`, `confirmado`, `reconfirmado`, `cancelado`—
 * heredados de cuando cotización y operación eran lo mismo. Tres de ellos no los leía
 * **nadie**: el cálculo financiero y la propuesta al cliente sólo preguntan por
 * `cancelado`. Pero se pintaban en un `<select>` del editor, así que el vendedor podía
 * marcar «Confirmado» creyendo que estaba registrando la confirmación del proveedor.
 * Un flag que no hace nada es ruido; uno que aparenta hacer lo que hace otro módulo es
 * una trampa. En la base había 2 componentes marcados así, sin efecto alguno.
 *
 * Ver docs/Cotizaciones.md §3.b.
 */
enum ComponenteEstadoEnum: string
{
    case ACTIVO = 'activo';
    case CANCELADO = 'cancelado';
}
