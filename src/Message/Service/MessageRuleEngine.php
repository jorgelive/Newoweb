<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageRule;
use App\Message\Entity\WhatsappMetaSendQueue;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Motor centralizado para la programación, actualización y cancelación de mensajes automáticos.
 * Utiliza heurísticas de tiempo y triggers de contexto para evitar spam a reservas históricas
 * y resolver casos de reservas de última hora (Last-Minute Bookings).
 */
final readonly class MessageRuleEngine
{
    public const string TRIGGER_INSERT = 'insert';
    public const string TRIGGER_UPDATE = 'update';
    public const string TRIGGER_COMMAND = 'command';

    public function __construct(
        private EntityManagerInterface $em,
        private MessageDispatcher $dispatcher,
        private LoggerInterface $logger
    ) {}

    /**
     * Evalúa todas las reglas activas contra una conversación y orquesta su ciclo de vida.
     * Incluye protección contra el "Time Machine Bug" (cálculos en el pasado lejano).
     *
     * @param MessageConversation $conversation La conversación objetivo.
     * @param string $trigger El origen de la ejecución (insert, update, command).
     * @param bool $force Si es true, ignora las protecciones preventivas de actualización.
     */
    public function syncConversationRules(
        MessageConversation $conversation,
        string $trigger = self::TRIGGER_UPDATE,
        bool $force = false
    ): void {
        $rules = $this->em->getRepository(MessageRule::class)->findBy([
            'isActive' => true,
            'contextType' => $conversation->getContextType()
        ]);

        $now = new DateTimeImmutable();

        // Tolerancia máxima de caducidad. Si un mensaje debió enviarse antes de este límite, se considera caducado.
        $pastThreshold = $now->modify('-2 hours');

        foreach ($rules as $rule) {
            $runAt = $this->calculateRunAt($rule, $conversation);
            $applies = $this->ruleAppliesToConversation($rule, $conversation);

            $existingMessage = $this->findExistingSystemMessage($conversation, $rule);

            // Escenario 1: La regla aplica a nivel de filtros y fechas
            if ($applies && $runAt !== null) {
                if ($existingMessage !== null) {
                    // Ya existe: Solo actualizamos fechas en caso de cambios en el PMS
                    $this->updatePendingQueues($existingMessage, $runAt);
                } else {
                    // No existe: Evaluamos si debemos programarlo por primera vez

                    // 🔥 @TODO: RESTAURAR BLINDAJE LUEGO DEL CATCH-UP.
                    // Evita que un simple UPDATE en una reserva vieja dispare correos de Bienvenida.
                    /*
                    if (
                        $rule->getMilestone() === ConversationMilestoneInterface::CREATED
                        && $trigger === self::TRIGGER_UPDATE
                        && !$force
                    ) {
                        $this->logger->info("Omisión preventiva: Regla CREATED ignorada en actualización normal para {$conversation->getGuestName()}");
                        continue;
                    }
                    */

                    if ($runAt > $pastThreshold) {
                        // Flujo regular: El mensaje está en el futuro o acaba de cumplirse
                        $this->createNewScheduledMessage($conversation, $rule, $runAt);
                    } else {
                        // Flujo de Rescate (Last-Minute Booking):
                        // Si la regla caducó, pero la reserva es literalmente NUEVA (Insert),
                        // adelantamos el envío a AHORA MISMO para que el huésped reciba la información vital.
                        if ($trigger === self::TRIGGER_INSERT) {
                            $this->logger->info(sprintf(
                                "Last-Minute Catch: Forzando envío inmediato de regla atrasada para nueva reserva de %s",
                                $conversation->getGuestName()
                            ));
                            $this->createNewScheduledMessage($conversation, $rule, clone $now);
                        } else {
                            // Si caducó y es un Update, la descartamos silenciosamente
                            $this->logger->info(sprintf(
                                "Regla omitida para huésped (%s). La fecha calculada (%s) caducó respecto a la ventana de 2 horas.",
                                $conversation->getGuestName(),
                                $runAt->format('Y-m-d H:i')
                            ));
                        }
                    }
                }
            }
            // Escenario 2: La regla ya no aplica (El Hito pasó, se canceló la reserva, etc.)
            else {
                if ($existingMessage !== null) {
                    $this->cancelPendingQueues($existingMessage);
                }
            }
        }

        $this->em->flush();
    }

    /**
     * Valida si el estado, el contexto, el origen, las agencias y los hitos cronológicos
     * de la reserva permiten la ejecución de la regla.
     */
    private function ruleAppliesToConversation(MessageRule $rule, MessageConversation $conversation): bool
    {
        // ⛔ BARRERA: No procesar reglas en chats que ya terminaron su ciclo (cerrados) o fueron cancelados (archivados)
        if (in_array($conversation->getStatus(), [MessageConversation::STATUS_ARCHIVED, MessageConversation::STATUS_CLOSED], true)) {
            return false;
        }

        if ($rule->getContextType() !== $conversation->getContextType()) {
            return false;
        }

        $allowedSources = $rule->getAllowedSources();
        if (!empty($allowedSources)) {
            $origin = $conversation->getContextOrigin();
            if ($origin === null || !in_array($origin, $allowedSources, true)) {
                return false;
            }
        }

        $allowedAgencies = $rule->getAllowedAgencies();
        if (!empty($allowedAgencies)) {
            $contextData = $conversation->getContextData() ?? [];
            $agencyId = $contextData['agency'] ?? $contextData['agency_id'] ?? null;
            if ($agencyId === null || !in_array((string)$agencyId, $allowedAgencies, true)) {
                return false;
            }
        }

        // =========================================================================
        // VALIDACIÓN DE HITOS CRONOLÓGICOS (Milestones)
        // =========================================================================
        $milestones = $conversation->getContextMilestones();
        $today = clone (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->setTime(0, 0, 0);

        switch ($rule->getMilestone()) {
            case ConversationMilestoneInterface::START:
                $startStr = $milestones[ConversationMilestoneInterface::START] ?? null;
                if (!$startStr) return false;

                $startDate = (new DateTimeImmutable($startStr))->setTime(0, 0, 0);
                if ($startDate < $today) {
                    return false; // La llegada ya pasó
                }
                break;

            case ConversationMilestoneInterface::END:
                $endStr = $milestones[ConversationMilestoneInterface::END] ?? null;
                if (!$endStr) return false;

                $endDate = (new DateTimeImmutable($endStr))->setTime(0, 0, 0);
                if ($endDate < $today) {
                    return false; // La salida ya pasó
                }
                break;

            case ConversationMilestoneInterface::CREATED:
                $startStr = $milestones[ConversationMilestoneInterface::START] ?? null;
                $createdStr = $milestones[ConversationMilestoneInterface::CREATED] ?? null;

                if (!$startStr) return false;

                $startDate = (new DateTimeImmutable($startStr))->setTime(0, 0, 0);
                $createdDate = $createdStr ? (new DateTimeImmutable($createdStr))->setTime(0, 0, 0) : clone $today;

                // 🔥 @TODO: ELIMINAR ESTA HEURÍSTICA LUEGO DEL CATCH-UP.
                // Restringe correos de bienvenida en reservas antiguas a menos que falten +21 días para la llegada.
                if ($createdDate < clone $today->modify('-1 day')) {
                    $threeWeeksFromNow = clone $today->modify('+21 days');
                    if ($startDate < $threeWeeksFromNow) {
                        return false;
                    }
                }
                break;
        }

        return true;
    }

    /**
     * Proyecta la fecha matemática exacta de envío aplicando el offset de la regla al hito.
     */
    private function calculateRunAt(MessageRule $rule, MessageConversation $conversation): ?DateTimeImmutable
    {
        $milestones = $conversation->getContextMilestones();
        $baseDateString = $milestones[$rule->getMilestone()] ?? null;

        if (!$baseDateString) {
            return null;
        }

        try {
            $baseDate = new DateTimeImmutable($baseDateString);
            $modifier = sprintf('%+d minutes', $rule->getOffsetMinutes());
            return $baseDate->modify($modifier);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Localiza un mensaje generado previamente por el sistema asociado a la misma plantilla.
     */
    private function findExistingSystemMessage(MessageConversation $conversation, MessageRule $rule): ?Message
    {
        $ruleTemplateId = (string) $rule->getTemplate()->getId();

        foreach ($conversation->getMessages() as $msg) {
            if ($msg->getTemplate() !== null
                && (string) $msg->getTemplate()->getId() === $ruleTemplateId
                && $msg->getSenderType() === Message::SENDER_SYSTEM) {
                return $msg;
            }
        }

        return null;
    }

    /**
     * Sincroniza la nueva fecha de ejecución en todas las colas pendientes asociadas al mensaje.
     */
    private function updatePendingQueues(Message $message, DateTimeImmutable $newRunAt): void
    {
        $updated = false;

        foreach ($message->getBeds24SendQueues() as $queue) {
            if ($queue->getStatus() === Beds24SendQueue::STATUS_PENDING && $queue->getRunAt() != $newRunAt) {
                $queue->setRunAt($newRunAt);
                $updated = true;
            }
        }

        foreach ($message->getWhatsappMetaSendQueues() as $queue) {
            if ($queue->getStatus() === WhatsappMetaSendQueue::STATUS_PENDING && $queue->getRunAt() != $newRunAt) {
                $queue->setRunAt($newRunAt);
                $updated = true;
            }
        }

        if ($updated) {
            $this->logger->info("Reprogramadas colas del mensaje {$message->getId()} para {$newRunAt->format('Y-m-d H:i')}");
        }
    }

    /**
     * Cancela e invalida todas las tareas de envío pendientes asociadas al mensaje.
     */
    private function cancelPendingQueues(Message $message): void
    {
        foreach ($message->getBeds24SendQueues() as $queue) {
            if ($queue->getStatus() === Beds24SendQueue::STATUS_PENDING) {
                $queue->setStatus(Beds24SendQueue::STATUS_CANCELLED);
                $queue->setRunAt(null);
            }
        }

        foreach ($message->getWhatsappMetaSendQueues() as $queue) {
            if ($queue->getStatus() === WhatsappMetaSendQueue::STATUS_PENDING) {
                $queue->setStatus(WhatsappMetaSendQueue::STATUS_CANCELLED);
                $queue->setRunAt(null);
            }
        }
    }

    /**
     * Genera una nueva instancia de Mensaje Automático para ser procesada por el Dispatcher.
     */
    private function createNewScheduledMessage(MessageConversation $conversation, MessageRule $rule, DateTimeImmutable $runAt): void
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setTemplate($rule->getTemplate());
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setSenderType(Message::SENDER_SYSTEM);
        $message->setStatus(Message::STATUS_PENDING);

        // Puntero temporal en memoria para informar al MessageDispatcher
        $message->setScheduledAt($runAt);

        // Mapeo de canales destino configurados en la regla
        $channelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();
        $message->setTransientChannels($channelIds);

        $conversation->addMessage($message);
        $this->em->persist($message);

        $this->logger->info("Programado nuevo mensaje automático para {$conversation->getGuestName()} a las {$runAt->format('Y-m-d H:i')}");
    }
}