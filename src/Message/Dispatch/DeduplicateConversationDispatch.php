<?php

declare(strict_types=1);

namespace App\Message\Dispatch;

/**
 * DeduplicateConversationDispatch.
 * * Representa una orden de "despacho" para que el Worker procese la deduplicación
 * de una conversación específica, resolviendo la condición de carrera entre
 * los envíos locales y los webhooks entrantes.
 */
final readonly class DeduplicateConversationDispatch
{
    /**
     * @param string $conversationId El UUID (texto) de la conversación que debe ser evaluada.
     */
    public function __construct(
        public string $conversationId
    ) {}
}