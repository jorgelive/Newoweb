<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Agent\Dispatch\ProcessInboundIntentDispatch;
use App\Message\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Escucha modificaciones en los mensajes de chat e inyecta la orden al Agent asíncronamente.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Message::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Message::class)]
final readonly class MessageAutoResponderListener
{
    public function __construct(private MessageBusInterface $bus) {}

    public function postPersist(Message $message, PostPersistEventArgs $event): void
    {
        $this->processIntent($message);
    }

    public function postUpdate(Message $message, PostUpdateEventArgs $event): void
    {
        // En PostUpdate no tenemos hasChangedField, pero evaluamos la regla de negocio directamente.
        $this->processIntent($message);
    }

    private function processIntent(Message $message): void
    {
        $intent = $message->getInboundIntent();

        // Si hay una intención y su flag 'resolved' es falso, despachamos la orden
        if ($intent && ($intent['resolved'] ?? true) === false) {
            $this->bus->dispatch(new ProcessInboundIntentDispatch((string) $message->getId()));
        }
    }
}