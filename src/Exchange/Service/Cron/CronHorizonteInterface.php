<?php

declare(strict_types=1);

namespace App\Exchange\Service\Cron;

use DateTimeImmutable;

/**
 * Job que sabe hasta dónde llegan SUS datos en el calendario.
 *
 * Sin esto, `TimelineEnqueuerService` recorre el cursor hasta un tope fijo de +18 meses antes
 * de reiniciarlo. Ese número es arbitrario y suele quedar muy por encima del horizonte real:
 * medido en producción, las tarifas terminaban a 151 días y las reservas a 185, así que del
 * orden del 70 % de las ejecuciones barría calendario **garantizadamente vacío**.
 *
 * El horizonte real se sabe con un `MAX(fecha)`. Reiniciar el cursor ahí no es una heurística
 * sino un límite exacto, y se autoajusta solo: si se cargan tarifas de 2028, el horizonte se
 * extiende sin tocar código.
 *
 * Es OPCIONAL: un job que no la implemente conserva el tope de +18 meses.
 */
interface CronHorizonteInterface
{
    /**
     * Última fecha con datos que tenga sentido barrer, o null si no se puede determinar
     * (en cuyo caso se usa el tope por defecto).
     */
    public function getHorizonteMaximo(): ?DateTimeImmutable;
}
