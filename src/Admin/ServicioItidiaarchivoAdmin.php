<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ServicioItidiaarchivoAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('itinerariodia', null, [
                    'label' => 'Dia de itineratio'
                ]
            )
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('itinerariodia', null, [
                    'label' => 'Dia de itineratio'
                ]
            )
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => []
                ]
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {

        if ($this->getRoot()->getClass() != 'App\Entity\ServicioItinerariodia' &&
            $this->getRoot()->getClass() != 'App\Entity\ServicioItinerario' &&
            $this->getRoot()->getClass() != 'App\Entity\ServicioServicio'
        ){
            $formMapper->add('itinerariodia', null, [
                'label' => 'Dia de itineratio'
                ]
            );
        }
        $formMapper
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
        ;
    }

}
