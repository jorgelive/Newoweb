<?php

declare(strict_types=1);

namespace App\Message\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageAttachment;
use App\Message\Service\MercureBroadcaster;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MessageMultipartProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack,
        private MercureBroadcaster $mercureBroadcaster // 🔥 Inyectamos nuestro servicio central
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Message) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request && $request->files->has('file')) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('file');

            if ($uploadedFile) {
                $attachment = new MessageAttachment();
                $attachment->setFile($uploadedFile);
                $attachment->setOriginalName($uploadedFile->getClientOriginalName());
                $attachment->setMimeType($uploadedFile->getMimeType());
                $attachment->setFileSize($uploadedFile->getSize());

                $data->addAttachment($attachment);
            }
        }

        // 1. Guardamos en BD
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        // 2. Transmitimos por Mercure usando el Broadcaster y los DTOs
        if ($result instanceof Message) {
            $this->mercureBroadcaster->broadcastMessage($result);
        }

        return $result;
    }
}