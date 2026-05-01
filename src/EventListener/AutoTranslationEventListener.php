<?php
// src/EventListener/AutoTranslationEventListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\AutoTranslationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Listener encargado de interceptar entidades antes de ser guardadas para auto-traducir
 * campos marcados con el atributo #[AutoTranslate].
 * Delega la lógica pesada al AutoTranslationService.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class AutoTranslationEventListener
{
    public function __construct(
        private readonly AutoTranslationService $autoTranslationService
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        // En persistencia nueva, no hay ChangeSet previo que recalcular
        $this->autoTranslationService->processEntity($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        // Pasamos el ObjectManager para que el servicio pueda recalcular el ChangeSet
        $this->autoTranslationService->processEntity($args->getObject(), false, null, $args->getObjectManager());
    }
}