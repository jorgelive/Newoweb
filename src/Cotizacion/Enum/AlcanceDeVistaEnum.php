<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Hasta dónde llega lo que alguien ve del expediente.
 *
 * Existe como enum y no como booleanos sueltos porque son **excluyentes y ordenados**: quien tiene
 * `EXPEDIENTE` no necesita además `SUS_GRUPOS`. Con banderas, la combinación «grupos sí, expediente
 * también» habría que decidir qué significa, y no significa nada.
 */
enum AlcanceDeVistaEnum: string
{
    /** Todo el viaje del colegio. ⚠️ Los invitados NO entran: eso lo decide `esExpuesto()`. */
    case EXPEDIENTE = 'expediente';

    /** Los grupos a los que pertenece. Un coordinador está en el suyo. */
    case SUS_GRUPOS = 'sus_grupos';

    /** Sólo su ficha. */
    case SOLO_YO = 'solo_yo';

    /** La agencia. No sale de un tipo de pasajero sino de la sesión: ve TODO, invitados incluidos. */
    case AGENCIA = 'agencia';
}
