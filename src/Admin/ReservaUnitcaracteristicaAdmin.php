<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaUnitcaracteristicaAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit')
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', TranslationFieldFilter::class, [

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
            ->add('unit')
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', null, [
                'safe' => true
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
        if($this->getRoot()->getClass() != 'App\Entity\ReservaUnit'){
            $formMapper->add('unit', null, [
                'label' => 'Unidad'
            ]);
        }

        $formMapper
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
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
            ->add('unit')
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', null, [
                'safe' => true
            ])
        ;
    }
}
