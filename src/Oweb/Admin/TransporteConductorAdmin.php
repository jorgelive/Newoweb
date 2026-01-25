<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class TransporteConductorAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('user', null, [
                'label' => 'Nombre'
            ])
            ->add('licencia')
            ->add('abreviatura')
            ->add('color')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('user.fullname', null, [
                'label' => 'Nombre'
            ])
            ->add('licencia', null, [
                'editable' => true
            ])
            ->add('abreviatura', null, [
                'editable' => true
            ])
            ->add('color', null, [
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
            ->add('user', null, [
                'required' => true,
                'choice_label' => 'fullname',
                'label' => 'Nombre'
            ])
            ->add('licencia')
            ->add('abreviatura')
            ->add('color')
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('user.fullname', null, [
                'label'=>'Nombre'
            ])
            ->add('user.phone', null, [
                'label' => 'Teléfono'
            ])
            ->add('licencia')
            ->add('abreviatura')
            ->add('color')
        ;
    }

}
