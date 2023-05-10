<?php

namespace App\Admin;

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

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', TranslationFieldFilter::class, [
                'label' => 'Descripción'
            ])
            ->add('referencia', TranslationFieldFilter::class, [
                'label' => 'Referencia de ubicación'
            ])
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, [
                'label' => 'Descripción',
                'editable' => true
            ])
            ->add('referencia', null, [
                'label' => 'Referencia de ubicación',
                'editable' => true
            ])
            ->add('unitnexos', null, [
                'label' => 'Nexos'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'resumen' => [
                        'template' => 'reserva_unit_admin\list__action_resumen.html.twig'
                    ],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'reserva_unit_admin/list__action_traducir.html.twig'
                    ]
                ],
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('referencia', null, [
                'label' => 'Referencia de ubicación'
            ])
            ->add('unitnexos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Nexos'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('unitcaracteristicas', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Caracteristicas',
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('unitmedios', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Multimedia',
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('establecimiento')
            ->add('establecimiento.direccion', null, [
                'label' => 'Dirección',
                'template' => 'base_sonata_admin/show_map.html.twig',
                'zoom' => 17
            ])
            ->add('nombre')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('referencia', null, [
                'label' => 'Referencia de ubicación'
            ])
            ->add('unitnexos', null, [
                'label' => 'Nexos'
            ])
            ->add('unitcaracteristicas', null, [
                'label' => 'Caracteristicas'
            ])
            ->add('unitmedios', null, [
                'label' => 'Multimedia'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('ical', $this->getRouterIdParameter() . '/ical');
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen');
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');

    }
}
