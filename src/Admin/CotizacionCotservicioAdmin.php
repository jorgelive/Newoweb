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
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;

use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateRangePickerType;
use Sonata\Form\Type\DateRangeType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionCotservicioAdmin extends AbstractAdmin
{


    public function configure(): void
    {
        $this->setFormTheme([0 => 'cotizacion_cotservicio_admin/form_admin_fields.html.twig']);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahorainicio';
    }

    protected function configureFilterParameters(array $parameters): array
    {
        if(!isset($parameters['fechahorainicio'])){
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

        if(!isset($parameters['cotizacion__estadocotizacion'])){
            $parameters = array_merge([
                'cotizacion__estadocotizacion' => [
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
            ->add('cotizacion.estadocotizacion',  null, [
                'label' => 'Estado cotización'
            ])
            ->add('fechahorainicio', CallbackFilter::class,[
                'label' => 'Fecha de inicio',
                'callback' => function($queryBuilder, $alias, $field, $filterData) {

                    $valor = $filterData->getValue();
                    if (!($valor['start'] instanceof \DateTime) || !($valor['end'] instanceof \DateTime)) {
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
                    } else{
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
            ->add('servicio')
            ->add('cotizacion', null, [
                'label' => 'Cotización'
            ])
            ->add('itinerario')
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
            ->add('servicio')
            ->add('cotizacion.file.nombre', null, [
                'label' => 'File'
            ])
            ->add('cotcomponentes', null, [
                'label' => 'Componentes',
                'associated_property' => 'nombre',
                'sort_field_mapping' => [
                    'fieldName' => 'fechahorainicio',
                ]
            ])
            ->add('itinerario')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => []
                ]
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotizacion'
            && !($this->isCurrentRoute('edit') && $this->getRoot()->getClass() == 'App\Entity\CotizacionCotservicio')
        ){
            $formMapper->add('cotizacion');
        }

        $formMapper
            ->add('servicio', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'route' => ['name' => 'app_servicio_servicio_alldropdown', 'parameters' => []],
                'placeholder' => '',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'btn_add' => false
            ])
            ->add('itinerario', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'template' => 'form/ajax_dropdown_type.html.twig',
                'route' => ['name' => 'app_servicio_itinerario_porserviciodropdown', 'parameters' => []],
                'placeholder' => '',
                'context' => '/\[itinerario\]$/g, "[servicio]"',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'btn_add' => false
            ])
            ->add('fechahorainicio', DateTimePickerType::class, [
                'label' => 'Inicio',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm',
                'attr' => [
                    'class' => 'fechahora serviciofechainicio'
                ]
            ])
            ->add('fechahorafin', DateTimePickerType::class, [
                'label' => 'Fin',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm',
                'attr' => [
                    'class' => 'fechahora serviciofechafin'
                ]
            ])
            ->add('cotcomponentes', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Componentes'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;

        $horarioModifier = function (FormInterface $form, $duracion, $paraleloclass) {

            $form->add('fechahorainicio', DateTimePickerType::class, [
                    'label' => 'Inicio',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'class' => 'fechahora serviciofechainicio' . $paraleloclass
                    ]
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label' => 'Fin',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'class' => 'fechahora serviciofechafin'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($horarioModifier) {
                $paraleloClass = '';
                if($event->getData()
                    && $event->getData()->getServicio()
                    && $event->getData()->getServicio()->getParalelo()
                ){
                    $paraleloClass = ' paralelo';
                }

                if($event->getData()
                    && $event->getData()->getItinerario()
                ){
                    $horarioModifier($event->getForm(), $event->getData()->getItinerario()->getDuracion(), $paraleloClass);
                }

            }
        );

        $this->vars['cotservicios']['serviciopath'] = 'app_servicio_servicio_ajaxinfo';
        $this->vars['cotcomponentes']['componentepath'] = 'app_servicio_componente_ajaxinfo';
        $this->vars['cotservicios']['itinerariopath'] = 'app_servicio_itinerario_ajaxinfo';
        $this->vars['cottarifas']['tarifapath'] = 'app_servicio_tarifa_ajaxinfo';
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('servicio')
            ->add('fechahorafin', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotizacion')
            ->add('itinerario')
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('ical', 'ical');
    }

}
