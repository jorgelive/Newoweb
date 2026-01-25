<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaEstadoAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'RESERVAS';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('habilitarResumenPublico', null, [
                'label' => 'Habilitar características en resumen público',
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
            ->add('color', null, [
                'editable' => true
            ])
            ->add('colorcalendar',  null, [
                'label' => 'Color de calendario',
                'editable' => true
            ])
            ->add('habilitarResumenPublico', null, [
                'label' => 'Habilitar características en resumen público',
                'editable' => true
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
            ->add('color')
            ->add('colorcalendar',  null, [
                'label' => 'Color de calendario'
            ])
            ->add('habilitarResumenPublico', null, [
                'required' => false,
                'label' => 'Habilitar características en resumen público',
                'help' => 'Si está activo, en la vista pública se muestran también los TIPOS marcados como restringidos.',
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
            ->add('color')
            ->add('colorcalendar',  null, [
                'label' => 'Color de calendario'
            ])
            ->add('habilitarResumenPublico', null, [
                'label' => 'Habilitar características en resumen público',
            ])
        ;
    }
}
