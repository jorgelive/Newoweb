<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageRule;
use App\Message\Entity\WhatsappGupshupSendQueue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MessageRuleEngine
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageDispatcher $dispatcher,
        private LoggerInterface $logger
    ) {}

    /**
     * Sincroniza todas las reglas de mensajería para una conversación.
     * Crea mensajes nuevos, actualiza fechas de envío o cancela colas pendientes según corresponda.
     */
    public function syncConversationRules(MessageConversation $conversation): void
    {
        // Traemos las reglas pre-filtradas por contexto y estado activo
        $rules = $this->em->getRepository(MessageRule::class)->findBy([
            'isActive' => true,
            'contextType' => $conversation->getContextType()
        ]);

        foreach ($rules as $rule) {
            $runAt = $this->calculateRunAt($rule, $conversation);
            $applies = $this->ruleAppliesToConversation($rule, $conversation);

            $existingMessage = $this->findExistingSystemMessage($conversation, $rule);

            // Escenario 1: La regla aplica y tenemos una fecha válida (Reserva Activa y Cumple Filtros)
            if ($applies && $runAt !== null) {
                if ($existingMessage !== null) {
                    // 1A. Ya existe: Reprogramamos las colas pendientes (por si las fechas del PMS cambiaron)
                    $this->updatePendingQueues($existingMessage, $runAt);
                } else {
                    // 1B. No existe: Lo creamos desde cero
                    $this->createNewScheduledMessage($conversation, $rule, $runAt);
                }
            }
            // Escenario 2: La regla ya no aplica (Archivada, Cambio de OTA, etc.)
            else {
                if ($existingMessage !== null) {
                    // Cancelamos las colas pendientes físicamente para que no salgan de la BD
                    $this->cancelPendingQueues($existingMessage);
                }
            }
        }

        // Persistimos todos los cambios orquestados
        $this->em->flush();
    }

    /**
     * Verifica estrictamente si la conversación cumple con todos los filtros de la regla.
     */
    private function ruleAppliesToConversation(MessageRule $rule, MessageConversation $conversation): bool
    {
        // 1. REGLA DE NEGOCIO MAESTRA: ESTADO
        // Si la conversación está Archivada (Reserva Cancelada), ninguna regla regular aplica.
        if ($conversation->getStatus() === MessageConversation::STATUS_ARCHIVED) {
            return false;
        }

        // 2. FILTRO ESTRICTO DE CONTEXTO (Defensivo)
        if ($rule->getContextType() !== $conversation->getContextType()) {
            return false;
        }

        // 3. FILTRO DE OTAs / FUENTES (Allowed Sources)
        // Ejemplo JSON: ["booking", "airbnb"]
        $allowedSources = $rule->getAllowedSources();
        if (!empty($allowedSources)) {
            $origin = $conversation->getContextOrigin(); // Ej: "booking"
            if ($origin === null || !in_array($origin, $allowedSources, true)) {
                return false;
            }
        }

        // 4. FILTRO DE AGENCIAS B2B (Allowed Agencies)
        // Buscamos la llave 'agency' o 'agency_id' en la metadata de la conversación
        $allowedAgencies = $rule->getAllowedAgencies();
        if (!empty($allowedAgencies)) {
            $contextData = $conversation->getContextData() ?? [];
            $agencyId = $contextData['agency'] ?? $contextData['agency_id'] ?? null;

            // Casteamos a string para asegurar compatibilidad en el in_array (ej: ID "152" vs entero 152)
            if ($agencyId === null || !in_array((string)$agencyId, $allowedAgencies, true)) {
                return false;
            }
        }

        return true; // Pasó todos los filtros exitosamente
    }

    /**
     * Calcula la fecha exacta en base a los milestones de la reserva y el desfase de la regla.
     */
    private function calculateRunAt(MessageRule $rule, MessageConversation $conversation): ?\DateTimeImmutable
    {
        $milestones = $conversation->getContextMilestones();
        $baseDateString = $milestones[$rule->getMilestone()] ?? null;

        if (!$baseDateString) {
            return null;
        }

        try {
            $baseDate = new \DateTimeImmutable($baseDateString);
            $modifier = sprintf('%+d minutes', $rule->getOffsetMinutes());
            return $baseDate->modify($modifier);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Busca si ya generamos previamente un mensaje automático para esta regla específica.
     */
    private function findExistingSystemMessage(MessageConversation $conversation, MessageRule $rule): ?Message
    {
        // Forzamos la conversión a string para comparar los valores reales del UUID
        $ruleTemplateId = (string) $rule->getTemplate()->getId();

        foreach ($conversation->getMessages() as $msg) {
            if ($msg->getTemplate() !== null
                && (string) $msg->getTemplate()->getId() === $ruleTemplateId
                && $msg->getSenderType() === Message::SENDER_SYSTEM) {
                return $msg; // ¡Ya existe! Lo devolvemos para actualizar fechas en vez de duplicarlo
            }
        }

        return null;
    }

    /**
     * Actualiza la fecha de ejecución de las colas que AÚN NO se han enviado.
     */
    private function updatePendingQueues(Message $message, \DateTimeImmutable $newRunAt): void
    {
        $updated = false;

        foreach ($message->getBeds24SendQueues() as $queue) {
            if ($queue->getStatus() === Beds24SendQueue::STATUS_PENDING && $queue->getRunAt() != $newRunAt) {
                $queue->setRunAt($newRunAt);
                $updated = true;
            }
        }

        foreach ($message->getWhatsappGupshupSendQueues() as $queue) {
            if ($queue->getStatus() === WhatsappGupshupSendQueue::STATUS_PENDING && $queue->getRunAt() != $newRunAt) {
                $queue->setRunAt($newRunAt);
                $updated = true;
            }
        }

        if ($updated) {
            $this->logger->info("Reprogramadas colas del mensaje {$message->getId()} para {$newRunAt->format('Y-m-d H:i')}");
        }
    }

    /**
     * Cancela físicamente las colas pendientes (útil cuando se archiva una conversación o cambian los filtros).
     */
    private function cancelPendingQueues(Message $message): void
    {
        foreach ($message->getBeds24SendQueues() as $queue) {
            if ($queue->getStatus() === Beds24SendQueue::STATUS_PENDING) {
                $queue->setStatus(Beds24SendQueue::STATUS_CANCELLED);
                $queue->setRunAt(null);
            }
        }

        foreach ($message->getWhatsappGupshupSendQueues() as $queue) {
            if ($queue->getStatus() === WhatsappGupshupSendQueue::STATUS_PENDING) {
                $queue->setStatus(WhatsappGupshupSendQueue::STATUS_CANCELLED);
                $queue->setRunAt(null);
            }
        }
    }

    /**
     * Ensambla el nuevo mensaje y usa el Dispatcher para generar las colas.
     */
    private function createNewScheduledMessage(MessageConversation $conversation, MessageRule $rule, \DateTimeImmutable $runAt): void
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setTemplate($rule->getTemplate());
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setSenderType(Message::SENDER_SYSTEM); // Indicador de que es un mensaje automático
        $message->setStatus(Message::STATUS_PENDING);

        // Puntero en memoria para el Dispatcher
        $message->setScheduledAt($runAt);

        // Canales destino configurados en la regla
        $channelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();
        $message->setTransientChannels($channelIds);

        $conversation->addMessage($message);
        $this->em->persist($message);

        // La cola se crea normalmente

        $this->logger->info("Programado nuevo mensaje automático para {$conversation->getGuestName()} a las {$runAt->format('Y-m-d H:i')}");
    }
}