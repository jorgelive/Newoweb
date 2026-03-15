<?php

declare(strict_types=1);

namespace App\Message\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageAttachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MessageMultipartProcessor implements ProcessorInterface
{
    public function __construct(
        // Inyectamos el procesador original para que guarde en la BD al terminar
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,

        // Inyectamos la petición HTTP actual para extraer el archivo físicamente
        private RequestStack $requestStack
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Si por alguna razón no es un Message, dejamos que siga su curso normal
        if (!$data instanceof Message) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $this->requestStack->getCurrentRequest();

        // Extraemos el archivo saltándonos al Serializador por completo
        if ($request && $request->files->has('file')) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('file');

            if ($uploadedFile) {
                // Instanciamos el adjunto y se lo pasamos a VichUploader
                $attachment = new MessageAttachment();
                $attachment->setFile($uploadedFile);
                $attachment->setOriginalName($uploadedFile->getClientOriginalName());
                $attachment->setMimeType($uploadedFile->getMimeType());
                $attachment->setFileSize($uploadedFile->getSize());

                // Lo asociamos al mensaje (Doctrine hará el persist en cascada)
                $data->addAttachment($attachment);
            }
        }

        // Finalmente, mandamos la entidad ya ensamblada al procesador de Doctrine
        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}