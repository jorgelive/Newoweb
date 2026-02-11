<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractCacheListener; // ✅ Importamos la base correcta
use App\Pms\Entity\PmsReservaHuesped;
use App\Pms\Entity\PmsUnidad;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsUnidad::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsUnidad::class)]
class PmsUnidadCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'pms.path.unidad_images')] private string $imagesPath,
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