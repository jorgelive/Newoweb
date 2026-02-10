<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractCacheListener;
use App\Pms\Entity\PmsGuiaItemGaleria;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsGuiaItemGaleria::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsGuiaItemGaleria::class)]
class PmsGuiaItemGaleriaCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'pms.path.galeria_images')] private string $path
    ) {
        parent::__construct($cacheManager);
    }

    protected function getMapping(): array
    {
        return [
            'imageName' => $this->path
        ];
    }
}