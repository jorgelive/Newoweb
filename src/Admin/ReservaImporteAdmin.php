<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;

class ReservaImporteAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('fecha')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('fecha')
            ->add('moneda')
            ->add('monto')
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
                'label' => 'Fin',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd',
                'dp_default_date' => $ahora->format('Y-m-d')
            ])
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('moneda')
            ->add('monto')
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
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('fecha')
            ->add('moneda')
            ->add('monto')
            ->add('nota')
        ;
    }
}
