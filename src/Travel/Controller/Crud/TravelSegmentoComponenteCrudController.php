<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Helper\AdminFieldHelper;
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
        return $crud->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        $apiHostUrl = rtrim($this->getParameter('api_host_url'), '/');
        $endpointUrl = $apiHostUrl . '/platform/travel/tarifas';

        // FILA 1: Componente Logístico y Condicionado a Plantilla (50% cada uno)
        yield AdminFieldHelper::controlsAjax(
            AssociationField::new('componente', 'Componente Logístico'),
            'js-tarifa-api-target',
            $endpointUrl
        )->setColumns('col-12 col-md-6')->setFormTypeOption('choice_label', 'nombre');

        yield AssociationField::new('itinerarioContexto', 'Condicionado a Plantilla')
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombreInterno');

        // FILA 2: Tarifa (100% del ancho para acomodar el texto descriptivo largo)
        yield AssociationField::new('tarifaPredeterminada', 'Tarifa (Opcional)')
            ->autocomplete()
            ->setColumns('col-12')
            ->setFormTypeOptions([
                'attr' => [
                    'class' => 'js-tarifa-api-target'
                ],
            ]);

        // FILA 3: Horarios, Modo y Orden (25% cada uno)
        yield TimeField::new('hora', 'Hora Inicio')
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
            ->setColumns('col-12 col-md-3');

        yield TimeField::new('horaFin', 'Hora Fin')
            ->setFormat('HH:mm')
            ->setHelp('Dejar vacío para usar la duración por defecto.')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-danger font-monospace',
                    'style'           => 'cursor: pointer;'
                ],
            ])
            ->setColumns('col-12 col-md-3');

        yield ChoiceField::new('modo', 'Modo Comercial')
            ->setChoices(array_reduce(ComponenteItemModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteItemModoEnum ? $value->value : $value)
            ->setFormTypeOptions([
                'placeholder' => false
            ])
            ->setColumns('col-12 col-md-3');

        yield IntegerField::new('orden', 'Orden')
            ->setColumns('col-12 col-md-3')
            ->setFormTypeOptions([
                'empty_data' => '1',
                'attr'       => [
                    'placeholder'     => '1',
                    'data-default'    => '1',
                ],
            ])
            ->hideOnIndex();
    }
}