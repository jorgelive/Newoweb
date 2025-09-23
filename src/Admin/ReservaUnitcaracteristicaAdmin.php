<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\Form\Type\CollectionType; // 👈 importar CollectionType

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
            // Ya NO: ->add('unit')
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', TranslationFieldFilter::class, [])
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            // Ya NO: ->add('unit')
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, ['template' => 'base_sonata_admin/list_html.html.twig'])
            // 👇 opcional: mostrar cuántos medios tiene
            ->add('medios', null, [
                'label' => 'Medios',
                'associated_property' => null, // evita listar todos; Sonata mostrará un conteo
            ])
        ;

        if ($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $listMapper->add('contenidooriginal', null, [
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
                    'template' => 'reserva_unitcaracteristica_admin/list__action_traducir.html.twig'
                ]
            ],
        ]);
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {
        // Ya NO: seleccionar unit aquí; la unidad se gestiona con los vínculos en ReservaUnit

        $formMapper
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
            ])
            // 👇 NUEVO: colección de medios hijos
            ->add('medios', CollectionType::class, [
                'by_reference' => false,   // usa addMedio/removeMedio del entity
                'label' => 'Medios',
                'required' => false,
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                // si tu entity ReservaUnitmedio tiene 'prioridad', puedes habilitar:
                // 'sortable' => 'prioridad',
            ])
        ;

        if ($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
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
            // Ya NO: ->add('unit')
            ->add('unittipocaracteristica', null, ['label' => 'Tipo'])
            ->add('contenido', null, ['safe' => true])
            // 👇 opcional: listar medios (Sonata mostrará enlaces)
            ->add('medios', null, ['label' => 'Medios'])
        ;

        if ($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
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
