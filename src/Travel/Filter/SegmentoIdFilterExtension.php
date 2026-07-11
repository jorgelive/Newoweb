<?php

declare(strict_types=1);

namespace App\Travel\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Filtro dedicado para permitir `?id[]=uuid1&id[]=uuid2` sobre TravelSegmento.
 * Necesario porque el id es UUID binario (UUID_TO_BIN) y el SearchFilter nativo
 * no queda registrado automáticamente sin declaración explícita — mismo patrón
 * ya resuelto para tarifas en TarifaComponenteExtension.
 */
final class SegmentoIdFilterExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($resourceClass !== TravelSegmento::class) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $ids = $request?->query->all('id');

        if (!$ids || !is_array($ids)) {
            return;
        }

        $uuids = [];
        foreach ($ids as $id) {
            try {
                $uuids[] = Uuid::fromString($id)->toBinary();
            } catch (\Throwable) {
                continue;
            }
        }

        if (empty($uuids)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.id IN (:ids)', $alias))
            ->setParameter('ids', $uuids);
    }
}