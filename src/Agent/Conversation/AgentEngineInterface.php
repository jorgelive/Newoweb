<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * LA FRONTERA con el proveedor de IA.
 *
 * Todo lo que hay por encima —skills, permisos, actores, los dos asistentes— habla con esta
 * interfaz y no conoce ningún SDK. Todo lo que hay por debajo vive empaquetado en
 * `src/Agent/Provider/<Proveedor>/` y puede cambiar entero sin tocar el resto.
 *
 * Para qué sirve de verdad:
 *
 * - **Actualizar el SDK o la API** afecta a una carpeta, no a todo el módulo.
 * - **Convivir en paralelo**: se pueden registrar dos motores y elegir por caso de uso —el
 *   panel con uno barato, el chat del huésped con otro— sin duplicar skills ni permisos.
 *
 * Ojo con lo que NO abstrae: los **prompts**. Cambiar de motor obliga a recalibrarlos, y eso
 * no lo salva ninguna interfaz. Por eso la frontera está aquí —en la conversación completa—
 * y no en un cliente HTTP genérico: mover la frontera más abajo sólo daría portabilidad
 * aparente al precio de perder tool use, caché de prompts y control de rechazos.
 */
interface AgentEngineInterface
{
    /** Identificador del motor, para logs y para elegirlo por configuración. */
    public function nombre(): string;

    /** Nombre del proveedor tal y como se le enseña al operador en el panel. */
    public function etiqueta(): string;

    /** `false` cuando falta configuración: el sistema debe degradar, no romper. */
    public function estaDisponible(): bool;

    /**
     * Modelos que este motor acepta, en orden de preferencia.
     *
     * Es una **lista blanca**, no un catálogo informativo: lo que no esté aquí se rechaza en
     * {@see \App\Agent\Conversation\AgentEngineRegistry::validarModelo()}. Sin ella, un POST
     * al endpoint del panel podría pedir el modelo más caro del proveedor.
     *
     * @return non-empty-list<string>
     */
    public function modelos(): array;

    /** Modelo que se usa cuando la petición no pide uno. Siempre está dentro de `modelos()`. */
    public function modeloPorDefecto(): string;

    public function conversar(ConversationRequest $peticion): ConversationResponse;
}
