<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class MaestroTipodocumentoAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoddc', null, [
                'label' => 'Código DDC'
            ])
            ->add('codigopr', null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon', null, [
                'label' => 'Código CON'
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
            ->add('codigo', null, [
                'label' => 'Código',
                'editable' => true
            ])
            ->add('codigoddc', null, [
                'label' => 'Código DDC',
                'editable' => true
            ])
            ->add('codigopr', null, [
                'label' => 'Código PR',
                'editable' => true
            ])
            ->add('codigocon', null, [
                'label' => 'Código CON',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, 'actions', [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
                'label' => 'Acciones'
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoddc', null, [
                'label' => 'Código DDC'
            ])
            ->add('codigopr', null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon', null, [
                'label' => 'Código CON'
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoddc', null, [
                'label' => 'Código DDC'
            ])
            ->add('codigopr', null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon', null, [
                'label' => 'Código CON'
            ])
        ;
    }
}
