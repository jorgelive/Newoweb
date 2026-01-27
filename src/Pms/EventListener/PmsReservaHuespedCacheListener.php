<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Listener encargado de la BASURA (Garbage Collection) de Miniaturas.
 * Usa Doctrine para detectar cambios y borrar los caches de las imágenes VIEJAS.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsReservaHuesped::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsReservaHuesped::class)]
class PmsReservaHuespedCacheListener
{
    public function __construct(
        private CacheManager $cacheManager,

        // Necesitamos las rutas base para reconstruir el path relativo para Liip
        #[Autowire(param: 'pms.path.huesped_docs')]
        private string $docsPath,

        #[Autowire(param: 'pms.path.huesped_firmas')]
        private string $firmasPath,
    ) {}

    /**
     * Se dispara cuando REEMPLAZAS una imagen (UPDATE).
     * Aquí es donde capturamos el nombre ANTIGUO antes de que se pierda.
     */
    public function preUpdate(PmsReservaHuesped $huesped, PreUpdateEventArgs $event): void
    {
        // 1. Revisamos si cambió el DNI
        if ($event->hasChangedField('documentoName')) {
            // ¡MAGIA! Doctrine nos da el valor viejo
            $oldName = $event->getOldValue('documentoName');
            if ($oldName) {
                $this->deleteCache($this->docsPath, $oldName);
            }
        }

        // 2. Revisamos si cambió la TAM
        if ($event->hasChangedField('tamName')) {
            $oldName = $event->getOldValue('tamName');
            if ($oldName) {
                $this->deleteCache($this->docsPath, $oldName);
            }
        }

        // 3. Revisamos si cambió la Firma
        if ($event->hasChangedField('firmaName')) {
            $oldName = $event->getOldValue('firmaName');
            if ($oldName) {
                $this->deleteCache($this->firmasPath, $oldName);
            }
        }
    }

    /**
     * Se dispara cuando BORRAS el huésped completo (DELETE).
     */
    public function preRemove(PmsReservaHuesped $huesped, PreRemoveEventArgs $event): void
    {
        if ($huesped->getDocumentoName()) {
            $this->deleteCache($this->docsPath, $huesped->getDocumentoName());
        }
        if ($huesped->getTamName()) {
            $this->deleteCache($this->docsPath, $huesped->getTamName());
        }
        if ($huesped->getFirmaName()) {
            $this->deleteCache($this->firmasPath, $huesped->getFirmaName());
        }
    }

    private function deleteCache(string $basePath, string $filename): void
    {
        // Reconstruimos la ruta relativa: 'carga/pms/.../archivo_viejo.webp'
        $path = rtrim($basePath, '/') . '/' . $filename;

        // Liip necesita el path sin el slash inicial
        $this->cacheManager->remove(ltrim($path, '/'), null);
    }
}