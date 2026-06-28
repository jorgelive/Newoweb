<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class TarifaComponenteExtension implements QueryCollectionExtensionInterface
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

        // Capturamos el UUID limpio enviado por Javascript
        $componenteId = $request->query->get('componente_id');

        if ($componenteId) {
            $rootAlias = $queryBuilder->getRootAliases()[0];

            // Al pasar 'uuid' como 3er parámetro, Doctrine activa UuidType y lo convierte a BINARY nativo
            $queryBuilder->andWhere(sprintf('%s.componente = :compId', $rootAlias))
                ->setParameter('compId', $componenteId, 'uuid');
        }
    }
}