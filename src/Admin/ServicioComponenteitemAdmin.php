<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ServicioComponenteitemAdmin extends AbstractAdmin
{

    public function configure(): void
    {
        $this->classnameLabel = "Componente Item";
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('Componente', null, [
                'label' => 'Componente'
            ])
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
            ->add('Componente', null, [
                'label' => 'Componente'
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('nomostrartarifa', null, [
                'label' => 'No mostrar tar',
                'editable' => true
            ])
            ->add('nomostrarmodalidadtarifa', null, [
                'label' => 'No mostrar mod',
                'editable' => true
            ])
            ->add('nomostrarcategoriatour', null, [
                'label' => 'No mostrar cat',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'servicio_componenteitem_admin/list__action_traducir.html.twig'
                    ]
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

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
