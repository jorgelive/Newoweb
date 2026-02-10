<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractCacheListener; // ✅ Importamos la base correcta
use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsReservaHuesped::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsReservaHuesped::class)]
class PmsReservaHuespedCacheListener extends AbstractCacheListener
{
    public function __construct(
        CacheManager $cacheManager,
        #[Autowire(param: 'pms.path.huesped_docs')] private string $docsPath,
        #[Autowire(param: 'pms.path.huesped_firmas')] private string $firmasPath
    ) {
        parent::__construct($cacheManager);
    }

    protected function getMapping(): array
    {
        return [
            'documentoName' => $this->docsPath,
            'tamName'       => $this->docsPath,
            'firmaName'     => $this->firmasPath,
        ];
    }
}