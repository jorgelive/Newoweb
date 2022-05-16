<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class UserCuentaAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('user.dependencia', null, [
                'label' => 'Dependencia'
            ])
            ->add('user.area', null, [
                'label' => 'Area'
            ])
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('cuentatipo', null, [
                'label' => 'Tipo de cuenta'
            ])
            ->add('nombre', null, [
                'label' => 'Nombre'
            ])
            ->add('password',null, [
                'label' => 'Contraseña'
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
            ->add('user.dependencia', null, [
                'label' => 'Dependencia'
            ])
            ->add('user.area', null, [
                'label' => 'Area'
            ])
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('cuentatipo', null, [
                'label' => 'Tipo de cuenta'
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('password', null, [
                'editable' => true,
                'label' => 'Contraseña'
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
                'label' => 'Usuario'
            ])
            ->add('cuentatipo', null, [
                'label' => 'Contraseña'
            ])
            ->add('nombre')
            ->add('password', null, [
                'label' => 'Contraseña'
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
            ->add('user.dependencia', null, [
                'label' => 'Dependencia'
            ])
            ->add('user.area', null, [
                'label' => 'Area'
            ])
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('cuentatipo', null, [
                'label' => 'Tipo de cuenta'
            ])
            ->add('nombre')
            ->add('password', null, [
                'label' => 'Contraseña'
            ])
        ;
    }
}
