<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Message;

use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageConversationFactory;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsReservaMessageContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Intercepta el guardado de Reservas en caliente para incluir la Conversación
 * en la misma transacción SQL, SIN usar flush() manual.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class PmsReservaMessageListener
{
    public function __construct(
        private readonly MessageConversationFactory $factory
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $reservasProcesadas = [];

        // 1. Recolectamos las nuevas reservas que se van a insertar
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsReserva) {
                $reservasProcesadas[] = $entity;
            }
        }

        // 2. Recolectamos las reservas existentes que se van a actualizar
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsReserva) {
                $reservasProcesadas[] = $entity;
            }
        }

        // 3. Procesamos y metemos las conversaciones en el mismo tren
        foreach ($reservasProcesadas as $reserva) {
            $context = new PmsReservaMessageContext($reserva);

            // Upsert sin flush (solo crea o actualiza la entidad en memoria)
            $conversation = $this->factory->upsertFromContext($context, false);

            $em->persist($conversation);

            // 🔥 LA MAGIA: Le decimos a Doctrine que calcule los cambios de esta
            // nueva entidad para que la incluya en el COMMIT actual. ¡Cero flush() manual!
            $metadata = $em->getClassMetadata(MessageConversation::class);
            $uow->computeChangeSet($metadata, $conversation);
        }
    }
}