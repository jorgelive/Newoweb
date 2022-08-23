<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ServicioComponenteitemAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('nomostrartarifa', null, [
                'label' => 'No mostrar tar'
            ])
            ->add('nomostrarmodalidadtarifa', null, [
                'label' => 'No mostrar mod'
            ])
            ->add('nomostrarcategoriatour', null, [
                'label' => 'No mostrar cat'
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
            ->add('tarifa', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tarifa']]
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('nomostrartarifa', null, [
                'label' => 'No mostrar tar'
            ])
            ->add('nomostrarmodalidadtarifa', null, [
                'label' => 'No mostrar mod'
            ])
            ->add('nomostrarcategoriatour', null, [
                'label' => 'No mostrar cat'
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
        if($this->getRoot()->getClass() != 'App\Entity\ServicioComponente'
        ){
            $formMapper->add('componente');
        }

        $formMapper
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('nomostrartarifa', null, [
                'label' => 'No mostrar tar'
            ])
            ->add('nomostrarmodalidadtarifa', null, [
                'label' => 'No mostrar mod'
            ])
            ->add('nomostrarcategoriatour', null, [
                'label' => 'No mostrar cat'
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr' => [
                        'style' => 'width: 300px;'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\ServicioComponente'
                ){
                    $widthModifier($event->getForm());
                }
            }
        );

    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('nomostrartarifa', null, [
                'label' => 'No mostrar tar'
            ])
            ->add('nomostrarmodalidadtarifa', null, [
                'label' => 'No mostrar mod'
            ])
            ->add('nomostrarcategoriatour', null, [
                'label' => 'No mostrar cat'
            ])
        ;
    }
}
