<?php

declare(strict_types=1);

namespace App\Travel\EventListener;

use App\Panel\EventListener\Media\AbstractCacheListener;
use App\Travel\Entity\ProveedorImagen;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Se encarga de notificar a LiipImagine que elimine los archivos en caché (miniaturas)
 * si la imagen de la galería del proveedor es modificada o eliminada.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: ProveedorImagen::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: ProveedorImagen::class)]
class ProveedorImagenCacheListener extends AbstractCacheListener
{
    /**
     * Constructor para inyectar las dependencias y el parámetro de la ruta.
     * * @param CacheManager $cacheManager Gestor de caché de LiipImagine.
     * @param string $uploadPath Ruta base inyectada vía Autowire.
     */
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire('%travel.path.proveedor_galeria%')]
        private readonly string $uploadPath
    ) {
        parent::__construct($cacheManager);
    }

    /**
     * Devuelve el mapeo indicando qué propiedad contiene el nombre del archivo
     * y cuál es su ruta relativa para buscar en caché.
     * * @return array<string, string>
     */
    protected function getMapping(): array
    {
        return [
            // 'imageName' es la propiedad en BD que tiene el nombre físico del archivo.
            'imageName' => $this->uploadPath,
        ];
    }
}