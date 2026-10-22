<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Skill\EntradaDeSkill;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;

/**
 * De qué reserva habla quien usa una skill acotada a «su» reserva (16/10/2026; varias reservas por
 * hilo desde el 21/10/2026).
 *
 * ### La regla
 *
 * - **Un huésped**: una de las de SU conversación. La de por defecto es la que está en curso o es
 *   la próxima (`EnlacesDeConversacion::asuntoEnCurso()`, que ya es `contextoId()`); otra, sólo
 *   nombrándola por su clave (`asunto: "R2"`) de las que le lista el contexto. Un UUID o un
 *   localizador escrito no le abren la casita de otro: la clave sólo existe para este hilo.
 * - **El equipo**: la que nombre (`reserva_id`); si no nombra ninguna, la de la conversación.
 *
 * ### Por qué hacía falta
 *
 * Las tres skills de consulta hacían `$actor->contextoId() ?? $parametro` sin mirar el TIPO del
 * contexto. El equipo escribe por WhatsApp en su hilo de avisos, cuyo contexto es
 * `staff:<uuid del operador>` (`AvisoAlEquipoService`): el «contexto» era el UUID de una persona, el
 * parámetro se ignoraba y la consulta moría en «no encuentro la reserva».
 *
 * Y un hilo puede colgar de varias reservas (un huésped que repite). Sin poder elegir, el agente
 * sólo conocía una; con un parámetro libre, el modelo podía nombrar cualquiera. La clave del
 * asunto cierra las dos cosas. Ver `docs/Mensajeria.md` §11, «Varias reservas en un hilo».
 */
final class ReservaDeLaConsulta
{
    /** El parámetro con el que el modelo elige asunto. */
    public const string PARAMETRO = 'asunto';

    /** La definición, igual en todas las skills que lo aceptan. */
    public static function parametro(): SkillParameter
    {
        return SkillParameter::texto(
            self::PARAMETRO,
            'Sólo si el contexto te lista VARIOS asuntos de esta persona (R1, R2…) y habla de uno que '
            . 'no es el de por defecto: su clave, tal cual («R2»). Si sólo tiene uno, o habla del de por '
            . 'defecto, no lo pases.',
            false,
        );
    }

    /**
     * La clave que pasó el modelo, normalizada («r2 » → «R2»), o `''`.
     *
     * @param array<string, mixed> $entrada
     */
    public static function clave(array $entrada): string
    {
        return strtoupper(trim((new EntradaDeSkill($entrada))->texto(self::PARAMETRO)));
    }

    /**
     * El error, si el modelo nombró un asunto que no es de este hilo. `null` si no nombró ninguno
     * o nombró uno válido. Va lo PRIMERO de `ejecutar()`: antes de leer o escribir nada.
     *
     * @param array<string, mixed> $entrada
     */
    public static function noPermitido(ActorInterface $actor, array $entrada): ?SkillResult
    {
        $clave = self::clave($entrada);

        if ($clave === '' || isset($actor->asuntosDelHilo()[$clave])) {
            return null;
        }

        $validas = array_keys($actor->asuntosDelHilo());

        return SkillResult::error(
            $validas === []
                ? sprintf('«%s» no existe: esta conversación tiene una sola reserva. Vuelve a llamarme sin «%s».', $clave, self::PARAMETRO)
                : sprintf('«%s» no es ninguno de los asuntos de esta conversación (%s). Usa una de esas claves, o ninguna para el de por defecto.', $clave, implode(', ', $validas))
        );
    }

    /**
     * La reserva atada a la conversación, si el contexto ES una reserva: la del asunto nombrado
     * (si es de este hilo) o la de por defecto. Comprueba antes con {@see self::noPermitido()}:
     * aquí una clave ajena cae en la de por defecto, no en un error.
     */
    public static function contexto(ActorInterface $actor, string $clave = ''): ?string
    {
        if ($actor->contextoTipo() !== 'pms_reserva') {
            return null;
        }

        $asunto = $actor->asuntosDelHilo()[strtoupper(trim($clave))] ?? null;

        if ($asunto !== null && $asunto->contextType === 'pms_reserva') {
            return $asunto->contextId;
        }

        return $actor->contextoId();
    }

    /** El id con el que trabajar (puede ser `''`): ver la regla en la cabecera. */
    public static function de(ActorInterface $actor, string $parametro, string $clave = ''): string
    {
        if ($actor->esDelEquipo() && $parametro !== '') {
            return $parametro;
        }

        return self::contexto($actor, $clave) ?? ($actor->esDelEquipo() ? $parametro : '');
    }
}
