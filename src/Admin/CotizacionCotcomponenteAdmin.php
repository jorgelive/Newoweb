<?php

namespace App\Admin;


use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Show\ShowMapper;

use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateRangePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Sonata\AdminBundle\Form\Type\ModelHiddenType;

class CotizacionCotcomponenteAdmin extends AbstractAdmin
{

    public function configure(): void
    {
        $this->classnameLabel = "Componente";
        $this->setFormTheme([0 => 'cotizacion_cotcomponente_admin/form_admin_fields.html.twig']);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahorainicio';
    }

    protected function configureFilterParameters(array $parameters): array
    {
        if(!isset($parameters['fechahorainicio']) && !isset($parameters['cotservicio__cotizacion'])){
            $fecha = new \DateTime();

            $parameters = array_merge([
                'fechahorainicio' => [
                    'value' => [
                        'start' => $fecha->format('Y/m/d'),
                        'end' => $fecha->format('Y/m/d')
                    ]
                ]
            ], $parameters);
        }

        if(!isset($parameters['cotservicio__cotizacion__estadocotizacion']) &&  !isset($parameters['cotservicio__cotizacion'])){
            $parameters = array_merge([
                'cotservicio__cotizacion__estadocotizacion' => [
                    'value' => 3
                ]
            ], $parameters);
        }

        return $parameters;
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('cotservicio.cotizacion',  null, [
                'label' => 'Cotización'
            ])
            ->add('cotservicio.cotizacion.estadocotizacion',  null, [
                'label' => 'Estado cotización'
            ])
            ->add('cotservicio', null, [
                'label' => 'Servicio'
            ])
            ->add('componente')
            ->add('estadocotcomponente', null, [
                'label' => 'Estado del componente'
            ])
            ->add('cantidad')
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

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotservicio', null, [
                'label' => 'Servicio'
            ])
            ->add('componente')
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

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {


        if($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotizacion'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotservicio'
            && !($this->isCurrentRoute('edit') && $this->getRoot()->getClass() == 'App\Entity\CotizacionCotcomponente')
        ){
            $formMapper->add('cotservicio', null, [
                'label' => 'Servicio'
            ]);
        }
        //oculto en la edicion el componente y la cantidad
        if(!($this->isCurrentRoute('edit') && $this->getRoot()->getClass() == 'App\Entity\CotizacionCotcomponente')
        ){
            $formMapper
                ->add('componente', ModelAutocompleteType::class, [
                    'property' => 'nombre', //no funciona ya que se cambio la ruta
                    'template' => '/form/type/ajax_dropdown_type_cotizacion_base.html.twig',
                    'route' => ['name' => 'app_servicio_componente_porserviciodropdown', 'parameters' => []],
                    'placeholder' => '',
                    'context' => '/\[cotcomponentes\]\[\d*\]\[componente\]$/g, "[servicio]"',
                    'minimum_input_length' => 0,
                    'dropdown_auto_width' => false,
                    'btn_add' => false
                ])
                ->add('cantidad', null, [
                        'required' => false,
                        'attr' => ['class' => 'readonly']
                    ]
                )
                ->add('fechahorainicio', DateTimePickerType::class, [

                    'label' => 'Inicio',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'class' => 'fechahora componenteinicio',
                        'horariodependiente' => false
                    ]
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label' => 'Fin',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'class' => 'fechahora componentefin',
                        'horariodependiente' => false
                    ]
                ])
            ;
        }else{
            //muestro como oculto ya que las tarifas dependen de los componentes
            $formMapper
            ->add('componente', ModelHiddenType::class);
        }

        $formMapper
            ->add('estadocotcomponente', null, [
                'label' => 'Estado'
            ])
            ->add('cottarifas', CollectionType::class , [
                'by_reference' => false,
                'label' => 'Tarifas'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;

        $cantidadModifier = function (FormInterface $form) {

            $form->add(
                'cantidad',
                null,
                [
                    'label' => 'Cantidad',
                    'required' => false,
                    'attr' => ['class' => 'dependeduracion readonly']
                ]
            );
        };

        $horarioModifier = function (FormInterface $form, $duracion, $horarioDependiente) {

            $form->add('fechahorainicio', DateTimePickerType::class, [
                    'label' => 'Inicio',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'horariodependiente' => $horarioDependiente,
                        'class' => 'fechahora componenteinicio'
                    ]
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label' => 'Fin',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'horariodependiente' => $horarioDependiente,
                        'class' => 'fechahora componentefin'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($cantidadModifier, $horarioModifier) {

                if($event->getData()
                    && $event->getData()->getComponente()
                    && $event->getData()->getComponente()->getTipocomponente()
                    && $event->getData()->getComponente()->getTipocomponente()->isDependeduracion() === true
                ){
                    $cantidadModifier($event->getForm());
                }

                $horarioDependiente = false;
                $duracion = 0;
                if($event->getData()
                    && $event->getData()->getComponente()
                    && !is_null($event->getData()->getComponente()->getDuracion())
                ) {
                    $duracion = $event->getData()->getComponente()->getDuracion();
                }elseif($event->getData()
                    && $event->getData()->getCotservicio()
                    && $event->getData()->getCotservicio()->getItinerario()
                    && $event->getData()->getCotservicio()->getItinerario()->getDuracion())
                {
                    $duracion = $event->getData()->getCotservicio()->getItinerario()->getDuracion();
                    $horarioDependiente = true;
                }
                //var_dump($event->getData()->getComponente()->getTipocomponente()->isDependeduracion());
                if(!empty($duracion)){
                    $horarioModifier($event->getForm(), $duracion, $horarioDependiente);
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
