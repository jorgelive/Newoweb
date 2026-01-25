<?php

namespace App\Oweb\Admin;


use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\AdminType;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Form\Type\ModelHiddenType;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Sonata\Form\Type\CollectionType;
use Sonata\Form\Type\DateRangePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionCotcomponenteAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {

        return 'OPERACIONES';
    }

    public function configure(): void
    {
        $this->classnameLabel = "Componente";
        $this->setFormTheme([0 => 'oweb/admin/cotizacion_cotcomponente/form_admin_fields.html.twig']);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahorainicio';
    }

    protected function configureFilterParameters(array $parameters): array
    {
        if(count($parameters) <= 4){
            $fecha = new \DateTime();

            $parameters = array_merge([
                'fechahorainicio' => [
                    'value' => [
                        'start' => $fecha->format('Y/m/d'),
                        'end' => $fecha->format('Y/m/d')
                    ]
                ]
            ], $parameters);

            $parameters = array_merge([
                'cotservicio__cotizacion__estadocotizacion' => [
                    'value' => 3
                ]
            ], $parameters);
        }

        return $parameters;
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('cotservicio.cotizacion',  null, [
                'label' => 'Cotización'
            ])
            ->add('cotservicio.cotizacion.estadocotizacion',  null, [
                'label' => 'Estado cotización'
            ])
            ->add('componente')
            ->add('estadocotcomponente', null, [
                'label' => 'Estado del componente'
            ])
            ->add('fechahorainicio', CallbackFilter::class,[
                'label' => 'Fecha de inicio',
                'callback' => function($queryBuilder, $alias, $field, $filterData) {

                    $valor = $filterData->getValue();
                    if(!($valor['start'] instanceof \DateTime) || !($valor['end'] instanceof \DateTime)) {
                        return false;
                    }
                    $fechaMasUno = clone ($valor['end']);
                    $fechaMasUno->add(new \DateInterval('P1D'));

                    if(empty($filterData->getType())){
                        $queryBuilder->andWhere("DATE($alias.$field) >= :fechahora");
                        $queryBuilder->andWhere("DATE($alias.$field) < :fechahoraMasUno");
                        $queryBuilder->setParameter('fechahora', $valor['start']->format('Y-m-d'));
                        $queryBuilder->setParameter('fechahoraMasUno', $fechaMasUno->format('Y-m-d'));
                        return true;
                    }else{
                        return false;
                    }
                },
                'field_type' => DateRangePickerType::class,
                'field_options' => [
                    'field_options_start' => [
                        'dp_use_current' => true,
                        'dp_show_today' => true,
                        'format'=> 'yyyy/MM/dd'
                    ],
                    'field_options_end' => [
                        'dp_use_current' => true,
                        'dp_show_today' => true,
                        'format'=> 'yyyy/MM/dd'
                    ]
                ],
                'operator_type' => ChoiceType::class,
                'operator_options' => [
                    'choices' => [
                        'Igual a' => 0
                    ]
                ]
            ])

        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotservicio.cotizacion',  null, [
                'label' => 'Cotización'
            ])
            ->add('cotservicio', null, [
                'label' => 'Servicio cotizado'
            ])
            ->add('componente', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'componente']]
            ])
            ->add('cantidad')

            ->add('fechahorafin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d H:i'
            ])
            ->add('estadocotcomponente', null, [
                'label' => 'Estado'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1) Campos dependientes del contexto (root class y ruta)
        //    - Mostrar/ocultar 'cotservicio', 'componente' y 'cantidad' según reglas
        // ─────────────────────────────────────────────────────────────────────────────

        // Mostrar 'cotservicio' salvo en los roots/clases y rutas excluidas
        if (
            $this->getRoot()->getClass() !== \App\Oweb\Entity\CotizacionFile::class
            && $this->getRoot()->getClass() !== \App\Oweb\Entity\CotizacionCotizacion::class
            && $this->getRoot()->getClass() !== \App\Oweb\Entity\CotizacionCotservicio::class
            && !($this->isCurrentRoute('edit') && $this->getRoot()->getClass() === \App\Oweb\Entity\CotizacionCotcomponente::class)
        ) {
            $formMapper->add('cotservicio', null, [
                'label' => 'Servicio',
            ]);
        }

        // Ocultar en edición el componente y la cantidad (mostrar como hidden el componente);
        // en los demás casos, 'componente' es autocompletable y 'cantidad' readonly opcional
        if (!($this->isCurrentRoute('edit') && $this->getRoot()->getClass() === \App\Oweb\Entity\CotizacionCotcomponente::class)) {
            $formMapper
                ->add('componente', ModelAutocompleteType::class, [
                    'property'             => 'nombre',//  (ruta cambiada; se respeta comportamiento original)
                    'template'             => 'form/type/ajax_dropdown_type_cotizacion_base.html.twig',
                    'route'                => ['name' => 'api_oweb_servicio_componente_porserviciodropdown', 'parameters' => []],
                    'placeholder'          => '',
                    'context'              => '/\[cotcomponentes\]\[\d*\]\[componente\]$/g, "[servicio]"', // se deja tal cual
                    'minimum_input_length' => 0,
                    'dropdown_auto_width'  => false,
                    'btn_add'              => false,
                ])
                ->add('cantidad', null, [
                    'required' => false,
                    'attr'     => ['class' => 'readonly'],
                ])
            ;
        } else {
            // En edición de CotizacionCotcomponente: el componente se mantiene pero como hidden
            $formMapper->add('componente', ModelHiddenType::class);
        }

        // Estado (siempre)
        $formMapper->add('estadocotcomponente', null, [
            'label' => 'Estado',
        ]);

        // ─────────────────────────────────────────────────────────────────────────────
        // 2) Bloque OPERATIVA (embed AdminType inline)
        // ─────────────────────────────────────────────────────────────────────────────
        $formMapper->add('operativa', AdminType::class, [
            'label'    => 'Operativa',
            'delete'   => false,   // evita borrar desde el embed
            'required' => false,   // permite crearla cuando aún no existe
            'btn_add'  => false,   // sin botón "agregar" aquí
            'help'     => 'Configura la operativa de recojo (horas, tolerancia y notas).',
            'help_html'=> true,
        ], [
            'edit'   => 'inline',
            'inline' => 'standard', // (se respeta tal cual; alternativa: 'table')
        ]);

        // ─────────────────────────────────────────────────────────────────────────────
        // 3) Fechas de inicio/fin (no se muestran en edición de CotizacionCotcomponente)
        // ─────────────────────────────────────────────────────────────────────────────
        if (!($this->isCurrentRoute('edit') && $this->getRoot()->getClass() === \App\Oweb\Entity\CotizacionCotcomponente::class)) {
            $formMapper
                ->add('fechahorainicio', DateTimePickerType::class, [
                    'label'         => 'Inicio',
                    'dp_show_today' => true,
                    'format'        => 'yyyy/MM/dd HH:mm',
                    'attr'          => [
                        'class'               => 'fechahora componenteinicio',
                        'horariodependiente'  => false,
                    ],
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label'         => 'Fin',
                    'dp_show_today' => true,
                    'format'        => 'yyyy/MM/dd HH:mm',
                    'attr'          => [
                        'class'               => 'fechahora componentefin',
                        'horariodependiente'  => false,
                    ],
                ])
            ;
        }

        // ─────────────────────────────────────────────────────────────────────────────
        // 4) Colección de tarifas (inline table)
        // ─────────────────────────────────────────────────────────────────────────────
        $formMapper->add('cottarifas', CollectionType::class, [
            'by_reference' => false,
            'label'        => 'Tarifas',
            'btn_add'      => 'Agregar nueva Tarifa',
        ], [
            'edit'   => 'inline',
            'inline' => 'table',
        ]);

        // ─────────────────────────────────────────────────────────────────────────────
        // 5) Modificadores y listeners (PRE_SET_DATA)
        //     - cantidadModifier: redefine 'cantidad' como dependeduracion + readonly
        //     - horarioModifier : ajusta inicio/fin según duración/horarioDependiente
        // ─────────────────────────────────────────────────────────────────────────────

        $cantidadModifier = function (FormInterface $form): void {
            $form->add('cantidad', null, [
                'label'    => 'Cantidad',
                'required' => false,
                'attr'     => ['class' => 'dependeduracion readonly'],
            ]);
        };

        $horarioModifier = function (FormInterface $form, $duracion, $horarioDependiente): void {
            $form
                ->add('fechahorainicio', DateTimePickerType::class, [
                    'label'         => 'Inicio',
                    'dp_show_today' => true,
                    'format'        => 'yyyy/MM/dd HH:mm',
                    'attr'          => [
                        'duracion'            => $duracion,
                        'horariodependiente'  => $horarioDependiente,
                        'class'               => 'fechahora componenteinicio',
                    ],
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label'         => 'Fin',
                    'dp_show_today' => true,
                    'format'        => 'yyyy/MM/dd HH:mm',
                    'attr'          => [
                        'duracion'            => $duracion,
                        'horariodependiente'  => $horarioDependiente,
                        'class'               => 'fechahora componentefin',
                    ],
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();

        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($cantidadModifier, $horarioModifier): void {
                $data = $event->getData();
                $form = $event->getForm();

                // cantidad depende de tipocomponente->dependeduracion
                if (
                    $data
                    && $data->getComponente()
                    && $data->getComponente()->getTipocomponente()
                    && $data->getComponente()->getTipocomponente()->isDependeduracion() === true
                ) {
                    $cantidadModifier($form);
                }

                // calcular duración y si el horario depende del itinerario
                $horarioDependiente = false;
                $duracion = 0;

                if ($data && $data->getComponente() && $data->getComponente()->getDuracion() !== null) {
                    $duracion = $data->getComponente()->getDuracion();
                } elseif (
                    $data
                    && $data->getCotservicio()
                    && $data->getCotservicio()->getItinerario()
                    && $data->getCotservicio()->getItinerario()->getDuracion()
                ) {
                    $duracion = $data->getCotservicio()->getItinerario()->getDuracion();
                    $horarioDependiente = true;
                }

                if (!empty($duracion)) {
                    $horarioModifier($form, $duracion, $horarioDependiente);
                }
            }
        );
    }


    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('cotservicio')
            ->add('componente')
            ->add('cantidad')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('estadocotcomponente', null, [
                'label' => 'Estado'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('email', $this->getRouterIdParameter() . '/email');
    }

}
