<?php

declare(strict_types=1);

namespace App\Message\EventListener\Media;

use App\Panel\EventListener\Media\AbstractCacheListener;
use App\Message\Entity\MessageAttachment;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: MessageAttachment::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: MessageAttachment::class)]
class MessageAttachmentCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'message.path.message_attachments')] private string $path
    ) {
        parent::__construct($cacheManager);
    }

    protected function getMapping(): array
    {
        return [
            'fileName' => $this->path
        ];
    }
}