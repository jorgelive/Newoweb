<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsReservaHuesped::class)]
class PmsReservaHuespedEntityListener
{
    public function __construct(
        #[Autowire(param: 'pms.path.huesped_docs')]
        private string $docsPath,

        #[Autowire(param: 'pms.path.huesped_firmas')]
        private string $firmasPath,
    ) {}

    public function postLoad(PmsReservaHuesped $huesped): void
    {
        if ($huesped->getDocumentoName()) {
            $huesped->setDocumentoUrl($this->joinPaths($this->docsPath, $huesped->getDocumentoName()));
        }
        if ($huesped->getTamName()) {
            $huesped->setTamUrl($this->joinPaths($this->docsPath, $huesped->getTamName()));
        }
        if ($huesped->getFirmaName()) {
            $huesped->setFirmaUrl($this->joinPaths($this->firmasPath, $huesped->getFirmaName()));
        }
    }

    private function joinPaths(string $base, string $file): string
    {
        return rtrim($base, '/') . '/' . $file;
    }
}