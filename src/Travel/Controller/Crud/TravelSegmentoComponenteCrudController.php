<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelSegmentoComponente;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

class TravelSegmentoComponenteCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmentoComponente::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        // 1. El Componente que se cobra
        yield AssociationField::new('componente', 'Componente Logístico')
            ->setColumns(4);

        // 2. 🔥 NUEVO: El contexto. ¿A qué tour le aplica esta regla?
        yield AssociationField::new('servicioContexto', 'Condicionado al Tour')
            ->setHelp('Si lo dejas vacío, esta regla aplicará a TODOS los tours que usen este segmento.')
            ->setColumns(3);

        // 3. La Hora
        yield TimeField::new('hora', 'Hora')
            ->setFormat('HH:mm')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-success font-monospace',
                    'style'           => 'max-width: 100px; cursor: pointer;'
                ],
            ])
            ->setColumns(2);

        // 4. Inclusión
        yield BooleanField::new('esIncluido', '¿Incluido?')
            ->setColumns(2);

        // 5. Orden
        yield IntegerField::new('orden', 'Orden')
            ->setColumns(1)
            ->hideOnIndex();
    }
}