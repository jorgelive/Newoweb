<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\Proveedor;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class UuidBatchIdExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        // 1. Entidades que soportan la carga en lote mediante ?id[]=...
        $supportedClasses = [
            TravelTarifa::class,
            TravelServicio::class,
            Proveedor::class,
            TravelComponente::class,
            TravelSegmento::class,
        ];

        if (!in_array($resourceClass, $supportedClasses, true)) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // 2. Capturamos el array de UUIDs enviado por el frontend
        $ids = $request->query->all('id');

        if (empty($ids) || !is_array($ids)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $paramNames = [];

        foreach ($ids as $index => $id) {
            // Generar un nombre de parámetro único para la iteración
            $paramName = sprintf('batchId_%s_%d', $rootAlias, $index);
            $paramNames[] = ':' . $paramName;

            // Forzamos el casteo a 'uuid' nativo de Doctrine para soportar el formato binario
            $queryBuilder->setParameter($paramName, $id, 'uuid');
        }

        $queryBuilder->andWhere(sprintf('%s.id IN (%s)', $rootAlias, implode(',', $paramNames)));
    }
}