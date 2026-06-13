<?php

declare(strict_types=1);

namespace App\Travel\EventListener;

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Travel\Entity\ProveedorImagen;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Escucha los eventos de carga de la entidad ProveedorImagen para inyectar
 * la URL pública de la imagen en la propiedad virtual $imageUrl, utilizando
 * el parámetro centralizado del sistema.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: ProveedorImagen::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: ProveedorImagen::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: ProveedorImagen::class)]
class ProveedorImagenAssetListener extends AbstractAssetListener
{
    /**
     * Constructor para inyectar la ruta configurada en services.yaml.
     * * @param string $uploadPath Ruta base inyectada vía Autowire.
     */
    public function __construct(
        #[Autowire('%travel.path.proveedor_galeria%')]
        private readonly string $uploadPath
    ) {
        parent::__construct();
    }

    /**
     * Define el mapeo entre el nombre del archivo en BD y la propiedad que recibirá la URL.
     * * @return array<string, array{path: string, setter: string}>
     */
    protected function getMapping(): array
    {
        return [
            // 'imageName' es la propiedad guardada en base de datos.
            'imageName' => [
                'path' => $this->uploadPath,
                'setter' => 'imageUrl',
            ]
        ];
    }
}