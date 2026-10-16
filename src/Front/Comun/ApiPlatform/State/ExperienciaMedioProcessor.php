<?php

declare(strict_types=1);

namespace App\Front\Comun\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Enum\ExperienciaMedioTipoEnum;
use App\Panel\Media\ImagenSubidaSegura;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * El alta de un medio de álbum: la foto llega en el campo `imagen` del multipart —no en `file`,
 * mismo nombre que las galerías de Travel— y el resto lo denormaliza API Platform.
 *
 * Con archivo, el medio ES una foto aunque no se diga: así util no tiene que mandar `tipo` en
 * cada subida, y un vídeo nunca arrastra un binario. Una foto SIN archivo es un 422 desde aquí.
 *
 * 🔒 Y lo que llega se comprueba antes de aceptarlo ({@see ImagenSubidaSegura}): el destino es
 * `public/`, compartido por todos los hosts, así que un SVG con script sería XSS en el origen del
 * panel. Va aquí y no en la entidad porque el validador corre antes que este processor.
 *
 * @implements ProcessorInterface<ExperienciaMedio, ExperienciaMedio>
 */
final readonly class ExperienciaMedioProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<ExperienciaMedio, ExperienciaMedio> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof ExperienciaMedio) {
            $subida = $this->requestStack->getCurrentRequest()?->files->get('imagen');

            if ($subida instanceof UploadedFile) {
                // 🔒 Antes de nada: que sea de verdad una foto. La carpeta de destino está en
                // `public/` y la comparten todos los hosts. Ver `ImagenSubidaSegura`.
                ImagenSubidaSegura::verificar($subida);

                $data->setTipo(ExperienciaMedioTipoEnum::FOTO);
                $data->setVideoUrl(null);
                $data->setImageFile($subida);
            } elseif ($data->esFoto() && $data->getImageName() === null) {
                // Aquí y no en el validador de la entidad: la validación corre antes que esto y
                // todavía no ha visto el archivo (ver `ExperienciaMedio::validar()`).
                throw new UnprocessableEntityHttpException('Falta la foto: súbela en el campo «imagen».');
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
