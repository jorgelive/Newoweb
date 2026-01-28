<?php

namespace App\EventListener;

use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Listener de Doctrine encargado de inicializar rutas del sistema de archivos en las entidades.
 *
 * Este listener actúa como un puente entre la configuración del servidor (parámetros de Symfony)
 * y el modelo de datos (Entidades de Doctrine). Su función principal es inyectar la ruta
 * absoluta del directorio público (`publicDir`) en aquellas entidades que gestionan archivos
 * (como imágenes de departamentos, banners, usuarios) y que utilizan el trait `MainArchivoTrait`.
 *
 * Se utiliza el patrón Listener con Atributos (PHP 8) para un registro automático y sin configuración YAML.
 *
 * @see \App\Oweb\Trait\MainArchivoTrait Trait que consume la ruta inyectada.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 500, connection: 'default')]
#[AsDoctrineListener(event: Events::preUpdate, priority: 500, connection: 'default')]
#[AsDoctrineListener(event: Events::postLoad, priority: 500, connection: 'default')]
final class EntityPathInitializerDoctrineEventListener
{
    /**
     * @var string Ruta absoluta al directorio público del proyecto.
     */
    private readonly string $publicDir;

    /**
     * Constructor del Listener.
     *
     * Se utiliza Autowire para inyectar el parámetro global `app.public_dir` definido en services.yaml.
     * Esto desacopla la lógica de la ruta física del servidor.
     *
     * @param string $publicDir Ruta del directorio público (ej: /var/www/proyecto/public).
     */
    public function __construct(
        #[Autowire('%app.public_dir%')]
        string $publicDir
    ) {
        $this->publicDir = $publicDir;
    }

    /**
     * Intercepta el evento PrePersist de Doctrine.
     *
     * Se ejecuta justo antes de que una nueva entidad sea insertada en la base de datos.
     * Es crucial para que la lógica de `preUpload` (definida en la entidad/trait) tenga acceso
     * a la ruta de destino antes de intentar mover el archivo físico.
     *
     * @param PrePersistEventArgs $args Argumentos del evento, contiene la entidad a persistir.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Intercepta el evento PreUpdate de Doctrine.
     *
     * Se ejecuta justo antes de que una entidad existente sea actualizada en la base de datos.
     * Garantiza que si se reemplaza una imagen en una edición, la entidad sepa dónde guardar el nuevo archivo.
     *
     * @param PreUpdateEventArgs $args Argumentos del evento, contiene la entidad a actualizar.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Intercepta el evento PostLoad de Doctrine.
     *
     * Se ejecuta inmediatamente después de que una entidad ha sido hidratada desde la base de datos.
     * Esto asegura que al leer objetos (en controladores, vistas o EasyAdmin), la entidad ya tenga
     * configurada su ruta interna, permitiendo que métodos como `getInternalPath` funcionen correctamente
     * sin necesidad de intervención manual.
     *
     * @param PostLoadEventArgs $args Argumentos del evento, contiene la entidad cargada.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->init($this->extractEntity($args));
    }

    /**
     * Lógica central de inyección de dependencia (Setter Injection).
     *
     * Verifica mediante Duck Typing si la entidad procesada tiene la capacidad de recibir
     * la ruta pública (buscando el método `setInternalPublicDir`). Si es compatible, le inyecta la ruta.
     *
     * @param object|null $entity La entidad que disparó el evento. Puede ser null si la extracción falló.
     */
    private function init(?object $entity): void
    {
        // Verificamos explícitamente si el método existe para evitar errores en entidades que no usan el Trait.
        if ($entity !== null && method_exists($entity, 'setInternalPublicDir')) {
            $entity->setInternalPublicDir($this->publicDir);
        }
    }

    /**
     * Extrae el objeto entidad de los argumentos del evento de forma agnóstica a la versión.
     *
     * Abstrae las diferencias entre versiones de Doctrine ORM, donde el método para obtener
     * el objeto ha variado entre `getObject()` y `getEntity()`.
     *
     * @param object $args El objeto de argumentos (EventArgs) de Doctrine.
     * @return object|null La entidad procesada o null si no se pudo determinar.
     */
    private function extractEntity(object $args): ?object
    {
        return match (true) {
            method_exists($args, 'getObject') => $args->getObject(),
            method_exists($args, 'getEntity') => $args->getEntity(),
            default => null,
        };
    }
}