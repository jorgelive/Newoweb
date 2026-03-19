<?php

declare(strict_types=1);

namespace App\Message\MessageHandler;

use App\Message\Dispatch\DeduplicateConversationDispatch;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Maneja la orden de despacho recibida desde el bus de mensajes.
 * Se encarga de limpiar los duplicados generados por la condición de carrera
 * entre el Worker de envío de mensajes y los Webhooks de recepción de Beds24.
 */
#[AsMessageHandler]
final readonly class DeduplicateConversationDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Invoca la lógica de deduplicación utilizando el ID proporcionado en el despacho.
     *
     * @param DeduplicateConversationDispatch $dispatch La orden de despacho con los parámetros necesarios.
     */
    public function __invoke(DeduplicateConversationDispatch $dispatch): void
    {
        $conversationId = $dispatch->conversationId;

        $conversation = $this->em->getRepository(MessageConversation::class)->find($conversationId);

        if (!$conversation instanceof MessageConversation) {
            $this->logger->warning(sprintf('GC Deduplicación: Conversación %s no encontrada.', $conversationId));
            return;
        }

        $cleanedCount = $this->deduplicateConversation($conversation);

        if ($cleanedCount > 0) {
            $this->em->flush();
            $this->logger->info(sprintf('GC Deduplicación: Se eliminaron %d mensajes huérfanos en la conversación %s.', $cleanedCount, $conversationId));
        }
    }

    /**
     * Compara los mensajes de una conversación específica buscando clones y los fusiona.
     * Utiliza el Timestamp nativo de los UUID v7 para una precisión de milisegundos
     * generada en el mismo servidor, eliminando desincronizaciones externas.
     */
    private function deduplicateConversation(MessageConversation $conversation): int
    {
        $locals = [];
        $officials = [];
        $cleaned = 0;

        foreach ($conversation->getMessages() as $msg) {
            if ($msg->getDirection() !== Message::DIRECTION_OUTGOING) {
                continue;
            }

            // Local: El que creamos nosotros (con plantilla)
            if ($msg->getTemplate() !== null && $msg->getBeds24ExternalId() === null) {
                $locals[] = $msg;
            }
            // Oficial: El que trajo el webhook (con ID externo)
            elseif ($msg->getBeds24ExternalId() !== null && $msg->getTemplate() === null) {
                $officials[] = $msg;
            }
        }

        if (empty($locals) || empty($officials)) {
            return 0;
        }

        foreach ($locals as $local) {
            $localText = preg_replace('/\s+/', '', trim($local->getContentExternal() ?? $local->getContentLocal() ?? ''));
            $localExtId = $local->getBeds24ExternalId();

            // 🔥 Timestamp absoluto del UUID v7 local (nuestro servidor)
            $localTime = (float) $local->getId()->getDateTime()->format('U.u');
            
            foreach ($officials as $index => $official) {
                $officialExtId = $official->getBeds24ExternalId();
                $officialText = preg_replace('/\s+/', '', trim($official->getContentExternal() ?? ''));

                // 🔥 Timestamp absoluto del UUID v7 del webhook (nuestro servidor)
                $officialTime = (float) $official->getId()->getDateTime()->format('U.u');

                /**
                 * REGLA CRONOLÓGICA UNIDIRECCIONAL
                 * El oficial SIEMPRE debe nacer después del local.
                 * abs() es peligroso aquí; preferimos la resta directa para asegurar orden.
                 */
                $diffSeconds = $officialTime - $localTime;

                // 1. Match por ID Externo (Si el worker de envío tuvo éxito)
                $isIdMatch = ($localExtId !== null && $localExtId === $officialExtId);

                // 2. Match por Texto + Ventana de Tiempo (Si el worker falló)
                // Tolerancia de 0 a 60 segundos de viaje completo.
                $isChronologicallyValid = ($diffSeconds >= 0 && $diffSeconds <= 60);
                $isTextMatch = ($localText !== '' && $localText === $officialText) && $isChronologicallyValid;

                // 3. Match por Tiempo Estricto (Respaldo si la API alteró el texto)
                $isStrictTimeMatch = false;
                if (!$isIdMatch && !$isTextMatch) {
                    // Si el oficial entró en los siguientes 10 segundos, es el mismo.
                    $isStrictTimeMatch = ($diffSeconds >= 0 && $diffSeconds <= 10);
                }

                if ($isIdMatch || $isTextMatch || $isStrictTimeMatch) {
                    // TRASPASO DE IDENTIDAD (Inyectamos el ADN del local al oficial)
                    $official->setTemplate($local->getTemplate());
                    $official->setSenderType(Message::SENDER_SYSTEM);
                    $official->setTransientChannels($local->getTransientChannels());

                    // ELIMINACIÓN DEL LOCAL (El inestable)
                    $conversation->removeMessage($local);
                    $this->em->remove($local);

                    unset($officials[$index]); // Evitamos doble emparejamiento
                    $cleaned++;
                    break;
                }
            }
        }

        return $cleaned;
    }
}