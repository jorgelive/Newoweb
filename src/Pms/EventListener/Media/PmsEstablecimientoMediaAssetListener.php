<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media;

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Pms\Entity\PmsEstablecimientoMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rellena la URL pública de las fotos de las cajas al cargarlas.
 *
 * Mismo patrón que {@see PmsUnidadMediaAssetListener}, y por el mismo motivo: la ruta pública es
 * configuración (`pms.path.establecimiento_images`), así que no puede vivir dentro de la entidad.
 * Sin este listener, `PmsEstablecimientoMedia::getValor()` devolvería el nombre del archivo y se
 * pintaría una imagen rota.
 *
 * Su gemelo es {@see PmsEstablecimientoMediaCacheListener}, que tira la miniatura cuando el medio
 * cambia. Van siempre en pareja: con sólo éste, reemplazar una foto dejaría la vieja servida desde
 * la caché **sin ningún error**.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsEstablecimientoMedia::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: PmsEstablecimientoMedia::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: PmsEstablecimientoMedia::class)]
class PmsEstablecimientoMediaAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.establecimiento_images')] private string $path
    ) {
        parent::__construct();
    }

    protected function getMapping(): array
    {
        return [
            'imageName' => ['path' => $this->path, 'setter' => 'imageUrl'],
        ];
    }
}
