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

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
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
            ->add('unitnexos', null, [
                'label' => 'Nexos'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
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
            ->add('nombre')
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
    }
}
