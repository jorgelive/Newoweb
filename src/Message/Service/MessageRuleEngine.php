<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageRule;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Motor centralizado para la programación, actualización y cancelación de mensajes automáticos.
 * DELEGA toda la infraestructura de colas al MessageEnqueuerEntityListener.
 */
final readonly class MessageRuleEngine
{
    public const string TRIGGER_INSERT = 'insert';
    public const string TRIGGER_UPDATE = 'update';
    public const string TRIGGER_COMMAND = 'command';

    /**
     * Constructor del motor de reglas.
     * * @param EntityManagerInterface $em
     * @param LoggerInterface $logger
     * @param iterable<ChannelEnqueuerInterface> $enqueuers Colección de encoladores para validar viabilidad de canales.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[TaggedIterator('app.message.enqueuer')]
        private iterable $enqueuers
    ) {}

    /**
     * Evalúa todas las reglas activas contra una conversación y orquesta su ciclo de vida.
     *
     * @param MessageConversation $conversation La conversación objetivo.
     * @param string $trigger El origen de la ejecución.
     * @param bool $force Ignora protecciones.
     */
    public function syncConversationRules(
        MessageConversation $conversation,
        string $trigger = self::TRIGGER_UPDATE,
        bool $force = false
    ): void {
        // 1. Curación Automática: Detectar y sanar mensajes zombie antes de evaluar reglas.
        $this->healZombieMessages($conversation);

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

            if ($applies && $runAt !== null) {
                if ($existingMessage !== null) {
                    $this->syncPendingMessage($existingMessage, $rule, $runAt);
                } else {

                    // =========================================================
                    // 🛡️ BLINDAJE RESTAURADO: Prevención de Spam Histórico
                    // =========================================================
                    // Evita que un simple UPDATE en una reserva vieja dispare
                    // correos de Bienvenida de forma retroactiva.
                    if (
                        $rule->getMilestone() === ConversationMilestoneInterface::CREATED
                        && $trigger === self::TRIGGER_UPDATE
                        && !$force
                    ) {
                        $this->logger->info(sprintf(
                            "Omisión preventiva: Regla CREATED ('%s') ignorada en actualización normal para %s",
                            $rule->getTemplate()->getName(),
                            $conversation->getGuestName()
                        ));
                        continue; // Saltamos a la siguiente regla
                    }
                    // =========================================================

                    if ($runAt > $pastThreshold) {
                        // Flujo regular: El mensaje está en el futuro o acaba de cumplirse
                        $this->createNewScheduledMessage($conversation, $rule, $runAt);
                    } else {
                        // Flujo de Rescate (Last-Minute Booking)
                        if ($trigger === self::TRIGGER_INSERT) {
                            $this->createNewScheduledMessage($conversation, $rule, clone $now);
                        }
                    }
                }
            } else {
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
        // BARRERA: No procesar reglas en chats que ya terminaron su ciclo (cerrados) o fueron cancelados (archivados)
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

        $milestones = $conversation->getContextMilestones();
        $today = clone (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->setTime(0, 0, 0);

        switch ($rule->getMilestone()) {
            case ConversationMilestoneInterface::START:
                $startStr = $milestones[ConversationMilestoneInterface::START] ?? null;
                if (!$startStr) return false;
                if ((new DateTimeImmutable($startStr))->setTime(0, 0, 0) < $today) return false;
                break;

            case ConversationMilestoneInterface::END:
                $endStr = $milestones[ConversationMilestoneInterface::END] ?? null;
                if (!$endStr) return false;
                if ((new DateTimeImmutable($endStr))->setTime(0, 0, 0) < $today) return false;
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

        if (!$baseDateString) return null;

        try {
            return (new DateTimeImmutable($baseDateString))->modify(sprintf('%+d minutes', $rule->getOffsetMinutes()));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Curación de Mensajes Zombie.
     * Escanea todos los mensajes de la conversación y actualiza su estado real en base a sus colas.
     * NUNCA elimina la fecha scheduledAt para preservar la línea de tiempo visual del frontend.
     */
    private function healZombieMessages(MessageConversation $conversation): void
    {
        foreach ($conversation->getMessages() as $msg) {
            if (in_array($msg->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_CANCELLED], true)) {
                $oldStatus = $msg->getStatus();
                $this->resolveMessageStatus($msg);

                if ($oldStatus !== $msg->getStatus()) {
                    $this->logger->info("Sanidad: Mensaje {$msg->getId()} corregido de {$oldStatus} a {$msg->getStatus()}.");
                }
            }
        }
    }

    /**
     * Localiza un mensaje generado previamente por el sistema asociado a la misma plantilla.
     * Se usa casting estricto a (string) para evitar la fragilidad de buscar por UUID u objetos Doctrine.
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
     * Sincroniza la nueva fecha y realiza una "Poda de Canales" reactiva.
     * Si un canal de la regla ya no es válido para el contexto actual (ej. Beds24 en Directo),
     * se elimina de los transientChannels, lo que disparará la cancelación en el Listener.
     *
     * @param Message $message
     * @param MessageRule $rule
     * @param DateTimeImmutable $newRunAt
     */
    private function syncPendingMessage(Message $message, MessageRule $rule, DateTimeImmutable $newRunAt): void
    {
        // CORTAFUEGOS DE ESTADOS TERMINALES (Inmutabilidad estricta)
        if (in_array($message->getStatus(), [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ], true)) {
            return;
        }

        // 1. Obtenemos los canales que la REGLA quiere usar
        $targetChannelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();

        // 2. 🔥 FILTRO DE SEGURIDAD: Validamos cada canal contra su Enqueuer
        $validChannelIds = [];
        foreach ($targetChannelIds as $channelId) {
            foreach ($this->enqueuers as $enqueuer) {
                // Buscamos el enqueuer que soporta este canal
                if ($enqueuer->supports($this->em->getReference(MessageChannel::class, $channelId))) {
                    if ($enqueuer->isValid($message)) {
                        $validChannelIds[] = $channelId;
                    } else {
                        $this->logger->info("Regla Engine: Canal '$channelId' invalidado por el Enqueuer para el mensaje {$message->getId()}");
                    }
                    break;
                }
            }
        }

        // 3. Actualizamos los canales temporales con la lista filtrada
        // Si 'beds24' estaba en la lista pero ahora se quitó, el Listener cancelará su cola.
        $message->setTransientChannels($validChannelIds);

        // 4. Si no quedan canales válidos, cancelamos el mensaje padre completamente
        if (empty($validChannelIds)) {
            $this->cancelPendingQueues($message);
            return;
        }

        if (in_array($message->getStatus(), [Message::STATUS_QUEUED, Message::STATUS_PENDING], true)) {
            if ($message->getScheduledAt() != $newRunAt) {
                $message->setScheduledAt($newRunAt);
            }
        }

        $this->resolveMessageStatus($message);
    }

    /**
     * Marca el mensaje como cancelado. El Listener propagará esto a las colas.
     *
     * @param Message $message
     */
    private function cancelPendingQueues(Message $message): void
    {
        if (in_array($message->getStatus(), [Message::STATUS_QUEUED, Message::STATUS_PENDING], true)) {
            $message->setStatus(Message::STATUS_CANCELLED);
        }
    }

    private function createNewScheduledMessage(MessageConversation $conversation, MessageRule $rule, DateTimeImmutable $runAt): void
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setTemplate($rule->getTemplate());
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setSenderType(Message::SENDER_SYSTEM);
        $message->setStatus(Message::STATUS_QUEUED);

        $message->setScheduledAt($runAt);

        // Mapeo de canales destino configurados en la regla
        $channelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();
        $message->setTransientChannels($channelIds);

        $conversation->addMessage($message);
        $this->em->persist($message);

        $this->logger->info("Programado nuevo mensaje automático para {$conversation->getGuestName()} a las {$runAt->format('Y-m-d H:i')}");
    }

    /**
     * Evalúa la realidad física de las colas para dictaminar el estado real del mensaje raíz.
     * Ahora es 100% agnóstico a los canales, delegando la recolección a la entidad Message.
     */
    private function resolveMessageStatus(Message $message): void
    {
        $hasPending = $hasSuccess = $hasFailed = false;

        // Iteramos de forma agnóstica usando la interfaz
        foreach ($message->getAllQueues() as $q) {
            $s = $q->getStatus();

            if ($s === 'pending') {
                $hasPending = true;
            }
            if (in_array($s, ['success', 'sent', 'delivered'], true)) {
                $hasSuccess = true;
            }
            if ($s === 'failed') {
                $hasFailed = true;
            }
        }

        if ($hasPending) {
            $message->setStatus(Message::STATUS_QUEUED);
            return;
        }

        if ($hasSuccess) {
            $message->setStatus(Message::STATUS_SENT);
            return;
        }

        if ($hasFailed) {
            $message->setStatus(Message::STATUS_FAILED);
            return;
        }
    }
}