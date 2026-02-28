<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Entity\Message;
use App\Message\Service\MessageDispatcher;
use App\Message\Service\MessageTranslator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
class MessageEntityListener
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly MessageDispatcher $dispatcher, // 🔥 Inyectamos el Dispatcher
        private readonly EntityManagerInterface $em // 🔥 Inyectamos el EntityManager
    ) {}

    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        // 1. Procesar Traducciones
        $this->translator->process($message);

        // 2. Patrón Outbox: Generar las colas de envío
        if ($message->getDirection() === Message::DIRECTION_OUTGOING
            && $message->getStatus() === Message::STATUS_PENDING) {

            // El dispatcher ahora se encarga de cambiar el estado a QUEUED o FAILED internamente
            $queues = $this->dispatcher->dispatch($message);

            foreach ($queues as $queue) {
                // Como estamos en prePersist, simplemente le decimos al EM que persista
                // estas nuevas entidades. Doctrine las recogerá automáticamente en
                // el ciclo de flush actual sin necesidad de "recomputar" nada.
                $this->em->persist($queue);
            }
        }
    }

    public function preUpdate(Message $message, PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('contentLocal') || $event->hasChangedField('contentExternal')) {
            $this->translator->process($message);
        }
    }
}