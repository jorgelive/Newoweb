<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class TravelOrganizacionServicioPorOrganizacionExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (TravelOrganizacionServicio::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $organizacionId = $request->query->get('organización_id') ?? $request->query->get('organización.id');

        if ($organizacionId) {
            $rootAlias = $queryBuilder->getRootAliases()[0];
            $parameterName = $queryNameGenerator->generateParameterName('organizacionId');

            try {
                $uuidObject = Uuid::fromString((string) $organizacionId);
            } catch (\InvalidArgumentException $e) {
                return;
            }

            $queryBuilder->andWhere(sprintf('%s.organizacion = :%s', $rootAlias, $parameterName))
                ->setParameter($parameterName, $uuidObject, 'uuid');
        }
    }
}