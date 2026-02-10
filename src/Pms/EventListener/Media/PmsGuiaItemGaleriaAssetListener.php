<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Pms\Entity\PmsGuiaItemGaleria;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsGuiaItemGaleria::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: PmsGuiaItemGaleria::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: PmsGuiaItemGaleria::class)]
class PmsGuiaItemGaleriaAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.galeria_images')] private string $path
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