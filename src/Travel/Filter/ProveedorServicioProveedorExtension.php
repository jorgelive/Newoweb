<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\ProveedorServicio;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class ProveedorServicioProveedorExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        if (ProveedorServicio::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $proveedorId = $request->query->get('proveedor_id') ?? $request->query->get('proveedor.id');

        if ($proveedorId) {
            $rootAlias = $queryBuilder->getRootAliases()[0];
            $parameterName = $queryNameGenerator->generateParameterName('proveedorId');

            try {
                $uuidObject = Uuid::fromString((string) $proveedorId);
            } catch (\InvalidArgumentException $e) {
                return;
            }

            $queryBuilder->andWhere(sprintf('%s.proveedor = :%s', $rootAlias, $parameterName))
                ->setParameter($parameterName, $uuidObject, 'uuid');
        }
    }
}