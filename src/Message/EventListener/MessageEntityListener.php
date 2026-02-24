<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Entity\Message;
use App\Message\Service\MessageTranslator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
class MessageEntityListener
{
    public function __construct(
        private readonly MessageTranslator $translator
    ) {}

    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        $this->translator->process($message);
    }

    public function preUpdate(Message $message, PreUpdateEventArgs $event): void
    {
        // Evitar procesar de más si solo se actualizó un estado o un timestamp
        if ($event->hasChangedField('contentLocal') || $event->hasChangedField('contentExternal')) {
            $this->translator->process($message);
        }
    }
}