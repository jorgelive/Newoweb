<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class MaestroTipodocumentoAdmin extends AbstractSecureAdmin
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('nombremc', null, [
                'label' => 'Nombre MC'
            ])
            ->add('codigomc', null, [
                'label' => 'Código MC'
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
            ->add('nombremc', null, [
                'label' => 'Nombre MC',
                'editable' => true
            ])
            ->add('codigomc', null, [
                'label' => 'Código MC',
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
            ->add('nombremc', null, [
                'label' => 'Nombre MC'
            ])
            ->add('codigomc', null, [
                'label' => 'Código MC'
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
            ->add('nombremc', null, [
                'label' => 'Nombre MC'
            ])
            ->add('codigomc', null, [
                'label' => 'Código MC'
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
