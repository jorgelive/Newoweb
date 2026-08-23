<?php

declare(strict_types=1);

namespace App\Travel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Travel\Entity\TravelOrganizacionImagen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Recibe la subida de una imagen de galería de organización por `multipart/form-data`.
 *
 * Mismo patrón que {@see \App\Cotizacion\State\CotizacionFilearchivoMultipartProcessor}:
 * API Platform denormaliza los campos escalares del formulario, y el binario hay que
 * recogerlo a mano de la request y entregárselo a Vich.
 *
 * El campo se llama `imagen` y NO `file`: en multipart, un campo con el nombre de una
 * propiedad de Doctrine confunde al denormalizador (es la razón por la que el de documentos
 * usa `documento`). A partir de aquí manda VichUploader, y
 * {@see \App\Panel\EventListener\Media\VichWebpConversionListener} convierte a WebP.
 */
/**
 * ⚠️ Genérico en `mixed`, y no en la entidad, porque es la verdad: API Platform le pasa
 * cualquier cosa y este procesador **delega** lo que no sea una imagen. Declararlo estrecho
 * hacía que PHPStan diera la guarda de abajo por siempre cierta —y esa guarda es justo la que
 * hace correcto el delegado—.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class TravelOrganizacionImagenMultipartProcessor implements ProcessorInterface
{
    /**
     * El `@param` va AQUÍ y no como `@var` pegado al parámetro: ahí queda detrás del atributo
     * `#[Autowire]` y PHPStan no lo lee — el mismo fallo de docblock desplazado que documenta
     * CLAUDE.md.
     *
     * @param ProcessorInterface<mixed, mixed> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof TravelOrganizacionImagen) {
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
