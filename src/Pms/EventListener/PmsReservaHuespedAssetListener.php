<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Listener encargado de inyectar las rutas web absolutas a los archivos (Assets)
 * de PmsReservaHuesped justo después de cargar la entidad desde la BD.
 * * Elimina la necesidad de hardcodear rutas en los controladores o Twig.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: PmsReservaHuesped::class)]
class PmsReservaHuespedAssetListener
{
    public function __construct(
        // Inyectamos las rutas definidas en el .env (Fte. de verdad única)
        #[Autowire(param: 'pms.path.huesped_docs')]
        private string $docsPath,

        #[Autowire(param: 'pms.path.huesped_firmas')]
        private string $firmasPath,
    ) {}

    public function postLoad(PmsReservaHuesped $huesped): void
    {
        // 1. Inyectar URL completa del Documento (DNI/Pasaporte)
        if ($huesped->getDocumentoName()) {
            $huesped->setDocumentoUrl($this->buildUrl($this->docsPath, $huesped->getDocumentoName()));
        }

        // 2. Inyectar URL completa de la TAM
        if ($huesped->getTamName()) {
            $huesped->setTamUrl($this->buildUrl($this->docsPath, $huesped->getTamName()));
        }

        // 3. Inyectar URL completa de la Firma
        if ($huesped->getFirmaName()) {
            $huesped->setFirmaUrl($this->buildUrl($this->firmasPath, $huesped->getFirmaName()));
        }
    }

    /**
     * Helper para concatenar ruta base y archivo evitando dobles slashes.
     */
    private function buildUrl(string $basePath, string $filename): string
    {
        return rtrim($basePath, '/') . '/' . $filename;
    }
}