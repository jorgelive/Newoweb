<?php

declare(strict_types=1);

namespace App\Panel\Filter;

use App\Travel\Entity\TravelItinerario;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\EntityFilterType;

final class ItinerarioPlantillaFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName, ?string $label = null): self
    {
        $filter = new self();
        $filter->setFilterFqcn(self::class);
        $filter->setProperty($propertyName);
        $filter->setLabel($label);
        $filter->setFormType(EntityFilterType::class);
        $filter->setFormTypeOption('translation_domain', 'EasyAdminBundle');
        $filter->setFormTypeOption('value_type_options', [
            'class' => TravelItinerario::class,
            'choice_label' => 'nombreInterno',
        ]);
        $filter->setFormTypeOption('autocomplete', true);

        return $filter;
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $relAlias = 'segRelItinFiltro';

        $existingJoins = $queryBuilder->getDQLPart('join');
        $alreadyJoined = false;
        foreach ($existingJoins[$rootAlias] ?? [] as $join) {
            if ($join->getAlias() === $relAlias) {
                $alreadyJoined = true;
                break;
            }
        }
        if (!$alreadyJoined) {
            $queryBuilder->leftJoin(sprintf('%s.itinerarioSegmentosInyectados', $rootAlias), $relAlias);
        }

        $queryBuilder
            ->andWhere(sprintf('%s.itinerario = :itinFiltroValor', $relAlias))
            ->setParameter('itinFiltroValor', $filterDataDto->getValue());
    }
}