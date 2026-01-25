<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;

class ReservaUnitnexoAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'RESERVAS';
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit',  null, [
                'label' => 'Unidad'
            ])
            ->add('channel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('distintivo', null, [
                'label' => 'Distintivo'
            ])
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
            ->add('channel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace', null, [
                'editable' => true
            ])
            ->add('distintivo', null, [
                'label' => 'Distintivo',
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
                        'template' => 'oweb/admin/reserva_unitnexo/list__action_ical_clipboard.html.twig'
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
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ReservaUnit'){

        $formMapper
            ->add('unit',  null, [
                'label' => 'Unidad'
            ]);
        }

        $formMapper
            ->add('channel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('distintivo', null, [
                'label' => 'Distintivo'
            ])
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
            ->add('channel', null, [
                'label' => 'Canal'
            ])
            ->add('enlace')
            ->add('distintivo', null, [
                'label' => 'Distintivo'
            ])
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

    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return 'app/reservaunitnexo';
    }

}
