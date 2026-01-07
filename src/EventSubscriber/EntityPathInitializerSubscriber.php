<?php
// src/Doctrine/EntityPathInitializerSubscriber.php

namespace App\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
// 👉 Usa args específicos (no LifecycleEventArgs genérico)
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Subscriber de Doctrine que inicializa la ruta pública interna en las entidades que usan un trait específico.
 *
 * Se ejecuta en los eventos prePersist, preUpdate y postLoad para asegurar que la propiedad
 * interna que contiene la ruta pública esté correctamente configurada en las entidades que la requieren.
 * Esto es útil para entidades que necesitan conocer la ruta pública del proyecto para operaciones internas.
 */
final class EntityPathInitializerSubscriber implements EventSubscriber
{
    /**
     * Constructor.
     *
     * @param string $publicDir Ruta absoluta al directorio público del proyecto (por ejemplo, %kernel.project_dir%/public).
     */
    public function __construct(
        private readonly string $publicDir // %kernel.project_dir%/public
    ) {}

    /**
     * Retorna los eventos a los que este subscriber se suscribe.
     *
     * Se suscribe a:
     * - prePersist: antes de que una entidad sea persistida en la base de datos.
     * - preUpdate: antes de que una entidad sea actualizada en la base de datos.
     * - postLoad: justo después de que una entidad es cargada desde la base de datos.
     *
     * @return string[] Array con los nombres de eventos.
     */
    public function getSubscribedEvents(): array
    {
        // Puedes seguir retornando los nombres de evento
        return [Events::prePersist, Events::preUpdate, Events::postLoad];
    }

    /**
     * Evento que se ejecuta antes de persistir una entidad.
     *
     * Inicializa la ruta pública interna si la entidad usa el trait correspondiente.
     *
     * @param PrePersistEventArgs $args Argumentos del evento que contienen la entidad.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Evento que se ejecuta antes de actualizar una entidad.
     *
     * Inicializa la ruta pública interna si la entidad usa el trait correspondiente.
     *
     * @param PreUpdateEventArgs $args Argumentos del evento que contienen la entidad.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Evento que se ejecuta después de cargar una entidad desde la base de datos.
     *
     * Inicializa la ruta pública interna si la entidad usa el trait correspondiente.
     *
     * @param PostLoadEventArgs $args Argumentos del evento que contienen la entidad.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Inicializa la propiedad interna de la entidad con la ruta pública.
     *
     * Solo afecta a entidades que implementen el método setInternalPublicDir,
     * es decir, que usen el trait esperado para exponer este setter.
     *
     * @param object|null $entity La entidad a inicializar, o null si no se pudo extraer.
     */
    private function init(?object $entity): void
    {
        if (!$entity) return;

        // Solo entidades que usen tu trait (exponen el setter)
        if (method_exists($entity, 'setInternalPublicDir')) {
            $entity->setInternalPublicDir($this->publicDir);
        }
    }

    /**
     * Extrae la entidad del argumento del evento.
     *
     * Esto es necesario por compatibilidad con distintas versiones de Doctrine,
     * donde el argumento puede exponer getEntity() o getObject().
     *
     * @param object $args Argumento del evento.
     * @return object|null La entidad extraída, o null si no se pudo obtener.
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
