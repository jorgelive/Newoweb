<?php

declare(strict_types=1);

namespace App\Panel\EventListener\Media; // ✅ Nuevo Namespace

use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

abstract class AbstractCacheListener
{
    public function __construct(
        protected CacheManager $cacheManager
    ) {}

    /**
     * @return array<string, string>
     */
    abstract protected function getMapping(): array;

    public function preUpdate(object $entity, PreUpdateEventArgs $args): void
    {
        foreach ($this->getMapping() as $field => $path) {
            if ($args->hasChangedField($field)) {
                $oldValue = $args->getOldValue($field);
                if ($oldValue) {
                    $this->removeCache($path, (string) $oldValue);
                }
            }
        }
    }

    public function preRemove(object $entity, PreRemoveEventArgs $args): void
    {
        foreach ($this->getMapping() as $field => $path) {
            $getter = 'get' . ucfirst($field);
            if (method_exists($entity, $getter)) {
                $filename = $entity->$getter();
                if ($filename) {
                    $this->removeCache($path, $filename);
                }
            }
        }
    }

    private function removeCache(string $basePath, string $filename): void
    {
        $relativePath = ltrim(rtrim($basePath, '/') . '/' . ltrim($filename, '/'), '/');
        $this->cacheManager->remove($relativePath);
    }
}