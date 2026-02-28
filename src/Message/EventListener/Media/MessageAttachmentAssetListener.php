<?php

declare(strict_types=1);

namespace App\Message\EventListener\Media;

use App\Panel\EventListener\Media\AbstractAssetListener;
use App\Message\Entity\MessageAttachment;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: MessageAttachment::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageAttachment::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageAttachment::class)]
class MessageAttachmentAssetListener extends AbstractAssetListener
{
    public function __construct(
        // Utilizamos el parámetro que definiste en vich_uploader.yaml
        #[Autowire(param: 'message.path.message_attachments')] private string $path
    ) {
        parent::__construct();
    }

    protected function getMapping(): array
    {
        return [
            // 'fileName' es la propiedad física, 'fileUrl' será el setter virtual
            'fileName' => ['path' => $this->path, 'setter' => 'fileUrl']
        ];
    }
}