<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media;

use App\Panel\EventListener\Media\AbstractCacheListener;
use App\Pms\Entity\PmsEstablecimientoMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invalida la caché de Liip cuando cambia o se borra un medio del establecimiento.
 *
 * Gemelo de {@see PmsEstablecimientoMediaAssetListener}: uno pone la URL al leer, el otro tira la
 * miniatura al escribir. Sin éste, reemplazar la foto de una caja dejaría la miniatura vieja
 * servida desde la caché y **no habría ningún error** — sólo una foto que no se actualiza, que es
 * la clase de fallo que nadie denuncia.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEstablecimientoMedia::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsEstablecimientoMedia::class)]
class PmsEstablecimientoMediaCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'pms.path.establecimiento_images')] private string $imagesPath,
    ) {
        parent::__construct($cacheManager);
    }

    protected function getMapping(): array
    {
        return [
            'imageName' => $this->imagesPath,
        ];
    }
}
