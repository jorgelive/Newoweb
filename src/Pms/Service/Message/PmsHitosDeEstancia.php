<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\HitoDeEstancia;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Los momentos con nombre de una estancia, sacados de sus TRAMOS y no de sus fechas agregadas.
 *
 * ── Qué arregla ─────────────────────────────────────────────────────────────
 * `PmsReservaMessageContext::getMilestones()` resume la estancia en `start` y `end`, que salen
 * de `PmsReserva::getFechaLlegada()`/`getFechaSalida()` — el mínimo y el máximo de todos los
 * tramos. Una estancia partida se aplasta:
 *
 * ```
 *   Casita 1  10 → 12       (se va a Machu Picchu)
 *   Casita 3  15 → 18       (vuelve, y a otra casita)
 *
 *   hoy:  start = 10, end = 18     ← el 12 y el 15 no existen para la mensajería
 * ```
 *
 * Se le da la bienvenida el 10 y las instrucciones de salida el 18. **El día que de verdad se
 * va —el 12— nadie le dice cómo dejar la casita, y el día que vuelve —el 15— nadie le dice que
 * ahora está en la 3.** De 25 reservas de varios tramos en la base, 20 tienen hueco y 8 cambian
 * de unidad: es la mayoría de las multitramo, no una rareza.
 *
 * ── La trampa: dos casitas A LA VEZ no son un cambio ────────────────────────
 * ⚠️ El caso más común de reserva con varios tramos **no** es la estancia partida: es el grupo
 * que ocupa la Casita 2 y la Casita 5 las mismas noches. Comparar tramos consecutivos sin más
 * habría anunciado un «cambio de casita» a cada familia que reserva dos, todos los días.
 *
 * Por eso los tramos se agrupan primero en BLOQUES por solape temporal: un bloque es «dónde
 * está el huésped durante este rato», tenga una casita o cuatro. Los hitos salen de comparar
 * bloques, no tramos.
 *
 * ── Es derivación pura ──────────────────────────────────────────────────────
 * Sin base de datos, sin servicios: entra una reserva, salen sus hitos. Así se puede probar la
 * combinatoria entera —solapes, huecos, cambios, un tramo suelto— con entidades en memoria, que
 * es justo lo que no tenía el cálculo viejo.
 */
final readonly class PmsHitosDeEstancia
{
    /**
     * @return list<HitoDeEstancia> En orden cronológico. Vacía si la estancia no tiene tramos
     *                              vivos —una consulta, un bloqueo, todo cancelado—, y eso NO es
     *                              un error: es que no hay nada que anunciar.
     */
    public function para(PmsReserva $reserva): array
    {
        $bloques = $this->bloques($reserva);

        if ($bloques === []) {
            return [];
        }

        $hitos = [];
        $primero = $bloques[0];

        $hitos[] = new HitoDeEstancia(
            ConversationMilestoneInterface::START,
            $primero['inicio'],
            implode(' + ', $primero['unidades'])
        );

        for ($i = 0, $n = count($bloques) - 1; $i < $n; $i++) {
            $actual = $bloques[$i];
            $siguiente = $bloques[$i + 1];

            // Hay noches por medio en las que no ocupa nada: se va y vuelve. Se emiten los dos
            // hitos —la salida y el regreso— porque son dos mensajes distintos con días
            // distintos, no las dos caras de uno.
            if ($this->dia($siguiente['inicio']) > $this->dia($actual['fin'])) {
                $hitos[] = new HitoDeEstancia(
                    ConversationMilestoneInterface::TEMPORARY_END,
                    $actual['fin'],
                    implode(' + ', $actual['unidades'])
                );

                $hitos[] = new HitoDeEstancia(
                    ConversationMilestoneInterface::REENTRY,
                    $siguiente['inicio'],
                    implode(' + ', $siguiente['unidades'])
                );

                continue;
            }

            // Sin hueco: sigue alojado. Sólo hay algo que decir si le cambia la casita; si es la
            // misma, son dos tramos administrativos de una estancia continua —una extensión, un
            // cambio de tarifa— y anunciarlos sería ruido.
            if ($actual['unidades'] !== $siguiente['unidades']) {
                $hitos[] = new HitoDeEstancia(
                    ConversationMilestoneInterface::UNIT_CHANGE,
                    $siguiente['inicio'],
                    implode(' + ', $siguiente['unidades']),
                    implode(' + ', $actual['unidades'])
                );
            }
        }

        $ultimo = $bloques[count($bloques) - 1];

        $hitos[] = new HitoDeEstancia(
            ConversationMilestoneInterface::END,
            $ultimo['fin'],
            implode(' + ', $ultimo['unidades'])
        );

        return $hitos;
    }

    /**
     * Los tramos vivos agrupados por solape: dónde está el huésped en cada momento.
     *
     * Dos tramos que comparten aunque sea un día caen en el mismo bloque, porque el huésped está
     * en las dos casitas a la vez. Lo que separa bloques es el tiempo, no la unidad.
     *
     * @return list<array{inicio: DateTimeInterface, fin: DateTimeInterface, unidades: list<string>}>
     */
    private function bloques(PmsReserva $reserva): array
    {
        $tramos = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            if (!$this->cuenta($evento)) {
                continue;
            }

            $tramos[] = $evento;
        }

        usort(
            $tramos,
            static fn (PmsEventoCalendario $a, PmsEventoCalendario $b): int => $a->getInicio() <=> $b->getInicio()
        );

        $bloques = [];

        foreach ($tramos as $tramo) {
            $inicio = $tramo->getInicio();
            $fin = $tramo->getFin();
            $unidad = $tramo->getPmsUnidad()?->getNombre() ?? '—';
            $ultimo = $bloques === [] ? null : $bloques[count($bloques) - 1];

            // Solapa con el bloque abierto —empieza antes de que aquél termine— así que es la
            // misma estancia en otra casita más, no un tramo nuevo.
            if ($ultimo !== null && $this->dia($inicio) < $this->dia($ultimo['fin'])) {
                $bloques[count($bloques) - 1]['fin'] = max($ultimo['fin'], $fin);

                if (!in_array($unidad, $ultimo['unidades'], true)) {
                    $bloques[count($bloques) - 1]['unidades'][] = $unidad;
                    sort($bloques[count($bloques) - 1]['unidades']);
                }

                continue;
            }

            $bloques[] = ['inicio' => $inicio, 'fin' => $fin, 'unidades' => [$unidad]];
        }

        return $bloques;
    }

    /**
     * ¿Este tramo cuenta como estancia?
     *
     * Mismo criterio que el resto del sistema: los estados que identifican a un huésped, sin
     * extensiones. Una extensión es la noche fantasma que bloquea un horario extra (§7.1.b) y
     * estiraría la estancia un día por su cuenta, inventando un hueco donde no lo hay.
     */
    private function cuenta(PmsEventoCalendario $evento): bool
    {
        return $evento->getEventoOrigen() === null
            && $evento->getInicio() !== null
            && $evento->getFin() !== null
            && in_array($evento->getEstado()?->getId(), PmsEventoEstado::IDENTIFICAN_HUESPED, true);
    }

    /** El día calendario, sin la hora: los tramos van de las 14:00 a las 10:00 y comparar los
     *  instantes tal cual haría que un check-out y el check-in del mismo día pareciesen un hueco. */
    private function dia(DateTimeInterface $fecha): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($fecha)->setTime(0, 0);
    }
}
