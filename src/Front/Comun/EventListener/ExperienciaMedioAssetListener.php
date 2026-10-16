<?php

declare(strict_types=1);

namespace App\Front\Comun\EventListener;

use App\Front\Comun\Entity\ExperienciaMedio;
use App\Panel\EventListener\Media\AbstractAssetListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Pone `imageUrl` (ruta pública de la foto) en cada `ExperienciaMedio` cargado o guardado. */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: ExperienciaMedio::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: ExperienciaMedio::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: ExperienciaMedio::class)]
class ExperienciaMedioAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire('%front.path.experiencias%')]
        private readonly string $uploadPath,
    ) {
        parent::__construct();
    }

    protected function getMapping(): array
    {
        return ['imageName' => ['path' => $this->uploadPath, 'setter' => 'imageUrl']];
    }
}
