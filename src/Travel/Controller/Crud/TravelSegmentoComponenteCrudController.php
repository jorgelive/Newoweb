<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteItemModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombre');

        // 2. El contexto
        yield AssociationField::new('servicioContexto', 'Condicionado al Tour')
            ->setHelp('Si se deja vacío, aplica a TODOS los tours.')
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombreInterno');

        // 3. La Hora
        yield TimeField::new('hora', 'Hora de Ejecución')
            ->setFormat('HH:mm')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-success font-monospace',
                    'style'           => 'cursor: pointer;'
                ],
            ])
            ->setColumns('col-12 col-md-4');

        // 4. 🔥 NUEVO: Modo de Inclusión (Enum)
        yield ChoiceField::new('modo', 'Modo Comercial')
            ->setChoices(array_reduce(ComponenteItemModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteItemModoEnum ? $value->value : $value)
            ->setColumns('col-12 col-md-4');

        // 5. Orden
        yield IntegerField::new('orden', 'Orden de Aparición')
            ->setColumns('col-12 col-md-4')
            ->hideOnIndex();
    }
}