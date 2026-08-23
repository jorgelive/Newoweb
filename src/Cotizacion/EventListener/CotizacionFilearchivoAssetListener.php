<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Panel\EventListener\Media\AbstractAssetListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Escucha los eventos de carga de la entidad CotizacionFilearchivo para inyectar
 * la URL pública de la imagen o documento en la propiedad virtual $imageUrl.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: CotizacionFilearchivo::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: CotizacionFilearchivo::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: CotizacionFilearchivo::class)]
class CotizacionFilearchivoAssetListener extends AbstractAssetListener
{
    /**
     * Constructor para inyectar la ruta configurada en services.yaml.
     *
     * @param string $uploadPath Ruta base inyectada vía Autowire.
     */
    public function __construct(
        #[Autowire('%cotizacion.path.file_archivos%')]
        private readonly string $uploadPath
    ) {
        parent::__construct();
    }

    /**
     * Define el mapeo entre el nombre del archivo en BD y la propiedad que recibirá la URL.
     *
     * @return array<string, array{path: string, setter: string}>
     */
    protected function getMapping(): array
    {
        return [
            // 'imageName' es la propiedad mapeada en la BD (definida en el MediaTrait/Vich)
            'imageName' => [
                'path' => $this->uploadPath,
                'setter' => 'imageUrl',
            ]
        ];
    }
}