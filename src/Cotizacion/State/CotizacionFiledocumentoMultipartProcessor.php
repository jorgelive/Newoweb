<?php

declare(strict_types=1);

namespace App\Cotizacion\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFiledocumento;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CotizacionFiledocumentoMultipartProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof CotizacionFiledocumento) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $this->requestStack->getCurrentRequest();

        // 🔥 Buscamos 'documento' en lugar de 'file' para evitar chocar con la relación $file de Doctrine
        if ($request && $request->files->has('documento')) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('documento');

            if ($uploadedFile) {
                $data->setImageFile($uploadedFile);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}