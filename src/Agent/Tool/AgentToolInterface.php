<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una capacidad que el agente puede usar.
 *
 * Añadir una herramienta es **crear una clase que implemente esto**: el tag la registra sola
 * y ni el asistente del panel ni el del chat se tocan. Es el mismo patrón que
 * `ChannelEnqueuerInterface` para los canales de envío.
 *
 * Ver docs/Mensajeria.md §12.
 */
#[AutoconfigureTag('app.agent_tool')]
interface AgentToolInterface
{
    /**
     * Identificador estable. Es lo que el modelo escribe al invocarla, así que cambiarlo
     * invalida el prompt cacheado: trátalo como un contrato.
     */
    public function nombre(): string;

    /**
     * Lo que ve el modelo: `description` e `inputSchema`.
     *
     * 🔥 La descripción ES prompt, no documentación. Di **cuándo** usarla, no sólo qué hace,
     * y prohíbe explícitamente responder de memoria si el dato debe salir de aquí. Eso es lo
     * que sube la tasa de llamada.
     *
     * @return array{description: string, inputSchema: array<string, mixed>}
     */
    public function definicion(): array;

    /**
     * @param array<string, mixed> $entrada Lo que el modelo rellenó según el esquema.
     * @return string JSON. Un error de negocio se DEVUELVE como `{"error": "..."}` en vez de
     *                lanzarse: así el modelo puede reformular o pedir la aclaración que falta,
     *                en lugar de romper el turno.
     */
    public function ejecutar(array $entrada, AgentActor $actor): string;

    /** @return list<string> Roles que la habilitan. Vacío ⇒ cualquier actor. */
    public function rolesRequeridos(): array;

    public function nivelRiesgo(): NivelRiesgo;
}
