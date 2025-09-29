<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaUnittipocaracteristicaAdmin extends AbstractAdmin
{

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('iconcolor', null, [
                'label' => 'Color del ícono'
            ])
            ->add('iconclase', null, [
                'label' => 'Clase del ícono'
            ])
            ->add('restringidoEnResumen', null, [
                'label'    => 'Restringida en RESUMEN'
            ])
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('iconcolor', null, [
                'editable' => true,
                'label' => 'Color del ícono'
            ])
            ->add('iconclase', null, [
                'editable' => true,
                'label' => 'Clase del ícono'
            ])
            ->add('restringidoEnResumen', null, [
                'editable' => true,
                'label'    => 'Restringida en RESUMEN'
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

    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('iconcolor', null, [
                'label' => 'Color del ícono'
            ])
            ->add('iconclase', null, [
                'label' => 'Clase del ícono'
            ])
            ->add('restringidoEnResumen', null, [
                'required' => false,
                'label'    => 'Restringida en RESUMEN',
                'help'     => 'Si está activo, esta característica SOLO se mostrará en la vista pública cuando el estado habilite el resumen.',
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('iconcolor', null, [
                'label' => 'Color del ícono'
            ])
            ->add('iconclase', null, [
                'label' => 'Clase del ícono'
            ])
            ->add('restringidoEnResumen', null, [
                'label'    => 'Restringida en resumen'
            ])
        ;
    }
}
