<?php

namespace App\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Subscriber de Doctrine para la inicialización de rutas en Entidades.
 * * Este suscriptor detecta entidades que requieren conocer la ruta pública del servidor
 * (útil para la gestión de imágenes de tus departamentos en Cusco) e inyecta la ruta
 * necesaria automáticamente al persistir, actualizar o cargar datos.
 */
final class EntityPathInitializerSubscriber implements EventSubscriber
{
    /**
     * @param string $publicDir Ruta absoluta al directorio público (inyectada vía bind en el core).
     */
    public function __construct(
        private readonly string $publicDir
    ) {}

    /**
     * Define los eventos de Doctrine a los que este servicio debe reaccionar.
     * * @return string[] Array con los nombres de los eventos registrados.
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
            Events::postLoad
        ];
    }

    /**
     * Ejecuta la inicialización antes de la creación en base de datos.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Ejecuta la inicialización antes de la actualización de datos existentes.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Ejecuta la inicialización inmediatamente después de cargar una entidad.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Inyecta la ruta pública en la entidad si esta cuenta con el setter requerido.
     * * @param object|null $entity Instancia de la entidad procesada.
     */
    private function init(?object $entity): void
    {
        if ($entity && method_exists($entity, 'setInternalPublicDir')) {
            $entity->setInternalPublicDir($this->publicDir);
        }
    }

    /**
     * Extrae el objeto entidad de los argumentos del evento.
     * * Soporta diferentes versiones de Doctrine (getEntity/getObject) para
     * asegurar compatibilidad total en tu entorno local y de producción.
     * * @param object $args Argumentos del evento de Doctrine.
     * @return object|null La entidad o null si no se pudo extraer.
     */
    private function extractEntity(object $args): ?object
    {
        if (method_exists($args, 'getEntity')) {
            return $args->getEntity();
        }

        return method_exists($args, 'getObject') ? $args->getObject() : null;
    }
}