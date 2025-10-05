<?php
// src/Doctrine/EntityPathInitializer.php

namespace App\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
// 👉 Usa args específicos (no LifecycleEventArgs genérico)
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;

final class EntityPathInitializer implements EventSubscriber
{
    public function __construct(
        private readonly string $publicDir // %kernel.project_dir%/public
    ) {}

    public function getSubscribedEvents(): array
    {
        // Puedes seguir retornando los nombres de evento
        return [Events::prePersist, Events::preUpdate, Events::postLoad];
    }

    // Firmas nuevas por evento (evita la clase deprecada)
    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    private function init(?object $entity): void
    {
        if (!$entity) return;

        // Solo entidades que usen tu trait (exponen el setter)
        if (method_exists($entity, 'setInternalPublicDir')) {
            $entity->setInternalPublicDir($this->publicDir);
        }
    }

    /**
     * Compatibilidad: según versión, el arg expone getEntity() o getObject().
     */
    private function extractEntity(object $args): ?object
    {
        if (method_exists($args, 'getEntity')) {
            return $args->getEntity();
        }
        if (method_exists($args, 'getObject')) {
            return $args->getObject();
        }
        return null;
    }
}
