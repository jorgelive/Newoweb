<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media;

use App\Panel\EventListener\Media\AbstractCacheListener;
use App\Pms\Entity\PmsUnidadMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invalida la caché de Liip cuando cambia o se borra un medio de la casita.
 *
 * Gemelo de {@see PmsUnidadMediaAssetListener}: uno pone la URL al leer, el otro tira la miniatura
 * al escribir. Van siempre en pareja.
 *
 * ⚠️ **Escuchaba a `PmsUnidad` hasta el 12/09/2026**, cuando su portada era una columna de esa
 * entidad. Al mudarse a {@see PmsUnidadMedia} el listener se muda con ella: si se quedara mirando
 * la unidad, cambiar una portada dejaría la miniatura vieja servida desde la caché y **no habría
 * ningún error** — sólo una foto que no se actualiza.
 *
 * ⚠️ Se llamaba `PmsUnidadCacheListener` y el nombre se quedó atrás un día: decía que escuchaba a
 * la unidad cuando ya escuchaba al medio. Un nombre que miente sobre a quién escucha es justo el
 * que hace que nadie busque aquí el día que una miniatura no se actualice.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsUnidadMedia::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsUnidadMedia::class)]
class PmsUnidadMediaCacheListener extends AbstractCacheListener
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