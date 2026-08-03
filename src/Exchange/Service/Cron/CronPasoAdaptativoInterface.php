<?php

declare(strict_types=1);

namespace App\Exchange\Service\Cron;

use DateInterval;
use DateTimeImmutable;

/**
 * Job cuyo paso de cursor DEPENDE de a qué distancia esté mirando.
 *
 * `getStepInterval()` no recibe la fecha de partida, así que no puede adaptarse. Añadirle el
 * parámetro obligaría a tocar la firma de los cinco jobs (en PHP una implementación con menos
 * parámetros que la interfaz es error fatal, aunque tengan valor por defecto), de ahí que esto
 * sea una interfaz aparte y opt-in.
 *
 * Cuándo tiene sentido: cuando la densidad de datos es MUY desigual dentro del rango que sí
 * tiene datos. Medido en producción, las reservas vivas se reparten 24 / 2 / 1 entre los
 * tramos 0-60 d, 60-180 d y +180 d: barrer lo lejano con el mismo paso que lo cercano gasta
 * la mayoría de las ejecuciones donde casi no hay nada.
 *
 * Cuándo NO: si la densidad es uniforme dentro del rango cubierto, alargar el paso lejano sólo
 * consigue revisar peor esa parte. Es el caso de las tarifas (23 contra 21 rangos), que por eso
 * NO implementa esta interfaz aunque sí la del horizonte.
 */
interface CronPasoAdaptativoInterface
{
    public function getStepIntervalDesde(DateTimeImmutable $desde): DateInterval;
}
