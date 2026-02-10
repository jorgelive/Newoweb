<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Media; // ✅ Nuevo Namespace

use App\Panel\EventListener\Media\AbstractAssetListener; // ✅ Importamos la base correcta
use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsReservaHuesped::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: PmsReservaHuesped::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: PmsReservaHuesped::class)]
class PmsReservaHuespedAssetListener extends AbstractAssetListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.huesped_docs')] private string $docsPath,
        #[Autowire(param: 'pms.path.huesped_firmas')] private string $firmasPath
    ) {
        parent::__construct();
    }

    protected function getMapping(): array
    {
        return [
            'documentoName' => ['path' => $this->docsPath, 'setter' => 'documentoUrl'],
            'tamName'       => ['path' => $this->docsPath, 'setter' => 'tamUrl'],
            'firmaName'     => ['path' => $this->firmasPath, 'setter' => 'firmaUrl'],
        ];
    }
}