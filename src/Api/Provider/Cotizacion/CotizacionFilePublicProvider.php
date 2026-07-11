<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;


final class CotizacionFilePublicProvider implements ProviderInterface
{
    private ObjectRepository $repository;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(CotizacionFile::class);
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionFile
    {
        $file = $this->repository->findOneBy(['localizador' => $uriVariables['localizador']]);
        if (!$file) {
            return null; // 404, sin filtrar si existe o no vía timing/mensaje distinto
        }

        $cotizacionActiva = $file->getCotizaciones()
            ->filter(fn(Cotizacion $c) => $c->getEstado()->esPublico())
            ->first();

        if (!$cotizacionActiva) {
            return null; // ninguna versión pública -> 404
        }

        if ($cotizacionActiva->getFechaExpiracion()
            && $cotizacionActiva->getFechaExpiracion() < new \DateTimeImmutable()) {
            return null;
        }

        return $file;
    }
}