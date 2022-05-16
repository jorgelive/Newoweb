<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;


class ServicioItinerariodiaAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('itinerario')
            ->add('dia')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('importante')
            ->add('contenido', TranslationFieldFilter::class)
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('itinerario')
            ->add('dia')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('importante')
            ->add('contenido')
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
        if ($this->getRoot()->getClass() != 'App\Entity\ServicioServicio'
            && $this->getRoot()->getClass() != 'App\Entity\ServicioItinerario'
        ){
            $formMapper->add('itinerario');
        }
        $formMapper
            ->add('dia')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('importante')
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
            ])
            ->add('itidiaarchivos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Archivos'
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
            ->add('itinerario')
            ->add('dia')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('importante')
            ->add('contenido')
        ;
    }
}
