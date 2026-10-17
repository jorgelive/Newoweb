<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Access\ActorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * El catálogo de skills, y quién puede usar cada una.
 *
 * 🔑 Lo que un actor no puede usar **ni se le menciona al modelo**. No es que lo pida y se le
 * deniegue: es que no existe en su contexto. Un modelo no puede invocar algo que no sabe que
 * hay — y de paso se ahorran los tokens de su definición.
 *
 * Es dominio puro: no sabe de proveedores. El adaptador de cada motor consulta este registro
 * y traduce lo que reciba.
 */
final readonly class SkillRegistry
{
    /**
     * @param iterable<SkillInterface> $skills
     */
    public function __construct(
        #[AutowireIterator('app.agent_skill')]
        private iterable $skills
    ) {}

    /**
     * @return list<SkillInterface>
     */
    public function paraActor(ActorInterface $actor, bool $incluirEscritura = true): array
    {
        $permitidas = [];

        foreach ($this->skills as $skill) {
            if (self::motivoDeExclusion($skill, $actor) !== null) {
                continue;
            }

            // Ojo: NO es «deja fuera todo lo que escriba». `NivelRiesgo::Interna` escribe
            // —avisa al equipo, marca la conversación— y sobrevive a este filtro a propósito:
            // sin ella el chat del huésped se quedaba sin la única herramienta que le sirve
            // para pedir ayuda. Ver NivelRiesgo::exigePermisoDeEscritura().
            if (!$incluirEscritura && $skill->nivelRiesgo()->exigePermisoDeEscritura()) {
                continue;
            }

            $permitidas[] = $skill;
        }

        return $permitidas;
    }

    /**
     * Por qué esta skill NO es para este actor, o `null` si lo es. La política entera del
     * catálogo salvo la escritura, que depende del canal y la decide quien llama.
     *
     * Pública y estática porque la usa también `GuardiaDeSkills`, el último cierre al ejecutar: sólo
     * miraba los roles, así que por la CLI (`app:agent:skill`) se ejecutaba una skill apagada, de
     * otro negocio o de dinero en una consulta de OTA (revisión del 16/10/2026). Una sola política
     * en vez de dos que divergen.
     */
    public static function motivoDeExclusion(SkillInterface $skill, ActorInterface $actor): ?string
    {
        // Apagada por configuración: no existe para nadie, ni siquiera para un SUPER_ADMIN. Va
        // ANTES que los roles porque no es una cuestión de permisos — la skill no puede funcionar,
        // se tengan los que se tengan.
        if ($skill instanceof SkillConmutableInterface && !$skill->estaActiva()) {
            return sprintf('"%s" está desactivada.', $skill->nombre());
        }

        // De otro NEGOCIO: no existe para este actor. Va antes que los roles por el mismo motivo
        // — «esta herramienta no es de lo tuyo» es previo a «no tienes permiso», y así el motivo
        // que quede en el log es el de verdad.
        //
        // El equipo se lo salta entero: quien atiende alojamiento y tours por el mismo WhatsApp
        // interno necesita las dos cajas a la vez, y qué puede tocar de cada una ya lo deciden
        // sus roles.
        if (!$actor->esDelEquipo() && !self::esDelDominioDe($skill, $actor)) {
            return sprintf('"%s" no es de este negocio.', $skill->nombre());
        }

        if (!$actor->tieneAlguno($skill->rolesRequeridos())) {
            return sprintf(
                'No tienes permiso para usar "%s". Hace falta uno de estos roles: %s.',
                $skill->nombre(),
                implode(', ', $skill->rolesRequeridos())
            );
        }

        // 💵 En una consulta de OTA el precio lo pone la plataforma: las herramientas de dinero no
        // existen para ese actor. Ver RestriccionCanal::ocultaImportes().
        if ($skill instanceof SkillDeImportesInterface && $actor->restriccion()->ocultaImportes()) {
            return sprintf('"%s" maneja importes y aquí no se dan.', $skill->nombre());
        }

        // Sin el contexto que necesita —una reserva, un hilo— no puede funcionar.
        if ($skill instanceof SkillConContextoInterface && !$skill->disponiblePara($actor)) {
            return sprintf('"%s" necesita una conversación que aquí no hay.', $skill->nombre());
        }

        return null;
    }

    /**
     * ¿Esta skill es de algún negocio al que llegue el actor?
     *
     * **Los dos vacíos dicen «no acotes», y ninguno dice «no».** Una skill sin
     * {@see SkillDominioInterface} es transversal —escalar al equipo, el tipo de cambio— y vale
     * para todos; un actor con `dominios()` vacío es uno que todavía nadie ha poblado, y se
     * comporta como antes de que existieran los dominios.
     *
     * Esa doble permisividad es deliberada: mientras el modelo de frentes se termina de
     * enchufar, un olvido deja de más en el catálogo —inofensivo, porque el dominio no autoriza
     * nada— en vez de dejar a alguien sin herramientas en silencio, que es el fallo que no se
     * ve hasta que un cliente se queja de que el bot no sabe nada.
     */
    private static function esDelDominioDe(SkillInterface $skill, ActorInterface $actor): bool
    {
        if (!$skill instanceof SkillDominioInterface) {
            return true;
        }

        $deLaSkill = $skill->dominios();
        $delActor = $actor->dominios();

        if ($deLaSkill === [] || $delActor === []) {
            return true;
        }

        return array_intersect($deLaSkill, $delActor) !== [];
    }

    public function buscar(string $nombre): ?SkillInterface
    {
        foreach ($this->skills as $skill) {
            if ($skill->nombre() === $nombre) {
                return $skill;
            }
        }

        return null;
    }

    /** @return list<SkillInterface> */
    public function todas(): array
    {
        return iterator_to_array($this->skills, false);
    }
}
