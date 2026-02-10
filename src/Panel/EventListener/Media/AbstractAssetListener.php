<?php

declare(strict_types=1);

namespace App\Panel\EventListener\Media; // ✅ Nuevo Namespace

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

abstract class AbstractAssetListener
{
    private PropertyAccessorInterface $accessor;

    public function __construct()
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * @return array<string, array{path: string, setter: string}>
     */
    abstract protected function getMapping(): array;

    public function postLoad(object $entity, PostLoadEventArgs $args): void
    {
        $this->inject($entity);
    }

    public function postPersist(object $entity, PostPersistEventArgs $args): void
    {
        $this->inject($entity);
    }

    public function postUpdate(object $entity, PostUpdateEventArgs $args): void
    {
        $this->inject($entity);
    }

    private function inject(object $entity): void
    {
        foreach ($this->getMapping() as $fileField => $config) {
            if ($this->accessor->isReadable($entity, $fileField)) {
                $filename = $this->accessor->getValue($entity, $fileField);

                if ($filename) {
                    $url = rtrim($config['path'], '/') . '/' . ltrim($filename, '/');
                    if ($this->accessor->isWritable($entity, $config['setter'])) {
                        $this->accessor->setValue($entity, $config['setter'], $url);
                    }
                }
            }
        }
    }
}