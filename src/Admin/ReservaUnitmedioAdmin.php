<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\Form\Type\DatePickerType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ReservaUnitmedioAdmin extends AbstractAdmin
{
    public function configure(): void
    {
        $this->classnameLabel = "Multimedia";
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'id';
    }

    protected function configureFilterParameters(array $parameters): array
    {
        /*
         * if(!isset($parameters['unit'])){
            $parameters = array_merge([
                'unit' => [
                    'value' => 1
                ]
            ], $parameters);
        }
        */

        return $parameters;
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['carga'] = ['template' => 'reserva_unitmedio_admin/carga_button.html.twig'];

        return $buttonList;
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('unittipocaracteristica', null, [
                    'label' => 'Tipo'
                ]
            )
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('prioridad')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unit', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'unit']],
                'label' => 'Alojamiento',
                'editable' => true,
                'class' => 'App\Entity\ReservaUnit',
                'required' => true,
                'choices' => [
                    1 => '#1 Centro Cusco',
                    2 => '#2 Centro Cusco',
                    3 => '#3 Centro Cusco',
                    4 => '#4 Centro Cusco',
                    5 => '#5 Centro Cusco',
                ]
            ])
            ->add('unittipocaracteristica', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'unittipocaracteristica']],
                'label' => 'Tipo',
                'editable' => true,
                'class' => 'App\Entity\ReservaUnittipocaracteristica',
                'required' => true,
                'choices' => [
                    1 => 'Descrición',
                    2 => 'Briefing',
                    3 => 'Galería'

                ]
            ])
            ->add('webThumbPath', 'string', [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/list_image.html.twig'
                ]
            )
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('prioridad', null, [
                'label' => 'Prioridad',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'reserva_unitmedio_admin/list__action_traducir.html.twig'
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

        if($this->getRoot()->getClass() != 'App\Entity\ReservaUnit'){
            $formMapper->add('unit', null, [
                'label' => 'Unidad'
            ]);
        }

        $formMapper
            ->add('unittipocaracteristica', null, [
                    'label' => 'Tipo'
            ])
            ->add('nombre', null, [
                'attr' => ['class' => 'uploadedimage']
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('prioridad')
            ->add('archivo', FileType::class, [
                'required' => false
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'min-width: 150px;'
                    ]
                ])
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr' => [
                        'style' => 'min-width: 150px;'
                    ]
                ])
                ->add('enlace', null, [
                    'label' => 'Enlace',
                    'attr' => [
                        'style' => 'min-width: 250px;'
                    ]
                ])
                ->add('prioridad')
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\ReservaUnit'
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
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('webThumbPath', null, [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/show_image.html.twig'
                ]
            )
            ->add('enlace')
            ->add('unittipocaracteristica', null, [
                    'label' => 'Tipo'
                ]
            )
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
        ;
    }

    public function prePersist($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    public function preUpdate($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    private function manageFileUpload($unitmedio): void
    {
        if($unitmedio->getArchivo()) {
            $unitmedio->refreshModificado();
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
        $collection->add('carga', 'carga');
        $collection->add('ajaxcrear', 'ajaxcrear');
    }

}
