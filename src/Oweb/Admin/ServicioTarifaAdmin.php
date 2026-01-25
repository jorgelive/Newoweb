<?php

namespace App\Oweb\Admin;

use App\Entity\MaestroMoneda;
use App\Oweb\Entity\MaestroCategoriatour;
use App\Oweb\Entity\ServicioModalidadtarifa;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ServicioTarifaAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    public function configure(): void
    {
        $this->classnameLabel = "Tarifa";
    }

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
            ->add('moneda')
            ->add('monto')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('nombremostrar',  null, [
                'label' => 'Nombre para proveedor'
            ])
            ->add('provider',  null, [
                'label' => 'Proveedor'
            ])
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('modalidadtarifa', null, [
                'label' => 'Modalidad'
            ])
            ->add('prorrateado')
            ->add('tipopax', null, [
                'label' => 'Tipo de paaajero'
            ])
            ->add('tipotarifa', null, [
                'label' => 'Típo de tarifa'
            ])
        ;
    }

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
            ->add('moneda', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'moneda']],
                'label' => 'Moneda',
                'editable' => true,
                'class' => 'App\Entity\MaestroMoneda',
                'required' => false,
                'choices' => [
                    MaestroMoneda::DB_VALOR_SOL => 'Soles',
                    MaestroMoneda::DB_VALOR_DOLAR => 'Dólares'
                ]
            ])
            ->add('monto', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ]);

        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()){
            $listMapper
            ->add('titulooriginal', null, [
                'label' => 'Título original',
            ]);
        }

        $listMapper
            ->add('nombremostrar',  null, [
                'editable' => true,
                'label' => 'Nombre para proveedor'
            ])
            ->add('provider',  null, [
                'label' => 'Proveedor'
            ])
            ->add('providernomostrable',  null, [
                'label' => 'No Mostrar a Proveedor',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'oweb/admin/servicio_tarifa/list__action_clonar.html.twig'
                    ],
                    'traducir' => [
                        'template' => 'oweb/admin/servicio_tarifa/list__action_traducir.html.twig'
                    ]
                ],
            ])
            ->add('categoriatour', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'categoriatour']],
                'label' => 'Categoria de tour',
                'editable' => true,
                'class' => 'App\Oweb\Entity\MaestroCategoriatour',
                'choices' => [
                    MaestroCategoriatour::DB_VALOR_ESTANDAR => 'Estandar',
                    MaestroCategoriatour::DB_VALOR_ECONOMICO => 'Económico',
                    MaestroCategoriatour::DB_VALOR_SUPERIOR => 'Superior',
                    MaestroCategoriatour::DB_VALOR_PREMIUM => 'Premium'
                ]
            ])
            ->add('modalidadtarifa', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'modalidadtarifa']],
                'label' => 'Modalidad',
                'editable' => true,
                'class' => 'App\Oweb\Entity\ServicioModalidadtarifa',
                'choices' => [
                    ServicioModalidadtarifa::DB_VALOR_PRIVADO => 'Privado',
                    ServicioModalidadtarifa::DB_VALOR_COMPARTIDO => 'Compartido'
                ]
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
            ->add('edadmin', null, [
                'label' => 'Edad min',
                'editable' => true
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max',
                'editable' => true
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min',
                'editable' => true
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max',
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

        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioServicio'
            && $this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioComponente'
        ){
            $formMapper->add('componente');
        }
        $formMapper
            ->add('nombre')
            ->add('moneda')
            ->add('monto')
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
        $formMapper
            ->add('nombremostrar',  null, [
                'label' => 'Nombre para proveedor'
            ])
            ->add('provider', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'template' => 'form/type/ajax_dropdown_type_cotizacion_base.html.twig',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'required' => false,
                'label' => 'Proveedor'
            ])
            ->add('providernomostrable',  null, [
                'label' => 'No Mostrar a Proveedor'
            ])
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('modalidadtarifa', null, [
                'label' => 'Modalidad'
            ])
            ->add('prorrateado')
            ->add('tipopax', null, [
                'label' => 'Tipo de pasajero'
            ])
            ->add('tipotarifa', null, [
                'label' => 'Tipo de tarifa'
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min'
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max'
            ])

            ->add('capacidadmin', null, [
                'label' => 'Cantidad min'
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max'
            ])

            ->add('validezinicio', DatePickerType::class, [
                'label' => 'Inicio validex',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd',
                'attr' => [
                    'class' => 'fecha'
                ]
            ])
            ->add('validezfin', DatePickerType::class, [
                'label' => 'Fin validez',
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
                ->add('nombremostrar',  null, [
                    'label' => 'Nombre para proveedor',
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
                    && $this->getRoot()->getClass() == 'App\Oweb\Entity\ServicioComponente'
                ){
                    $widthModifier($event->getForm());
                }
            }
        );

    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('componente')
            ->add('nombre')
            ->add('moneda')
            ->add('monto')
            ->add('titulo', null, [
                'label' => 'Título'
            ]);
        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()){
            $showMapper
                ->add('titulooriginal', null, [
                    'label' => 'Título original',
                ]);
        }

        $showMapper
            ->add('nombremostrar',  null, [
                'label' => 'Nombre para proveedor'
            ])
            ->add('provider',  null, [
                'label' => 'Proveedor'
            ])
            ->add('providernomostrable',  null, [
                'label' => 'No Mostrar a Proveedor'
            ])
            ->add('categoriatour', null, [
                'label' => 'Categoria de tour'
            ])
            ->add('modalidadtarifa', null, [
                'label' => 'Modalidad'
            ])
            ->add('prorrateado')
            ->add('tipopax', null, [
                'label' => 'Típo de pasajero'
            ])
            ->add('tipotarifa', null, [
                'label' => 'Tipo de tarifa'
            ])
            ->add('edadmin', null, [
                'label' => 'Edad min'
            ])
            ->add('edadmax', null, [
                'label' => 'Edad max'
            ])
            ->add('capacidadmin', null, [
                'label' => 'Cantidad min'
            ])
            ->add('capacidadmax', null, [
                'label' => 'Cantidad max'
            ])
            ->add('validezinicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d'
            ])
            ->add('validezfin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
