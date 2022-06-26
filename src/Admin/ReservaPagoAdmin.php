<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;

class ReservaPagoAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('fecha')
            ->add('moneda')
            ->add('monto')
            ->add('user', null, [
                'label' => 'Cobrador'
            ])
            ->add('nota')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('fecha')
            ->add('moneda')
            ->add('monto')
            ->add('user', null, [
                'label' => 'Cobrador'
            ])
            ->add('nota')
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
        $ahora = new \DateTime('now');
        $formMapper
            ->add('fecha', DatePickerType::class, [
                'label' => 'Fecha',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd',
                'dp_default_date' => $ahora->format('Y-m-d')
            ])
            ->add('moneda')
            ->add('monto')
            ->add('user', null, [
                'label' => 'Cobrador'
            ])
            ->add('nota')
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('fecha')
            ->add('moneda')
            ->add('monto')
            ->add('user', null, [
                'label' => 'Cobrador'
            ])
            ->add('nota')
        ;
    }
}
