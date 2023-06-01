<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ReservaUnitcaracteristicaAdmin extends AbstractAdmin
{

    public function configure(): void
    {
        $this->classnameLabel = "Unidad caracteristica";
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit')
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', TranslationFieldFilter::class, [])
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unit')
            ->add('unittipocaracteristica', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido', null, [
                'template' => 'base_sonata_admin/list_html.html.twig'
            ])
            ->add('prioridad',null, [
                'editable' => true
            ])
        ;
        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $listMapper
                ->add('contenidooriginal', null, [
                    'label' => 'Contenido original',
                    'template' => 'base_sonata_admin/list_html.html.twig'
                ]);
        }
        $listMapper
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'reserva_unitcaracteristica_admin/list__action_traducir.html.twig'
                    ]
                ],
            ]);
    }

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
            ->add('prioridad')
        ;

        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $formMapper
                ->add('contenidooriginal', null, [
                    'label' => 'Contenido original',
                    'attr' => ['class' => 'ckeditorread'],
                    'disabled' => true
                ]);
        }

    }

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
            ->add('prioridad')
        ;
        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $showMapper
                ->add('contenidooriginal', null, [
                    'label' => 'Contenido original',
                    'safe' => true
                ]);
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
