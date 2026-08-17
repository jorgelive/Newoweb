<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Agent\Skill\SkillInterface;

/**
 * El último filtro antes de ejecutar una skill. **Aquí se cierra, no se pide.**
 *
 * ### Por qué hace falta si ya filtra el registro
 *
 * {@see \App\Agent\Skill\SkillRegistry::paraActor()} decide **qué se le OFRECE** al modelo.
 * Esto decide **qué se le PERMITE ejecutar**, y no son la misma pregunta:
 *
 * - El catálogo se arma una vez por turno. Si algo lo ensancha —un canal que pasa
 *   `permitirEscritura: true` de más, una skill que se cuela por un dominio mal declarado—,
 *   no hay nada detrás.
 * - Un modelo puede inventarse el nombre de una herramienta que no se le ofreció. Hoy los
 *   adaptadores buscan por nombre en la lista permitida, así que no cuela; pero eso es una
 *   propiedad de dos archivos que alguien puede cambiar sin darse cuenta de que era el cierre.
 *
 * La regla de la casa aplicada al pie de la letra: **el prompt es una petición, el cierre es
 * código**. Que la descripción de una skill diga «pide confirmación» no impide nada.
 *
 * ### Qué NO hace, todavía
 *
 * ⚠️ **No comprueba la confirmación de escritura.** El paso «previsualiza y espera el sí» sigue
 * dependiendo de que el modelo llame primero con `confirmado: false`, porque nada recuerda entre
 * llamadas si hubo previsualización. Para cerrarlo de verdad haría falta guardar la
 * previsualización por conversación y exigir que el `confirmado: true` coincida con ella. No
 * está hecho. Ver {@see NivelRiesgo::Escritura} y docs/Mensajeria.md §11.
 */
final readonly class GuardiaDeSkills
{
    /**
     * ¿Por qué NO puede ejecutarse? `null` = adelante.
     *
     * Devuelve el motivo en texto y no un booleano porque el que se lo come es un modelo:
     * necesita saber qué decirle al usuario. «No puedes» a secas produce una disculpa vaga y
     * el usuario vuelve a intentarlo igual.
     */
    public function motivoDeBloqueo(SkillInterface $skill, ActorInterface $actor): ?string
    {
        // Los roles se vuelven a mirar aquí a propósito, aunque el registro ya los mirase: es
        // barato y es el único punto por el que pasa TODA ejecución, venga del catálogo o no.
        if (!$actor->tieneAlguno($skill->rolesRequeridos())) {
            return sprintf(
                'No tienes permiso para usar "%s". Hace falta uno de estos roles: %s.',
                $skill->nombre(),
                implode(', ', $skill->rolesRequeridos())
            );
        }

        return null;
    }
}
