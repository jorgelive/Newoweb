<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Helper\AdminFieldHelper;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

/**
 * Controlador CRUD encargado de gestionar la relación asociativa entre Segmentos de Itinerario
 * y sus componentes logísticos / tarifas asignadas.
 */
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

        /* ====================================================================
         * FILA 1: COMPONENTE LOGÍSTICO (GATILLO AJAX) Y CONTEXTO DE PLANTILLA
         * Se pasan explícitamente los parámetros para cumplir el contrato agnóstico.
         * ==================================================================== */
        yield AdminFieldHelper::controlsAjax(
            AssociationField::new('componente', 'Componente Logístico'),
            'js-tarifa-api-target',
            $endpointUrl,
            'componente_id',
            'nombreInterno'
        )
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombre');

        yield AssociationField::new('itinerarioContexto', 'Condicionado a Plantilla')
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombreInterno');

        /* ====================================================================
         * FILA 2: TARIFA PREDETERMINADA (TARGET AJAX)
         * ==================================================================== */
        yield AssociationField::new('tarifaPredeterminada', 'Tarifa (Opcional)')
            ->autocomplete()
            ->setColumns('col-12')
            ->setFormTypeOptions([
                'attr' => [
                    'class' => 'js-tarifa-api-target'
                ],
            ]);

        /* ====================================================================
         * FILA 3: CONFIGURACIÓN HORARIA, MODO COMERCIAL Y FILTROS OPERATIVOS
         * ==================================================================== */
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
            ->setColumns('col-12 col-md-2');

        yield TimeField::new('horaFin', 'Hora Fin')
            ->setFormat('HH:mm')
            ->setColumns('col-12 col-md-2')
            ->setHelp('Dejar vacío para usar la duración por defecto.')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-danger font-monospace',
                    'style'           => 'cursor: pointer;'
                ],
            ]);

        yield IntegerField::new('dia', 'Día (Filtro)')
            ->setHelp('Día específico en la plantilla para aplicar esta logística (Ej: 2). Dejar vacío para aplicar todos los días.')
            ->setColumns('col-12 col-md-2')
            ->setFormTypeOptions([
                'attr' => ['placeholder' => 'Global']
            ]);

        yield ChoiceField::new('modo', 'Modo Comercial')
            ->setChoices(array_reduce(ComponenteModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteModoEnum ? $value->value : $value)
            ->setFormTypeOptions([
                'placeholder' => false
            ])
            ->setColumns('col-12 col-md-3');

        yield IntegerField::new('orden', 'Orden')
            ->setColumns('col-12 col-md-3')
            ->setFormTypeOptions([
                'empty_data' => '1',
                'attr'       => [
                    'placeholder' => '1',
                    'data-default'    => '1',
                ],
            ])
            ->hideOnIndex();
    }
}