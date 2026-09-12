<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractCacheListener; // ✅ Importamos la base correcta
use App\Pms\Entity\PmsUnidadMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invalida la caché de Liip cuando cambia o se borra un medio de la casita.
 *
 * ⚠️ **Escuchaba a `PmsUnidad` hasta el 12/09/2026**, cuando su portada era una columna de esa
 * entidad. Al mudarse a {@see PmsUnidadMedia} el listener se muda con ella: si se quedara mirando
 * la unidad, cambiar una portada dejaría la miniatura vieja servida desde la caché y **no habría
 * ningún error** — sólo una foto que no se actualiza.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsUnidadMedia::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsUnidadMedia::class)]
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