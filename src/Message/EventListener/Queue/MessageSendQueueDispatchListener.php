<?php
declare(strict_types=1);

namespace App\Message\EventListener\Queue;

use App\Exchange\Dispatch\RunExchangeTaskDispatch;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\WhatsappGupshupSendQueue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
#[AsDoctrineListener(event: Events::postFlush, priority: 200)]
final class MessageSendQueueDispatchListener
{
    private array $beds24QueuedIds = [];
    private array $gupshupQueuedIds = [];

    public function __construct(private readonly MessageBusInterface $bus) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        // 1. INSERCIONES: Toda cola nueva nace en estado PENDING, la capturamos directo.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Beds24SendQueue || $entity instanceof WhatsappGupshupSendQueue) {
                $this->collectIfPending($entity);
            }
        }

        // 2. ACTUALIZACIONES: Ultra-eficiente. Solo miramos si el estado cambió a PENDING.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Beds24SendQueue || $entity instanceof WhatsappGupshupSendQueue) {

                // Pedimos a Doctrine exactamente qué campos cambiaron
                $changeSet = $uow->getEntityChangeSet($entity);

                // Si el campo 'status' cambió, y el NUEVO valor (índice 1) es 'pending'
                if (isset($changeSet['status']) && $changeSet['status'][1] === 'pending') {
                    $this->collectIfPending($entity);
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!empty($this->beds24QueuedIds)) {
            $ids = array_unique($this->beds24QueuedIds);
            $this->beds24QueuedIds = []; // Limpieza inmediata para evitar fugas en workers

            // Asegúrate de que el taskName coincida con tu Locator
            $this->bus->dispatch(new RunExchangeTaskDispatch('beds24_message_send', $ids));
        }

        if (!empty($this->gupshupQueuedIds)) {
            $ids = array_unique($this->gupshupQueuedIds);
            $this->gupshupQueuedIds = []; // Limpieza inmediata

            // Asegúrate de que el taskName coincida con tu Locator
            $this->bus->dispatch(new RunExchangeTaskDispatch('whatsapp_gupshup_message_send', $ids));
        }
    }

    /**
     * Agrupa el ID en el array correspondiente solo si realmente está PENDING.
     */
    private function collectIfPending(object $entity): void
    {
        // Doble validación de seguridad
        if ($entity->getStatus() !== 'pending') {
            return;
        }

        if ($entity instanceof Beds24SendQueue) {
            // Casteo a string para compatibilidad con el Dispatcher genérico
            $this->beds24QueuedIds[] = (string) $entity->getId();
        } elseif ($entity instanceof WhatsappGupshupSendQueue) {
            $this->gupshupQueuedIds[] = (string) $entity->getId();
        }
    }
}