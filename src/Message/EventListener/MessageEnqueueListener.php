<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Entity\Message;
use App\Message\Service\MessageDispatcher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Implementa el Patrón Outbox Transaccional.
 * Intercepta cualquier creación de un Message y genera sus colas de envío
 * en la misma transacción SQL, garantizando consistencia absoluta.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class MessageEnqueueListener
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Revisamos TODAS las entidades que Doctrine está a punto de insertar
        foreach ($uow->getScheduledEntityInsertions() as $entity) {

            // Si es un Mensaje, va hacia afuera y está "pendiente":
            if ($entity instanceof Message
                && $entity->getDirection() === Message::DIRECTION_OUTGOING
                && $entity->getStatus() === Message::STATUS_PENDING) {

                // 1. Pedimos al despachador que genere las colas
                $queues = $this->dispatcher->dispatch($entity);

                foreach ($queues as $queue) {
                    // Preparamos la cola para guardarla
                    $em->persist($queue);

                    // 🔥 DOCTRINE HACK: Le decimos al UnitOfWork que calcule los cambios
                    // de esta nueva entidad para inyectarla en el mismo COMMIT SQL.
                    $uow->computeChangeSet($em->getClassMetadata(get_class($queue)), $queue);
                }

                // 2. Cambiamos el estado del mensaje principal a "En Cola"
                $entity->setStatus(Message::STATUS_QUEUED);

                // 🔥 DOCTRINE HACK: Re-calculamos el mensaje original porque acabamos
                // de cambiarle el estado y Doctrine necesita saberlo antes del COMMIT.
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Message::class), $entity);
            }
        }
    }
}