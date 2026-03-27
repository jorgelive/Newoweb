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
readonly class MessageTranslatorEntityListener
{
    public function __construct(
        private MessageTranslator $translator,
    ) {}

    /**
     * Intercepta la creación de un nuevo mensaje antes de que sea insertado en la base de datos.
     *
     * Responsabilidades principales:
     * Ejecutar el motor de traducción para asegurar que el contenido esté en el idioma correcto.
     */
    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        // 1. Procesar Traducciones Automáticas
        $this->translator->process($message);
    }

    /**
     * Intercepta la actualización de un mensaje existente antes de que los cambios se apliquen.
     */
    public function preUpdate(Message $message, PreUpdateEventArgs $event): void
    {
        // Si el contenido fue editado por algún proceso posterior, volvemos a asegurar
        // que las traducciones locales y externas estén sincronizadas.
        if ($event->hasChangedField('contentLocal') || $event->hasChangedField('contentExternal')) {
            $this->translator->process($message);
        }
    }
}