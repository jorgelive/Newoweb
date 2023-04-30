<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class ServicioTipocomponenteAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('dependeduracion', null, [
                'label' => 'Depende de duración'
            ])
            ->add('agendable', null, [
                'label' => 'Mostrar en agenda'
            ])
            ->add('prioridadparaproveedor', null, [
                'label' => 'Prioridad para proveedor'
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
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('dependeduracion', null, [
                'editable' => true,
                'label' => 'Depende de duración'
            ])
            ->add('agendable', null, [
                'editable' => true,
                'label' => 'Mostrar en agenda'
            ])
            ->add('prioridadparaproveedor', null, [
                'editable' => true,
                'label' => 'Prioridad para proveedor'
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
            ->add('nombre')
            ->add('dependeduracion', null, [
                'label' => 'Depende de duración'
            ])
            ->add('agendable', null, [
                'label' => 'Mostrar en agenda'
            ])
            ->add('prioridadparaproveedor', null, [
                'label' => 'Prioridad para proveedor'
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
            ->add('nombre')
            ->add('dependeduracion', null, [
                'label' => 'Depende de duración'
            ])
            ->add('agendable', null, [
                'label' => 'Mostrar en agenda'
            ])
            ->add('prioridadparaproveedor', null, [
                'label' => 'Prioridad para proveedor'
            ])
        ;
    }
}
