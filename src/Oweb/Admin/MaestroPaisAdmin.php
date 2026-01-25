<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class MaestroPaisAdmin extends AbstractSecureAdmin
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
            ->add('iso2', null, [
                'label' => 'ISO2'
            ])
            ->add('codigomc',  null, [
                'label' => 'Código MC'
            ])
            ->add('codigopr',  null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon',  null, [
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
            ->add('iso2', null, [
                'label' => 'ISO2',
                'editable' => true
            ])
            ->add('codigomc',  null, [
                'label' => 'Código MC',
                'editable' => true
            ])
            ->add('codigopr',  null, [
                'label' => 'Código PR',
                'editable' => true
            ])
            ->add('codigocon',  null, [
                'label' => 'Código CON',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, array(
                'actions' => array(
                    'show' => array(),
                    'edit' => array(),
                    'delete' => array(),
                ),
            ))
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('nombre')
            ->add('iso2', null, [
                'label' => 'ISO2',
                'help' => 'Código ISO 3166-1 alfa-2 (PE, ES, FR, etc.)'
            ])
            ->add('codigomc',  null, [
                'label' => 'Código MC'
            ])
            ->add('codigopr',  null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon',  null, [
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
            ->add('iso2', null, [
                'label' => 'ISO2'
            ])
            ->add('codigomc',  null, [
                'label' => 'Código MC'
            ])
            ->add('codigopr',  null, [
                'label' => 'Código PR'
            ])
            ->add('codigocon',  null, [
                'label' => 'Código CON'
            ])
        ;
    }
}
