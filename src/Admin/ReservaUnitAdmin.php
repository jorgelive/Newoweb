<?php

namespace App\Admin;

use App\Form\ReservaUnitCaracteristicaLinkType;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaUnitAdmin extends AbstractAdmin
{
    public function configure(): void
    {
        $this->classnameLabel = "Unidad";
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', TranslationFieldFilter::class, ['label' => 'Descripción'])
            ->add('referencia', TranslationFieldFilter::class, ['label' => 'Referencia de ubicación'])
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción', 'editable' => true])
            ->add('referencia', null, ['label' => 'Referencia de ubicación', 'editable' => true])
            ->add('unitCaracteristicaLinks', null, [
                'label' => 'Características (vínculos)',
                'associated_property' => function ($link) {
                    return sprintf('%s (p:%s)',
                        (string) $link->getCaracteristica(),
                        $link->getPrioridad() ?? '-'
                    );
                },
            ])
            ->add('unitnexos', null, ['label' => 'Nexos'])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'resumen' => ['template' => 'admin/reserva_unit/list__action_resumen.html.twig'],
                    'inventario' => ['template' => 'admin/reserva_unit/list__action_inventario.html.twig'],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => ['template' => 'admin/reserva_unit/list__action_traducir.html.twig'],
                ],
            ])
        ;
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción'])
            ->add('referencia', null, ['label' => 'Referencia de ubicación'])

            ->add('unitCaracteristicaLinks', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Características',
                'required' => false,
                'btn_add' => 'Agregar vínculo',
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                'sortable' => 'prioridad',
            ])

            // Nexos al final (si lo tienes igual que antes)
            ->add('unitnexos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Nexos',
                'required' => false,
                'btn_add' => 'Agregar nexo',
                'type_options' => ['delete' => true],
                'modifiable' => true,
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                'sortable' => 'prioridad',
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('establecimiento')
            ->add('establecimiento.direccion', null, [
                'label' => 'Dirección',
                'template' => 'admin/base_sonata/show_map.html.twig',
                'zoom' => 17,
            ])
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción'])
            ->add('referencia', null, ['label' => 'Referencia de ubicación'])
            ->add('unitCaracteristicaLinks', null, ['label' => 'Características'])
            ->add('unitnexos', null, ['label' => 'Nexos'])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('icalics', $this->getRouterIdParameter() . '/ical.ics');
        $collection->add('ical', $this->getRouterIdParameter() . '/ical');
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen');
        $collection->add('inventario', $this->getRouterIdParameter() . '/inventario');
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
