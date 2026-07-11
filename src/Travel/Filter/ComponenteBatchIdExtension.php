<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\TravelComponente;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class ComponenteBatchIdExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private RequestStack $requestStack) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        // Solo intervenimos si están consultando el recurso TravelComponente
        if (TravelComponente::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Capturamos el array de UUIDs enviado por el frontend vía ?id[]=...&id[]=...
        $ids = $request->query->all('id');

        if (empty($ids) || !is_array($ids)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        // 🔥 Igual que en TarifaComponenteExtension: pasamos 'uuid' como tipo explícito
        // por cada parámetro, para que Doctrine convierta correctamente a BINARY nativo.
        // No usamos setParameter($key, $array, ...) porque el tipo custom 'uuid' no se
        // aplica automáticamente a arrays completos, solo a valores individuales.
        $paramNames = [];
        foreach ($ids as $index => $id) {
            $paramName = 'compBatchId' . $index;
            $paramNames[] = ':' . $paramName;
            $queryBuilder->setParameter($paramName, $id, 'uuid');
        }

        $queryBuilder->andWhere(sprintf('%s.id IN (%s)', $rootAlias, implode(',', $paramNames)));
    }
}