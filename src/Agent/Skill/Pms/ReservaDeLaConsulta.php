<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;

/**
 * De qué reserva pregunta quien consulta la guía, los códigos o el WiFi (16/10/2026).
 *
 * ### La regla
 *
 * - **Un huésped**: la de SU conversación, siempre. El parámetro no cuenta: un UUID escrito no le
 *   abre la casita de otro.
 * - **El equipo**: la que nombre (`reserva_id`); si no nombra ninguna, la de la conversación.
 *
 * ### Por qué hacía falta
 *
 * Las tres skills hacían `$actor->contextoId() ?? $parametro` sin mirar el TIPO del contexto. El
 * equipo escribe por WhatsApp en su hilo de avisos, cuyo contexto es `staff:<uuid del operador>`
 * (`AvisoAlEquipoService`): el «contexto» era el UUID de una persona, el parámetro se ignoraba y
 * la consulta moría en «no encuentro la reserva» — justo el caso del operador en la puerta. Es la
 * forma en que ya lo hacían `ConsultarCuentaSkill` y `ConsultarMediosPagoSkill`.
 */
final class ReservaDeLaConsulta
{
    /** La reserva atada a la conversación, si el contexto ES una reserva. */
    public static function contexto(ActorInterface $actor): ?string
    {
        return $actor->contextoTipo() === 'pms_reserva' ? $actor->contextoId() : null;
    }

    /** El id con el que trabajar (puede ser `''`): ver la regla en la cabecera. */
    public static function de(ActorInterface $actor, string $parametro): string
    {
        if ($actor->esDelEquipo() && $parametro !== '') {
            return $parametro;
        }

        return self::contexto($actor) ?? ($actor->esDelEquipo() ? $parametro : '');
    }
}
