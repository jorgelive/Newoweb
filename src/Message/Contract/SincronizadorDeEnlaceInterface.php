<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Entity\MessageConversation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Mantiene al día el ENLACE de un asunto cada vez que su activo cambia.
 *
 * ── El agujero que tapa ─────────────────────────────────────────────────────
 * `MessageConversationFactory::upsertFromContext()` corre en cada cambio de una reserva —lo
 * llama `PmsReservaRecalculoService`— y refresca el `contextData` de la conversación: hitos,
 * vínculo, etiqueta de estado. Pero **no sabía de enlaces**, así que:
 *
 *  - una reserva nueva creaba conversación **sin** enlace, y se quedaba en el camino legado
 *    para siempre: el modelo no crecía;
 *  - y peor, mover las fechas de una reserva que YA tenía enlace dejaba la conversación al día
 *    y **el enlace con los hitos viejos**. Como el motor, en modo enlace, lee el enlace, habría
 *    programado con fechas caducas. Con 204 enlaces poblados, ese riesgo estaba vivo.
 *
 * ── Por qué un contrato y no un `if` en la factoría ─────────────────────────
 * Porque la factoría vive en `src/Message` y el enlace del alojamiento en `src/Pms`: escribirlo
 * allí ataría la mensajería al esquema del PMS, que es la dependencia que se está quitando. Cada
 * negocio registra su sincronizador por tag y la factoría sólo pregunta quién soporta este
 * `context_type` — mismo patrón que {@see \App\Agent\Contract\InstruccionesDeDominioInterface} y
 * {@see FrentesPorDominioInterface}.
 */
#[AutoconfigureTag('app.message.sincronizador_enlace')]
interface SincronizadorDeEnlaceInterface
{
    public function supports(?string $contextType): bool;

    /**
     * Crea el enlace si falta, y lo pone al día si ya está.
     *
     * ⚠️ **Idempotente y sin flush.** Corre dentro de la misma unidad de trabajo que quien lo
     * llama —la factoría, el recálculo— y flushear aquí partiría esa transacción en dos: si lo
     * de después fallara, quedaría un enlace escrito sobre una reserva que no llegó a guardarse.
     */
    public function sincronizar(MessageConversation $conversacion, MessageContextInterface $contexto): void;
}
