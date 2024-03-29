<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaEstablecimientoAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
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
            ->add('nombre')
            ->add('direccion', null, [
                'label' => 'Dirección',
                'editable' => true
            ])
            ->add('referencia', null, [
                'editable' => true
            ])
            ->add('checkin', null, [
                'label' => 'Check In'
            ])
            ->add('checkout', null, [
                'label' => 'Check Out'
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
            ->add('direccion', null, [
                'label' => 'Dirección'
            ])
            ->add('referencia')
            ->add('checkin', null, [
                'label' => 'Check In'
            ])
            ->add('checkout', null, [
                'label' => 'Check out'
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
            ->add('direccion', null, [
                'label' => 'Dirección'
            ])
            ->add('referencia')
            ->add('checkin', null, [
                'label' => 'Check In'
            ])
            ->add('checkout', null, [
                'label' => 'Check out'
            ])
        ;
    }
}
