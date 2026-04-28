<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Gestiona la selección y orden cronológico de los segmentos dentro de una plantilla.
 */
class TravelItinerarioSegmentoRelCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelItinerarioSegmentoRel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('segmento', 'Segmento del Pool')
            ->setHelp('Selecciona un párrafo narrativo del pool de este servicio.')
            ->setColumns(6);

        yield IntegerField::new('dia', 'Día Relativo')
            ->setHelp('Ej: 1 (Para el primer día)')
            ->setColumns(3);

        yield IntegerField::new('orden', 'Orden de Aparición')
            ->setHelp('Define la secuencia dentro del mismo día.')
            ->setColumns(3);
    }
}