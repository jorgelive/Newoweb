<?php

declare(strict_types=1);

namespace App\Travel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Travel\Entity\ProveedorImagen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Recibe la subida de una imagen de galería de proveedor por `multipart/form-data`.
 *
 * Mismo patrón que {@see \App\Cotizacion\State\CotizacionFiledocumentoMultipartProcessor}:
 * API Platform denormaliza los campos escalares del formulario, y el binario hay que
 * recogerlo a mano de la request y entregárselo a Vich.
 *
 * El campo se llama `imagen` y NO `file`: en multipart, un campo con el nombre de una
 * propiedad de Doctrine confunde al denormalizador (es la razón por la que el de documentos
 * usa `documento`). A partir de aquí manda VichUploader, y
 * {@see \App\Panel\EventListener\Media\VichWebpConversionListener} convierte a WebP.
 */
/** @implements ProcessorInterface<ProveedorImagen, ProveedorImagen|null> */
final readonly class ProveedorImagenMultipartProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        /** @var ProcessorInterface<ProveedorImagen, ProveedorImagen|null> */
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof ProveedorImagen) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request && $request->files->has('imagen')) {
            $subida = $request->files->get('imagen');

            if ($subida instanceof UploadedFile) {
                $data->setImageFile($subida);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
