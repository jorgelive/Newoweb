<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\Form\Type\CollectionType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;

class ServicioTarifaAdmin extends AbstractAdmin
{

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'componente';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('componente')
            ->add('nombre')
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('moneda')
            ->add('monto')
            ->add('validezinicio', null, [
                'label' => 'Inicio'
            ])
            ->add('validezfin', null, [
                'label' => 'Fin'
            ])
            ->add('prorrateado')
            ->add('tipopax', null, [
                'label' => 'Tipo de paaajero'
            ])
            ->add('tipotarifa', null, [
                'label' => 'Típo de tarifa'
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min'
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max'
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min'
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max'
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
            ->add('componente', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'componente']]
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('categoriatour', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'categoriatour']],
                'label' => 'Categoria de tour',
                'editable' => true,
                'class' => 'App\Entity\MaestroCategoriatour',
                'choices' => [
                    1 => 'Estandar',
                    2 => 'Económico',
                    3 => 'Superior',
                    4 => 'Premium'
                ]
            ])
            ->add('titulo', null, [
                'label' => 'Título',
            ])
            ->add('moneda', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'moneda']]
            ])
            ->add('monto', null, [
                'editable' => true
            ])
            ->add('validezinicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d'
            ])
            ->add('validezfin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d'
            ])
            ->add('prorrateado', null, [
                'editable' => true
            ])
            ->add('tipopax', null, [
                'label' => 'Tipo de pasajero',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tipopax']]
            ])
            ->add('tipotarifa', null, [
                'label' => 'Tipo de tarifa',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tipotarifa']]
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min',
                'editable' => true
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max',
                'editable' => true
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min',
                'editable' => true
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'servicio_tarifa_admin/list__action_clonar.html.twig'
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
        if ($this->getRoot()->getClass() != 'App\Entity\ServicioServicio'
            && $this->getRoot()->getClass() != 'App\Entity\ServicioComponente'
        ){
            $formMapper->add('componente');
        }

        $formMapper
            ->add('nombre')
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('moneda')
            ->add('monto')
            ->add('prorrateado')
            ->add('tipotarifa', null, [
                'label' => 'Tipo de tarifa'
            ])
            ->add('tipopax', null, [
                'label' => 'Tipo de pasajero'
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min'
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max'
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min'
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max'
            ])
            ->add('validezinicio', DatePickerType::class, [
                'label' => 'Inicio',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd',
                'attr' => [
                    'class' => 'fecha'
                ]
            ])
            ->add('validezfin', DatePickerType::class, [
                'label' => 'Fin',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd',
                'attr' => [
                    'class' => 'fecha'
                ]
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('monto', null, [
                    'label' => 'Monto',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
                    ]
                ])
                ->add('capacidadmin', null, [
                    'label' => 'Cantidad min',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
                    ]
                ])
                ->add('capacidadmax', null, [
                    'label' => 'Cantidad max',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
                    ]
                ])
                ->add('edadmin', null, [
                    'label' => 'Edad min',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
                    ]
                ])
                ->add('edadmax', null, [
                    'label' => 'Edad max',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
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
            ->add('componente')
            ->add('nombre')
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('moneda')
            ->add('monto')
            ->add('validezinicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d'
            ])
            ->add('validezfin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d'
            ])
            ->add('prorrateado')
            ->add('tipopax', null, [
                'label' => 'Típo de pasajero'
            ])
            ->add('tipotarifa', null, [
                'label' => 'Tipo de tarifa'
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min'
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max'
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min'
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
