<?php

declare(strict_types=1);

namespace App\Message\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Arma el contexto de un asunto **bajo demanda**, sin esperar a que su dominio lo guarde.
 *
 * ── El agujero que tapa ─────────────────────────────────────────────────────
 * Un `MessageContextInterface` es lo único que sabe crear una conversación, y hasta ahora sólo
 * se construía **dentro del guardado del dominio**: `PmsReservaRecalculoService` al recalcular
 * una reserva, `CotizacionFileConversacionListener` al guardar un expediente. Nadie podía pedir
 * uno.
 *
 * La consecuencia era que **no existía forma de abrir un hilo**. Si a un cliente se le borraba
 * la conversación —o sencillamente nunca la tuvo, como los proveedores— los caminos eran tres,
 * y ninguno es un camino:
 *
 *  1. tocar y volver a guardar la reserva, para que el listener la recreara de rebote;
 *  2. esperar a que el cliente escribiera primero;
 *  3. crear la fila a mano en EasyAdmin — que no siembra identidades, ni enlace, ni
 *     `contextData`, así que nace un hilo que no resuelve nada.
 *
 * Con esto, «escríbele a éste» es una operación de primera clase: {@see \App\Message\Service\Conversacion\AperturaDeHilo}
 * pregunta quién soporta el `contextType` y le pide el contexto ya armado.
 *
 * ── Qué NO es ───────────────────────────────────────────────────────────────
 * No sustituye a los listeners. Ellos siguen creando la conversación cuando el dominio cambia,
 * que es lo que hace que el hilo exista antes de que nadie lo pida. Esto es la puerta para
 * cuando ese camino no pasó, o pasó y se deshizo.
 *
 * ⚠️ **Es de sólo lectura sobre el dominio.** Arma un adaptador con lo que ya está guardado; no
 * crea la reserva, ni el expediente, ni la organización. Si el `contextId` no existe devuelve
 * `null` y quien llame decide qué decir.
 */
#[AutoconfigureTag('app.message.proveedor_contexto')]
interface ProveedorDeContextoInterface
{
    /** ¿Este dominio sabe armar contextos de este tipo? */
    public function supports(string $contextType): bool;

    /**
     * El contexto de este asunto, o `null` si el asunto no existe.
     *
     * ⚠️ Devolver un contexto **no** significa que se le pueda escribir: puede no tener ni
     * teléfono ni correo. Eso lo comprueba quien abre el hilo, mirando
     * `getIdentificadores()` — un hilo sin un solo identificador no resuelve a nadie y no
     * debería nacer.
     */
    public function para(string $contextId): ?MessageContextInterface;
}
