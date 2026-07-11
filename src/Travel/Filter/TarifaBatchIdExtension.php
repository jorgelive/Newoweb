<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class TarifaBatchIdExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private RequestStack $requestStack) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        // Solo intervenimos si están consultando el recurso TravelTarifa
        if (TravelTarifa::class !== $resourceClass) {
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

        $paramNames = [];
        foreach ($ids as $index => $id) {
            $paramName = 'tarifaBatchId' . $index;
            $paramNames[] = ':' . $paramName;
            $queryBuilder->setParameter($paramName, $id, 'uuid');
        }

        $queryBuilder->andWhere(sprintf('%s.id IN (%s)', $rootAlias, implode(',', $paramNames)));
    }
}