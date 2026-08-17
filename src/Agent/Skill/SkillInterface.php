<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una capacidad del agente: algo que sabe hacer.
 *
 * 🔑 **Dominio puro: aquí no entra ningún proveedor.** Una skill no conoce Anthropic, ni
 * `BetaRunnableTool`, ni JSON Schema. Describe lo que hace en términos propios y devuelve un
 * {@see SkillResult}; traducir eso a la API de turno es trabajo del adaptador del proveedor
 * (`src/Agent/Provider/`). Así una skill escrita hoy sigue valiendo cuando cambie la API, y
 * dos motores pueden convivir en paralelo sobre el mismo catálogo.
 *
 * Añadir una capacidad es **crear una clase que implemente esto**: el tag la registra sola y
 * no se toca ningún motor ni ningún adaptador. Mismo patrón que `ChannelEnqueuerInterface`
 * para los canales de envío.
 *
 * Ver docs/Mensajeria.md §11.
 */
#[AutoconfigureTag('app.agent_skill')]
interface SkillInterface
{
    /**
     * Identificador estable. Es lo que el modelo escribe al invocarla, así que cambiarlo
     * invalida el prompt cacheado: trátalo como un contrato.
     */
    public function nombre(): string;

    public function definicion(): SkillDefinition;

    /**
     * @param array<string, mixed> $entrada Lo que el modelo rellenó según la definición.
     */
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult;

    /** @return list<string> Roles que la habilitan. Vacío ⇒ cualquier actor. */
    public function rolesRequeridos(): array;

    /**
     * Cuánto daño puede hacer, que es lo que decide el trato. Ver {@see NivelRiesgo}.
     *
     * ⚠️ Si devuelves `Escritura` o `Destructivo`, la skill DEBE aceptar un parámetro
     * `confirmado` y, con `false`, devolver la previsualización sin tocar nada. Confirmar es
     * enseñarle el cambio a **quien lo pidió** y esperar su «sí» —mismo usuario, dos turnos—,
     * no pedirle permiso a un tercero: {@see NivelRiesgo::Escritura} lo explica entero.
     *
     * Hoy ese paso no lo fuerza el servidor, sólo la descripción que lee el modelo, así que
     * omitirlo no rompe nada visible: aplica a la primera y nadie se entera hasta que el dato
     * está mal.
     */
    public function nivelRiesgo(): NivelRiesgo;
}
