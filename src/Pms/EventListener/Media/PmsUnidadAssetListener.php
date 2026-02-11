<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Entity\PmsUnidad;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsUnidad::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: PmsUnidad::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: PmsUnidad::class)]
class PmsUnidadAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.unidad_images')] private string $path
    ) {
        parent::__construct();
    }

    protected function getMapping(): array
    {
        return [
            'imageName' => ['path' => $this->path, 'setter' => 'imageUrl']
        ];
    }
}