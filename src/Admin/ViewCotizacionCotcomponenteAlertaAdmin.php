<?php

namespace App\Admin;


use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DateRangePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;


class ViewCotizacionCotcomponenteAlertaAdmin extends AbstractAdmin
{

    public function configure(): void
    {
        $this->classnameLabel = "Alertas de Componente";
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahorainicio';
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
            ->add('estadocotcomponente', null, [
                'label' => 'Estado'
            ])
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotservicio', null, [
                'label' => 'Servicio'
            ])
            ->add('componente')
            ->add('cantidad')

            ->add('fechaalerta', null, [
                'label' => 'Alerta',
                'format' => 'Y/m/d H:i'
            ])

        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->remove('create');
        $collection->remove('edit');
        $collection->remove('show');
    }

}