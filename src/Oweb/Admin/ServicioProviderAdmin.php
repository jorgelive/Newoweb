<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;


class ServicioProviderAdmin extends AbstractSecureAdmin
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
            ->add('nombre')
            ->add('nombremostrar', null, [
                'label' => 'Nombre para mostrar'
            ])
            ->add('direccion', null, [
                'label' => 'Dirección'
            ])
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('email', null, [
                'label' => 'E-Mail'
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
            ->add('nombremostrar', null, [
                'label' => 'Nombre para mostrar',
                'editable' => true
            ])
            ->add('direccion', null, [
                'label' => 'Dirección',
                'editable' => true
            ])
            ->add('telefono', null, [
                'label' => 'Teléfono',
                'editable' => true
            ])
            ->add('email', null, [
                'label' => 'E-Mail',
                'editable' => true
            ])
            ->add('providermedios', null, [
                'label' => 'Multimedia'
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
            ->add('nombremostrar', null, [
                'label' => 'Nombre para mostrar'
            ])
            ->add('direccion', null, [
                'label' => 'Dirección'
            ])
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('email', null, [
                'label' => 'E-Mail'
            ])
            ->add('providermedios', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Multimedia',
            ], [
                'edit' => 'inline',
                'inline' => 'table'
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
            ->add('nombremostrar', null, [
                'label' => 'Nombre para mostrar'
            ])
            ->add('direccion', null, [
                'label' => 'Dirección'
            ])
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('email', null, [
                'label' => 'E-Mail'
            ])
            ->add('providermedios', null, [
                'label' => 'Multimedia'
            ])
        ;
    }
}
