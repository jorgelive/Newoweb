<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;

class ServicioComponenteAdmin extends AbstractAdmin
{

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'nombre';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('anticipacionalerta', null, [
                'label' => 'Anticipación de Alerta'
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
            ->add('componenteitems', null, [
                'label' => 'Items'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tipocomponente']]
            ])
            ->add('duracion', FieldDescriptionInterface::TYPE_STRING, [
                'label' => 'Duración',
                'row_align' => 'right',
                'editable' => true
            ])
            ->add('anticipacionalerta', null, [
                'label' => 'Anticipación de Alerta',
                'editable' => true
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
                        'template' => 'admin/servicio_componente/list__action_clonar.html.twig'
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
            ->add('componenteitems', CollectionType::class , [
                'by_reference' => false,
                'label' => 'Items'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('anticipacionalerta', null, [
                'label' => 'Anticipacion de Alerta'
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
            ->add('componenteitems', null, [
                'label' => 'Items'
            ])
            ->add('tipocomponente', null, [
                'label' => 'Tipo'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('anticipacionalerta', null, [
                'label' => 'Anticipacion de Alerta'
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
