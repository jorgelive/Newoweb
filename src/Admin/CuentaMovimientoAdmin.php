<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateRangePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CuentaMovimientoAdmin extends AbstractAdmin
{

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechahora';
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('periodo')
            ->add('fechahora', CallbackFilter::class,[
                'label' => 'Fecha',
                'callback' => function($queryBuilder, $alias, $field, $value) {

                    if(!$value['value']['start']
                        || !($value['value']['start'] instanceof \DateTime)
                        || !$value['value']['end']
                        || !($value['value']['end'] instanceof \DateTime)
                    ) {
                        return false;
                    }
                    $fechaMasUno = clone ($value['value']['end']);
                    $fechaMasUno->add(new \DateInterval('P1D'));

                    if(empty($value['type'])){
                        $queryBuilder->andWhere("DATE($alias.$field) >= :fechahora");
                        $queryBuilder->andWhere("DATE($alias.$field) < :fechahoraMasUno");
                        $queryBuilder->setParameter('fechahora', $value['value']['start']);
                        $queryBuilder->setParameter('fechahoraMasUno', $fechaMasUno);
                        return true;
                    }
                    return true;

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
                'operator_options' => array(
                    'choices' => array(
                        'Entre' => 0,
                    )
                )
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('periodotransferencia', null, [
                'label' => 'Trans O / D'
            ])
            ->add('clase', null, [
                'label' => 'Clase'
            ])
            ->add('centro', null, [
                'label' => 'Centro de costo'
            ])
            ->add('debe', null, [
                'label' => 'Ingreso'
            ])
            ->add('haber', null, [
                'label' => 'Egreso'
            ])
            ->add('cobradorpagador', null, [
                'label' => 'Cobrador / Pagador'
            ])
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('periodo')
            ->add('fechahora',  null, [
                'label' => 'Fecha',
                'format' => 'Y/m/d H:i'
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('periodotransferencia', null, [
                'label' => 'Trans O / D'
            ])
            ->add('clase', null, [
                'label' => 'Clase'
            ])
            ->add('centro', null, [
                'label' => 'Centro de costo'
            ])
            ->add('debe', null, [
                'label' => 'Ingreso',
                'row_align' => 'right'
            ])
            ->add('haber', null, [
                'label' => 'Egreso',
                'row_align' => 'right'
            ])
            ->add('debesoles', null, [
                'template' => 'admin/cuenta_movimiento/list_string_debesoles.html.twig',
                'label' => 'Ingreso Soles',
                'row_align' => 'right'
            ])
            ->add('habersoles', null, [
                'template' => 'admin/cuenta_movimiento/list_string_habersoles.html.twig',
                'label' => 'Egreso Soles',
                'row_align' => 'right'
            ])
            ->add('cobradorpagador', null, [
                'label' => 'Cobrador / Pagador'
            ])
            ->add('modificado',  null, [
                'label' => 'Modificación',
                'format' => 'Y/m/d H:i'

            ])
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

    protected function configureFormFields(FormMapper $formMapper): void
    {
        if($this->getRoot()->getClass() != 'App\Entity\CuentaCuenta'
        && $this->getRoot()->getClass() != 'App\Entity\CuentaPeriodo'){
            $formMapper->add('periodo');
        }


        $formMapper
            ->add('fechahora', DateTimePickerType::class, [
                'label' => 'Fecha',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm',
                'attr' => [
                    'class' => 'fechahora'
                ]
            ])
            ->add('clase', null, [
                'label' => 'Clase'
            ])
            ->add('centro', null, [
                'label' => 'Centro de costo'
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('periodotransferencia', ModelAutocompleteType::class, [
                'label' => 'Trans O / D',
                'property' => 'cuenta',
                'template' => 'form/type/ajax_dropdown_type_cotizacion_base.html.twig',
                'required' => false,
                'callback' => function ($admin, $property, $value) {
                    $datagrid = $admin->getDatagrid();
                    $queryBuilder = $datagrid->getQuery();
                    $queryBuilder
                        ->leftJoin($queryBuilder->getRootAlias() .'.cuenta' ,'c')
                        ->andWhere('c.nombre like :periodoValue')
                        ->andWhere($queryBuilder->getRootAlias() .'.fechafin IS NULL')
                        ->setParameter('periodoValue', '%' . $admin->getRequest()->get('q') . '%')
                    ;
                    $datagrid->setValue($property, null, $value);
                },
            ])
            ->add('debe', null, [
                'label' => 'Ingreso',
                'attr' => ['class' => 'ingresoinput']
            ])
            ->add('haber', null, [
                'label' => 'Egreso',
                'attr' => ['class' => 'egresoinput']
            ])
            ->add('cobradorpagador', null, [
                'label' => 'Cobrador / Pagador'
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('descripcion', null, [
                    'label' => 'Descripcion',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('debe', null, [
                    'label' => 'Ingreso',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;',
                        'class' => 'ingresoinput'
                    ]
                ])
                ->add('haber', null, [
                    'label' => 'Egreso',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;',
                        'class' => 'egresoinput'
                    ]
                ])
                ->add('cobradorpagador', null, [
                    'label' => 'Cobrador / Pagador',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\CuentaPeriodo'
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
            ->add('periodo')
            ->add('fechahora',  null, [
                'label' => 'Fecha',
                'format' => 'Y/m/d H:i'
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('periodotransferencia', null, [
                'label' => 'Trans O / D'
            ])
            ->add('clase', null, [
                'label' => 'Clase'
            ])
            ->add('centro', null, [
                'label' => 'Centro de costo'
            ])
            ->add('debe', null, [
                'label' => 'Ingreso'
            ])
            ->add('haber', null, [
                'label' => 'Egreso'
            ])
            ->add('debesoles', null, [
                'label' => 'Ingreso Soles',
                'attr' => ['class' => 'ingreso']
            ])
            ->add('habersoles', null, [
                'label' => 'Egreso Soles',
                'attr' => ['class' => 'egreso']
            ])
            ->add('cobradorpagador', null, [
                'label' => 'Cobrador / Pagador'
            ])
        ;
    }

/*    public function getDataSourceIterator()
    {
        $datasourceit = parent::getDataSourceIterator();
        $datasourceit->setDateTimeFormat('Y/m/d H:i');
        return $datasourceit;
    }

    public function getExportFields()
    {
        $ret['Fecha y Hora'] = 'fechahora';
        $ret['Descripción'] = 'descripcion';
        $ret['Ingreso en soles'] = 'debesoles';
        $ret['Egreso en soles'] = 'habersoles';
        $ret['Clase'] = 'clase';
        $ret['Centro de costo'] = 'centro';
        $ret['Periodo'] = 'periodo';
        $ret['Cobrador / Pagador'] = 'cobradorpagador';

        return $ret;
    }

*/
}
