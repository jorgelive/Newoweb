<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;


class ServicioItinerariodiaAdmin extends AbstractAdmin
{

    public function configure(): void
    {
        $this->classnameLabel = "Día de itinerario";
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('itinerario')
            ->add('itinerario.servicio', null, [
                'label' => 'Servicio'
            ])
            ->add('notaitinerariodia', null, [
                'label' => 'Nota'
            ])
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
            ->add('itinerario', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'itinerario']]
            ])
            ->add('notaitinerariodia', null, [
                'label' => 'Nota',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'notaitinerariodia']],
            ])
            ->add('dia')
            ->add('titulo', null, [
                'label' => 'Título'
            ]);

        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $listMapper
                ->add('titulooriginal', null, [
                    'label' => 'Título original',
                    'template' => 'base_sonata_admin/list_html.html.twig'
                ]);
        }

        $listMapper->add('importante')
            ->add('contenido', null, [
                'template' => 'base_sonata_admin/list_html.html.twig'
            ]);

        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $listMapper
                ->add('contenidooriginal', null, [
                    'label' => 'Contenido original',
                    'template' => 'base_sonata_admin/list_html.html.twig'
                ]);
        }
        $listMapper->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'servicio_itinerariodia_admin/list__action_traducir.html.twig'
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
        if($this->getRoot()->getClass() != 'App\Entity\ServicioServicio'
            && $this->getRoot()->getClass() != 'App\Entity\ServicioItinerario'
        ){
            $formMapper->add('itinerario');
        }
        $formMapper
            ->add('notaitinerariodia', null, [
                'label' => 'Nota'
            ])
            ->add('dia')
            ->add('titulo', null, [
                'label' => 'Título'
            ]);
            if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()){
                $formMapper
                    ->add('titulooriginal', null, [
                        'label' => 'Título original',
                        'disabled' => true
                    ]);
            }
            $formMapper->add('importante')
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
            ]);

            if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
                $formMapper
                    ->add('contenidooriginal', null, [
                        'label' => 'Contenido original',
                        'attr' => ['class' => 'ckeditorread'],
                        'disabled' => true
                    ]);
            }

            $formMapper->add('itidiaarchivos', CollectionType::class, [
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
            ->add('notaitinerariodia', null, [
                'label' => 'Nota'
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ]);
            if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()){
                $showMapper
                    ->add('titulooriginal', null, [
                        'label' => 'Título original',
                    ]);
            }
            $showMapper->add('importante')
            ->add('contenido', null, [
                'safe' => true
            ]);
            if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()){
                $showMapper
                    ->add('contenidooriginal', null, [
                        'label' => 'Contenido original',
                        'safe' => true
                    ]);
            }
            $showMapper->add('itidiaarchivos', null, [
                'label' => 'Multimedia',
                'associated_property' => 'medio'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
