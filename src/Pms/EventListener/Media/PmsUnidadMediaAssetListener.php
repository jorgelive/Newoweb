<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media;

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Pms\Entity\PmsUnidadMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rellena la URL pública del croquis y de la foto de la puerta al cargarlos.
 *
 * Mismo patrón que {@see PmsUnidadAssetListener}, y por el mismo motivo: la ruta pública es
 * configuración (`pms.path.unidad_images`), así que no puede vivir dentro de la entidad. Sin este
 * listener, `PmsUnidadMedia::getValor()` devolvería el nombre del archivo —`TOKEN_croquis.webp`—
 * y la guía pintaría una imagen rota.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsUnidadMedia::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: PmsUnidadMedia::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: PmsUnidadMedia::class)]
class PmsUnidadMediaAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.unidad_images')] private string $path
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
