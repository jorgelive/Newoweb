<?php

declare(strict_types=1);

namespace App\Contract;


/**
 * En qué punto del ciclo está un {@see Frente}: comprando, o ya comprado.
 *
 * ── Por qué existe, si ya está `VinculoComercial` ───────────────────────────
 * `VinculoComercial` describe a la PERSONA («¿es cliente?»), y por eso hay uno solo por actor.
 * Esto describe un ASUNTO, y de esos puede haber varios abiertos a la vez **del mismo negocio**:
 * un huésped alojado (operación) que pregunta por ampliar su estadía (venta) tiene los dos
 * momentos vivos en alojamiento el mismo día.
 *
 * Ese caso es justamente el que demostró que el momento no se puede deducir del vínculo: con
 * dos asuntos abiertos, el vínculo dice «cliente» y no dice de cuál de los dos se habla. Lo
 * decide el frente, y el frente lo elige el triaje leyendo el mensaje.
 */
enum MomentoDeFrente: string
{
    /** Todavía se está decidiendo o cotizando. Puede no tener entidad detrás: ver {@see Frente}. */
    case Venta = 'venta';

    /** Ya está comprado y hay algo que operar: fechas, horas, confirmaciones. */
    case Operacion = 'operacion';
}
