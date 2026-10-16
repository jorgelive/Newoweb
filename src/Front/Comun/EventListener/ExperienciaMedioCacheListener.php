<?php

declare(strict_types=1);

namespace App\Front\Comun\EventListener;

use App\Front\Comun\Entity\ExperienciaMedio;
use App\Panel\EventListener\Media\AbstractCacheListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Borra de la caché de Liip (`public/media/cache/…`) las miniaturas de una foto de álbum cuando
 * se reemplaza o se borra.
 *
 * ⚠️ **Faltaba, y en estas fotos importa más que en las demás.** Vich borra el original
 * (`delete_on_remove`), pero las copias que genera Liip al servirlas viven en otra carpeta y
 * nadie las tocaba: una foto retirada de un álbum seguía estando, recortada, en una URL pública.
 * Son viajes escolares con menores (ver {@see \App\Front\Comun\Entity\ExperienciaAlbum}), así que
 * «borrada» tiene que significar borrada de las dos carpetas.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: ExperienciaMedio::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: ExperienciaMedio::class)]
class ExperienciaMedioCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'front.path.experiencias')] private readonly string $path,
    ) {
        parent::__construct($cacheManager);
    }

    protected function getMapping(): array
    {
        return ['imageName' => $this->path];
    }
}
