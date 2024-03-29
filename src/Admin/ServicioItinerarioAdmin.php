<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;


class ServicioItinerarioAdmin extends AbstractAdmin
{


    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'servicio';
    }

    public function configure(): void
    {
        $this->classnameLabel = "Itinerario";
        $this->setFormTheme([0 => 'servicio_itinerario_admin/form_admin_fields.html.twig']);
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('servicio')
            ->add('nombre')
            ->add('titulo')
            ->add('hora', null, [
                'with_seconds' => false
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
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
            ->add('servicio', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'servicio']]
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'editable' => true
            ])
            ->add('hora', 'datetime', [
                'format' => 'H:i',
                'row_align' => 'right'
            ])
            ->add('duracion', FieldDescriptionInterface::TYPE_STRING, [
                'label' => 'Duración',
                'row_align' => 'right',
                'editable' => true
            ])
            ->add('itinerariodias', null, [
                'label' => 'Dias'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'servicio_itinerario_admin/list__action_clonar.html.twig'
                    ]
                ]
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if($this->getRoot()->getClass() != 'App\Entity\ServicioServicio'){
            $formMapper->add('servicio');
        }
        $formMapper
            ->add('nombre')
            ->add('titulo')
            ->add('hora', null, [
                'with_seconds' => false
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('itinerariodias', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Dias'
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
            ->add('titulo')
            ->add('servicio')
            ->add('hora', 'datetime', [
                'format' => 'H:i'
            ])
            ->add('duracion', null, [
                'label' => 'Duración'
            ])
            ->add('itinerariodias', null, [
                'label' => 'Dias'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
