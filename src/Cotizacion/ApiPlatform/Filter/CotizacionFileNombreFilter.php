<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Buscador por nombre del dashboard de expedientes: un único query param
 * ("nombre") que matchea por OR contra nombreGrupo y pasajeroPrincipal,
 * algo que SearchFilter no soporta (ANDea propiedades distintas).
 */
final class CotizacionFileNombreFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ('nombre' !== $property || !\is_string($value) || '' === trim($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $param = $queryNameGenerator->generateParameterName('nombre');

        $queryBuilder
            ->andWhere(sprintf(
                '%1$s.nombreGrupo LIKE :%2$s OR %1$s.pasajeroPrincipal LIKE :%2$s',
                $alias,
                $param
            ))
            ->setParameter($param, '%' . $value . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'nombre' => [
                'property'    => 'nombre',
                'type'        => 'string',
                'required'    => false,
                'description' => 'Busca por nombre de grupo o pasajero principal (OR).',
            ],
        ];
    }
}
