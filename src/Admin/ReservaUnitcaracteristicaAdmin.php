<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\ModelFilter;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\Form\Type\CollectionType;
use Sonata\DoctrineORMAdminBundle\Filter\ModelAutocompleteFilter;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

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
            ->add('nombre', null, ['label' => 'Nombre interno'])
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', TranslationFieldFilter::class, [])
            // 🔎 Filtro por UNIDAD vinculada (autocomplete)
            ->add('links.unit', ModelFilter::class, [
                'label' => 'Unidad vinculada',
                'field_type' => EntityType::class,
                'field_options' => [
                    'class' => \App\Entity\ReservaUnit::class,
                    'choice_label' => 'nombre',
                    'placeholder' => '—',
                ],
                'show_filter' => true,
            ]);
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre', null, ['editable' => true, 'label' => 'Nombre interno'])
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, [
                'template' => 'base_sonata_admin/list_html.html.twig',
                'header_class' => 'col-long-text'
            ])
            ->add('links', null, [
                'label' => 'Vínculos a Unidades',
                'associated_property' => null, // muestra conteo
            ])
        ;

        if ($this->getRequest() && $this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $listMapper->add('contenidooriginal', null, [
                'label' => 'Contenido original',
                'template' => 'base_sonata_admin/list_html.html.twig',
                'header_class' => 'col-long-text'
            ]);
        }

        $listMapper->add(ListMapper::NAME_ACTIONS, null, [
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
        $formMapper
            ->add('nombre', null, [
                'label' => 'Nombre interno',
                'required' => true,
                'attr' => ['placeholder' => 'Ej: “Cama King + Vista Patio”'],
                'help' => 'Solo para uso interno en el backoffice',
            ])
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
            ])

            // Vínculos a Unidades (inline) con botón Agregar
            ->add('links', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Vínculos a Unidades',
                'required' => false,
                'btn_add' => 'Agregar vínculo',
                'type_options' => [
                    'delete' => true,
                    'label' => false,
                ],
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                'sortable' => 'prioridad',
            ])

            // Medios hijos (inline) con botón Agregar
            ->add('medios', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Medios',
                'required' => false,
                'btn_add' => 'Agregar medio',
                'type_options' => [
                    'delete' => true,
                    'label' => false,
                ],
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                // 'sortable' => 'prioridad', // descomenta si tu ReservaUnitmedio tiene prioridad
            ])
        ;

        if ($this->getRequest() && $this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $formMapper->add('contenidooriginal', null, [
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
            ->add('nombre', null, ['label' => 'Nombre interno'])
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, ['safe' => true])
            ->add('links', null, ['label' => 'Vínculos a Unidades'])
            ->add('medios', null, ['label' => 'Medios'])
        ;

        if ($this->getRequest() && $this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $showMapper->add('contenidooriginal', null, [
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
