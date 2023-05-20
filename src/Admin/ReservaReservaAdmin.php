<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Sonata\Form\Type\CollectionType;
use Sonata\Form\Type\DateRangePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;

class ReservaReservaAdmin extends AbstractAdmin
{
    public function configure(): void
    {
        $this->classnameLabel = "Reserva";
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahorainicio';
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['clonar'] = ['template' => 'reserva_reserva_admin/clonar_button.html.twig'];
        $buttonList['extender'] = ['template' => 'reserva_reserva_admin/extender_button.html.twig'];
        $buttonList['resumenclipboard'] = ['template' => 'reserva_reserva_admin/resumenclipboard_button.html.twig'];
        return $buttonList;
    }

    protected function configureFilterParameters(array $parameters): array
    {

        if(count($parameters) <= 4){
            $fecha = new \DateTime();
            $fechaFinal = new \DateTime('now +1 month');
            $parameters = array_merge([
                'fechahorafin' => [
                    'value' => [
                        'start' => $fecha->format('Y/m/d'),
                        'end' => $fechaFinal->format('Y/m/d')
                    ],
                    'type' => 0
                ]
            ], $parameters);

            $parameters = array_merge([
                'estado' => [
                    'value' => 3,
                    'type' => 2
                ]
            ], $parameters);
        }

        return $parameters;
    }

    public function alterNewInstance($object): void
    {
        $entityManager = $this->getModelManager()->getEntityManager('App\Entity\ReservaReserva');

        $inicio = new \DateTime('today');
        $inicio = $inicio->add(\DateInterval::createFromDateString('14 hours'));
        $fin = new \DateTime( 'tomorrow + 1day');
        $fin = $fin->add(\DateInterval::createFromDateString('10 hours'));

        $estadoReference = $entityManager->getReference('App\Entity\ReservaEstado', 2);
        $object->setEstado($estadoReference);
        $object->setFechahorainicio($inicio);
        $object->setFechahorafin($fin);
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('manual')
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', CallbackFilter::class,[
                'label' => 'Check-in',
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
            ->add('fechahorafin', CallbackFilter::class,[
                'label' => 'Check-out',
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
                'label' => 'Check-in',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Check-out',
                'format' => 'Y/m/d H:i'
            ])
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
            ->add('estado', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'estado']],
                'label' => 'Estado',
                'editable' => true,
                'class' => 'App\Entity\ReservaEstado',
                'required' => true,
                'choices' => [
                    1 => 'Pendiente',
                    2 => 'Confirmado',
                    3 => 'Cancelado'
                ]
            ])
            ->add('chanel', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'chanel']],
                'label' => 'Canal',
                'editable' => true,
                'class' => 'App\Entity\ReservaChanel',
                'required' => true,
                'choices' => [
                    1 => 'Directo',
                    2 => 'Airbnb',
                    3 => 'Booking'
                ]
            ])
            ->add('manual', null, [
                'editable' => true
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('enlace', null, [
                'attributes' => ['target' => '_blank', 'text' => 'Link'],
                'template' => 'base_sonata_admin/list_url.html.twig',
                'editable' => true
            ])
            ->add('detalles', null, [
                'label' => 'Detalles',
                'associated_property' => 'resumen',
                'sort_field_mapping' => [
                    'fieldName' => 'id',
                ]
            ])
            ->add('cantidadadultos', null, [
                'label' => 'Adl',
                'editable' => true
            ])
            ->add('cantidadninos', null, [
                'label' => 'Ni',
                'editable' => true
            ])
            ->add('creado', null, [
                'label' => 'Creación',
                'format' => 'Y/m/d H:i'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'resumen' => [
                        'template' => 'reserva_reserva_admin\list__action_resumen.html.twig'
                    ],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'reserva_reserva_admin/list__action_clonar.html.twig'
                    ],
                    'extender' => [
                        'template' => 'reserva_reserva_admin/list__action_extender.html.twig'
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
        $formMapper
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', DateTimePickerType::class, [
                'label' => 'Check-in',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm'
            ])
            ->add('fechahorafin', DateTimePickerType::class, [
                'label' => 'Check-out',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm'
            ])
            ->add('cantidadadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('cantidadninos', null, [
                'label' => 'Niños'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('manual')
            ->add('enlace')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('detalles', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Detalles'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('importes', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Precio'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('pagos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Cobranzas'
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
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', null, [
                'label' => 'Check-in',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Check-out',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cantidadadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('cantidadninos', null, [
                'label' => 'Niños'
            ])
            ->add('creado', null, [
                'label' => 'Creación',
                'format' => 'Y/m/d H:i'
            ])
            ->add('enlace', null, [
                'attributes' => ['target' => '_blank', 'text' => 'Link'],
                'template' => 'base_sonata_admin/show_url.html.twig'
            ])
            ->add('manual')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('detalles', null, [
                'label' => 'Detalles',
                'associated_property' => 'resumen',
                'sort_field_mapping' => [
                    'fieldName' => 'id'
                ]
            ])
            ->add('importes', null, [
                'label' => 'Precios',
                'associated_property' => 'resumen',
                'sort_field_mapping' => [
                    'fieldName' => 'fecha'
                ]
            ])
            ->add('pagos', null, [
                'label' => 'Cobranzas',
                'associated_property' => 'resumen',
                'sort_field_mapping' => [
                    'fieldName' => 'fecha'
                ]
            ])

        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('ical', 'ical');
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
        $collection->add('extender', $this->getRouterIdParameter() . '/extender');
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen/{token}');
    }

}
