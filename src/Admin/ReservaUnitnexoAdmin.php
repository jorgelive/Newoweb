<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaUnitnexoAdmin extends AbstractAdmin
{

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit',  null, [
                'label' => 'Unidad'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('related', null, [
                'label' => 'Nexos relacionados'
            ])
            ->add('deshabilitado')
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unit',  null, [
                'label' => 'Unidad'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace', null, [
                'editable' => true
            ])
            ->add('related', null, [
                'label' => 'Nexos relacionados',
                'editable' => true
            ])
            ->add('deshabilitado')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'ical' => [
                        'template' => 'reserva_unitnexo_admin\list__action_ical_clipboard.html.twig'
                    ],
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('unit',  null, [
                'label' => 'Unidad'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('related', null, [
                'label' => 'Nexos relacionados',
                'help' => 'Separados por coma'
            ])
            ->add('deshabilitado')
        ;
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('unit',  null, [
                'label' => 'Unidad'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('related', null, [
                'label' => 'Nexos relacionados'
            ])
            ->add('deshabilitado')
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('ical', $this->getRouterIdParameter() . '/ical');
        $collection->add('icalics', $this->getRouterIdParameter() . '/ical.ics');
    }
}
