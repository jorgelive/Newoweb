<?php

namespace App\Admin;


use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;

use Sonata\Form\Type\DatePickerType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class FitDietacomidaAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('numerocomida', null, [
                'label' => 'Número de comida'
            ])
            ->add('nota', null, [
                'label' => 'Nota'
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
            ->add('numerocomida', null, [
                'label' => 'Número de comida'
            ])
            ->add('nota', null, [
                'label' => 'Nota'
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
        if ($this->getRoot()->getClass() != 'App\Entity\FitDieta'
        ){
            $formMapper->add('dieta', null, [
                'label' => 'Dieta'
            ]);
        }

        $formMapper
            ->add('numerocomida', null, [
                    'label' => 'Número de comida'
            ])
            ->add('nota', null, [
                'label' => 'Nota'
            ])
            ->add('dietaalimentos', CollectionType::class , [
                'by_reference' => false,
                'label' => 'Alimentos'
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


        $showMapper->add('dieta', null, [
            'label' => 'Dieta'
            ])
            ->add('numerocomida', null, [
                'label' => 'Número de comida'
            ])
            ->add('nota', null, [
                'label' => 'Nota'
            ])
            ->add('dietaalimentos', null , [
                'label' => 'Alimentos'
            ])
        ;

    }
}
