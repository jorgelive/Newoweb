<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;

/**
 * Dos estancias de la MISMA reserva en la MISMA casita, una detrás de otra, son UNA estancia.
 *
 * ── Por qué hacen falta dos eventos ─────────────────────────────────────────
 * Es como se alarga una reserva de OTA: la noche extra entra como estancia **directa** aparte
 * (importe 0 en el evento; el cobro va por cargos), porque las fechas de la primera son de
 * Booking o Airbnb y no se pueden tocar. 14 casos medidos el 17/10/2026, todos con ese patrón:
 *
 * ```
 * 8YXYNK  Casita 1   10/10 15:00 → 14/10 10:00   booking
 *                    14/10 15:00 → 15/10 10:00   directo   ← la noche extra
 * ```
 *
 * ── Lo que se rompía leyéndolas por separado ────────────────────────────────
 * - El agente apuntó la hora de salida en la PRIMERA: «sale el 14/10 a las 06:30», cuando salía
 *   el 15 (`ConfirmarHoraSkill`).
 * - La lista de salidas para limpieza enseñaba una salida y una llegada el 14 en la misma casita:
 *   un cambio de huésped que no existe (`ListarSalidasSkill`, la portada de util).
 * - Y antes, el caso MMQSB2 (27/09/2026): el agente creía que el huésped se iba y que «entraba
 *   otro a las 14:00», que era él mismo (`PmsEspacioEstancia`, que ya tenía esta regla escondida).
 *
 * ── La regla, una sola vez ──────────────────────────────────────────────────
 * B continúa a A si son de la misma reserva y la misma casita, las dos viven (`IMPIDEN_VENTA`),
 * B empieza el mismo día en que acaba A —o antes— y acaba después. Si a mitad cambia de casita,
 * la cadena se corta ahí: eso sí es una salida y una llegada.
 *
 * ⚠️ **Espejo en SQL** en `ListarSalidasSkill::consultar()`, que filtra en la consulta. Si cambia
 * la regla, se cambian los dos.
 */
final class CadenaDeEstancia
{
    /** ¿`$despues` continúa a `$antes` en la misma casita? */
    public static function continua(PmsEventoCalendario $antes, PmsEventoCalendario $despues): bool
    {
        if ($antes === $despues || !self::viva($antes) || !self::viva($despues)) {
            return false;
        }

        $reservaA = $antes->getReserva();
        $unidadA = $antes->getPmsUnidad();

        if ($reservaA === null || $unidadA === null
            || $despues->getReserva() !== $reservaA || $despues->getPmsUnidad() !== $unidadA) {
            return false;
        }

        $finA = $antes->getFin();
        $inicioB = $despues->getInicio();
        $finB = $despues->getFin();

        return $finA !== null && $inicioB !== null && $finB !== null
            && $inicioB->format('Y-m-d') <= $finA->format('Y-m-d')
            && $finB > $finA;
    }

    /** La última estancia de la cadena: donde va la hora de SALIDA. */
    public static function ultima(PmsEventoCalendario $evento): PmsEventoCalendario
    {
        $actual = $evento;
        $vistos = [spl_object_id($actual) => true];

        while (($siguiente = self::siguienteDe($actual)) !== null && !isset($vistos[spl_object_id($siguiente)])) {
            $vistos[spl_object_id($siguiente)] = true;
            $actual = $siguiente;
        }

        return $actual;
    }

    /** La primera estancia de la cadena: donde va la hora de LLEGADA. */
    public static function primera(PmsEventoCalendario $evento): PmsEventoCalendario
    {
        $actual = $evento;
        $vistos = [spl_object_id($actual) => true];

        while (($anterior = self::anteriorDe($actual)) !== null && !isset($vistos[spl_object_id($anterior)])) {
            $vistos[spl_object_id($anterior)] = true;
            $actual = $anterior;
        }

        return $actual;
    }

    /**
     * Para un lote de estancias ya cargado: cuáles siguen en la misma casita y cuáles vienen de
     * otra estancia en ella. Sin consultar nada: compara sólo dentro del lote.
     *
     * Es lo que usa el calendario, que ya trae las estancias del rango; una cadena que cruce el
     * borde del rango no se ve, igual que el resto de lo que el calendario no ha cargado.
     *
     * @param iterable<PmsEventoCalendario> $eventos
     *
     * @return array<int, array{sigue: bool, viene: bool}> por `spl_object_id` del evento
     */
    public static function marcas(iterable $eventos): array
    {
        $lista = [];

        foreach ($eventos as $evento) {
            $lista[] = $evento;
        }

        $siguen = [];
        $vienen = [];

        foreach ($lista as $a) {
            foreach ($lista as $b) {
                if (self::continua($a, $b)) {
                    $siguen[spl_object_id($a)] = true;
                    $vienen[spl_object_id($b)] = true;
                }
            }
        }

        $marcas = [];

        foreach ($lista as $evento) {
            $id = spl_object_id($evento);
            $marcas[$id] = ['sigue' => isset($siguen[$id]), 'viene' => isset($vienen[$id])];
        }

        return $marcas;
    }

    private static function siguienteDe(PmsEventoCalendario $evento): ?PmsEventoCalendario
    {
        $mejor = null;

        foreach ($evento->getReserva()?->getEventosCalendario() ?? [] as $otro) {
            if (self::continua($evento, $otro) && ($mejor === null || $otro->getFin() > $mejor->getFin())) {
                $mejor = $otro;
            }
        }

        return $mejor;
    }

    private static function anteriorDe(PmsEventoCalendario $evento): ?PmsEventoCalendario
    {
        $mejor = null;

        foreach ($evento->getReserva()?->getEventosCalendario() ?? [] as $otro) {
            if (self::continua($otro, $evento) && ($mejor === null || $otro->getInicio() < $mejor->getInicio())) {
                $mejor = $otro;
            }
        }

        return $mejor;
    }

    private static function viva(PmsEventoCalendario $evento): bool
    {
        $codigo = $evento->getEstado()?->getId();

        return $codigo !== null && \in_array($codigo, PmsEventoEstado::IMPIDEN_VENTA, true);
    }
}
