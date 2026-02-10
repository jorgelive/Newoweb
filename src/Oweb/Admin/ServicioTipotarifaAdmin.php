<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class ServicioTipotarifaAdmin extends AbstractSecureAdmin
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
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('listacolor', null, [
                'label' => 'Color'
            ])
            ->add('listaclase', null, [
                'label' => 'Clase'
            ])
            ->add('comisionable')
            ->add('ocultoenresumen', null, [
                'label' => 'Oculto en resumen'
            ])
            ->add('mostrarcostoincluye', null, [
                'label' => 'Costo en incluye'
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
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('listacolor', null, [
                'label' => 'Color',
                'editable' => true
            ])
            ->add('listaclase', null, [
                'label' => 'Clase',
                'editable' => true
            ])
            ->add('comisionable', null, [
                'editable' => true
            ])
            ->add('ocultoenresumen', null, [
                'label' => 'Oculto en resumen',
                'editable' => true
            ])
            ->add('mostrarcostoincluye', null, [
                'editable' => true,
                'label' => 'Costo en incluye'
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
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('listacolor', null, [
                'label' => 'Color'
            ])
            ->add('listaclase', null, [
                'label' => 'Clase'
            ])
            ->add('comisionable')
            ->add('ocultoenresumen', null, [
                'label' => 'Oculto en resumen'
            ])
            ->add('mostrarcostoincluye', null, [
                'label' => 'Costo en incluye'
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
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('listacolor', null, [
                'label' => 'Color'
            ])
            ->add('listaclase', null, [
                'label' => 'Clase'
            ])
            ->add('comisionable')
            ->add('ocultoenresumen', null, [
                'label' => 'Oculto en resumen'
            ])
            ->add('mostrarcostoincluye', null, [
                'label' => 'Costo en incluye'
            ])
        ;
    }
}
