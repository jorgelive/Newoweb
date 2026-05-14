<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFiledocumento;
use App\Panel\EventListener\Media\AbstractCacheListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Se encarga de notificar a LiipImagine que elimine los archivos en caché (miniaturas)
 * si un documento adjunto a una cotización es modificado o eliminado.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: CotizacionFiledocumento::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: CotizacionFiledocumento::class)]
class CotizacionFiledocumentoCacheListener extends AbstractCacheListener
{
    /**
     * Constructor para inyectar las dependencias y el parámetro de la ruta.
     *
     * @param CacheManager $cacheManager Gestor de caché de LiipImagine.
     * @param string $uploadPath Ruta base inyectada vía Autowire.
     */
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire('%cotizacion.path.file_documentos%')]
        private readonly string $uploadPath
    ) {
        parent::__construct($cacheManager);
    }

    /**
     * Devuelve el mapeo indicando qué propiedad contiene el nombre del archivo
     * y cuál es su ruta relativa para buscar en caché.
     *
     * @return array<string, string>
     */
    protected function getMapping(): array
    {
        return [
            // 'imageName' es la propiedad de la BD.
            'imageName' => $this->uploadPath,
        ];
    }
}