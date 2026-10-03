<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;

/**
 * Las estancias que entran (o salen) el mismo día que la primera entrada (o la última salida) de
 * la reserva: las que confirma o pregunta un botón de hora. Las de otros días —un cambio de casita
 * a mitad de estancia— no entran.
 *
 * Lo usan los dos botones de hora (`ConfirmarHoraActionHandler`, `PedirHoraActionHandler`): un
 * botón no puede preguntar «¿de cuál casita?», y una familia en dos casitas sale de las dos a la vez.
 */
final class EstanciasDelBorde
{
    /**
     * @param array<int, PmsEventoCalendario> $activas Las de `PmsReserva::getEventosActivosGuia()`.
     * @return list<PmsEventoCalendario>
     */
    public static function de(array $activas, bool $esSalida): array
    {
        $dia = static fn (PmsEventoCalendario $e): ?string => ($esSalida ? $e->getFin() : $e->getInicio())?->format('Y-m-d');
        $dias = array_filter(array_map($dia, array_values($activas)));

        if ($dias === []) {
            return [];
        }

        $elDia = $esSalida ? max($dias) : min($dias);

        return array_values(array_filter($activas, static fn (PmsEventoCalendario $e): bool => $dia($e) === $elDia));
    }
}
