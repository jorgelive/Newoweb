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
            $fechaFinal = new \DateTime('now +1 month');
            $parameters = array_merge([
                'fechahorainicio' => [
                    'value' => [
                        'start' => $fecha->format('Y/m/d'),
                        'end' => $fechaFinal->format('Y/m/d')
                    ],
                    'type' => 0
                ]
            ], $parameters);
        }

        if(!isset($parameters['estado'])){
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
        $inicio = $inicio->add(\DateInterval::createFromDateString('12 hours'));
        $fin = new \DateTime( 'tomorrow + 1day');
        $fin = $fin->add(\DateInterval::createFromDateString('9 hours'));

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
            ->add('estado')
            ->add('nombre')
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
            ->add('fechahorafin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d H:i'
            ])
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('estado')
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('nombre')
            ->add('enlace', null, [
                'attributes' => ['target' => '_blank', 'text' => 'Link'],
                'template' => 'base_sonata_admin/list_url.html.twig'
            ])
            ->add('detalles', null, [
                'label' => 'Detalles',
                'associated_property' => 'resumen',
                'sort_field_mapping' => [
                    'fieldName' => 'id',
                ]
            ])
            ->add('cantidadadultos', null, [
                'label' => 'Adl'
            ])
            ->add('cantidadninos', null, [
                'label' => 'Ni'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'reserva_reserva_admin/list__action_clonar.html.twig'
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
                'label' => 'Inicio',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm'
            ])
            ->add('fechahorafin', DateTimePickerType::class, [
                'label' => 'Fin',
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
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cantidadadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('cantidadninos', null, [
                'label' => 'Niños'
            ])
            ->add('enlace', null, [
                'attributes' => ['target' => '_blank', 'text' => 'Link'],
                'template' => 'base_sonata_admin/show_url.html.twig'
            ])
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
    }

}
