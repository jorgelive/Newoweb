<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class MaestroMonedaAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'MAESTROS';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('simbolo', null, [
                'label' => 'Símbolo'
            ])
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoexterno', null, [
                'label' => 'Código Externo'
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
            ->add('simbolo', null, [
                'editable' => true,
                'label' => 'Símbolo'
            ])
            ->add('codigo', null, [
                'editable' => true,
                'label' => 'Código'
            ])
            ->add('codigoexterno', null, [
                'editable' => true,
                'label' => 'Código Externo'
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
            ->add('simbolo', null, [
                'label' => 'Símbolo'
            ])
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoexterno', null, [
                'label' => 'Código Externo'
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
            ->add('simbolo', null, [
                'label' => 'Símbolo'
            ])
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigoexterno', null, [
                'label' => 'Código Externo'
            ])
        ;
    }

}
