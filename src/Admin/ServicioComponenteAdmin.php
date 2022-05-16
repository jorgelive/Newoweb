<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\AdminBundle\Route\RouteCollectionInterface;

class ServicioComponenteAdmin extends AbstractAdmin
{
    protected $datagridValues = [
        '_page' => 1,
        '_sort_order' => 'ASC',
        '_sort_by' => 'nombre',
    ];

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('servicios')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tipocomponente']]
            ])
            ->add('duracion', null, [
                'label' => 'Duración',
                'row_align' => 'right'
            ])
            ->add('tarifas')
            ->add('servicios')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'servicio_componente_admin/list__action_clonar.html.twig'
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
        $formMapper
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('servicios', null,[
                'by_reference' => false
            ])
            ->add('tarifas', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Tarifas'
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
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('servicios')
            ->add('tarifas')
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
