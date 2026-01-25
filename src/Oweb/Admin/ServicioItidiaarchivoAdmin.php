<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class ServicioItidiaarchivoAdmin extends AbstractSecureAdmin
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
            ->add('itinerariodia', null, [
                    'label' => 'Dia de itineratio'
                ]
            )
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
            ->add('portada',  null, [
                'label' => 'Portada'
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
            ->add('itinerariodia', null, [
                    'label' => 'Dia de itineratio'
                ]
            )
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad', null, [
                'editable' => true
            ])
            ->add('portada',  null, [
                'label' => 'Portada',
                'editable' => true
            ])
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

        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioItinerariodia' &&
            $this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioItinerario' &&
            $this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioServicio'
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
            ->add('portada',  null, [
                'label' => 'Portada'
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
            ->add('medio', null, [
                'label' => 'Multimedia'
            ])
            ->add('prioridad')
            ->add('portada',  null, [
                'label' => 'Portada'
            ])
        ;
    }

}
