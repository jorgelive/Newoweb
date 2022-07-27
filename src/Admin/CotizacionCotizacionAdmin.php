<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Form\Type\ModelListType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateRangePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class CotizacionCotizacionAdmin extends AbstractAdmin
{

    public $vars;

    public function configure(): void
    {
        $this->setFormTheme([0 => 'cotizacion_cotizacion_admin/form_admin_fields.html.twig']);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'modificado';
    }


    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        if($action == 'resumen'){
            $buttonList['show'] = ['template' => 'cotizacion_cotizacion_admin/adminview_button.html.twig'];
        }else{
            $buttonList['resumen'] = ['template' => 'cotizacion_cotizacion_admin/resumen_button.html.twig'];
            $buttonList['fileedit'] = ['template' => 'cotizacion_cotizacion_admin/fileedit_button.html.twig'];
        }
        return $buttonList;
    }


    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('file')
            ->add('fecha', CallbackFilter::class,[
                'label' => 'Fecha cotización',
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
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('numeropasajeros', null, [
                'label' => 'Cantidad de pasajeros'
            ])
            ->add('estadocotizacion', null, [
                'label' => 'Estado'
            ])
            ->add('cotpolitica', null, [
                'label' => 'Política'
            ])
            ->add('cotnotas',  null, [
                'label' => 'Notas'
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
            ->add('primerCotservicioFecha', 'datetime', [
                'label' => 'Fecha Inicio',
                'format' => 'Y/m/d'
            ])
            ->add('file', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'file']]
            ])
            ->add('fecha', null, [
                'label' => 'Fecha cotización',
                'format' => 'Y/m/d'
            ])
            ->add('nombre', null, [
                'editable' => true,
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('numeropasajeros', null, [
                'label' => 'Num Pax'
            ])
            ->add('comision', 'decimal', [
                'editable' => true,
                'row_align' => 'right',
                'label' => 'Comisión'
            ])
            ->add('estadocotizacion', 'choice', [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'estadocotizacion']],
                'label' => 'Estado',
                'editable' => true,
                'class' => 'App\Entity\CotizacionEstadocotizacion',
                'choices' => [
                    1 => 'Pendiente',
                    2 => 'Archivado',
                    3 => 'Aceptado',
                    4 => 'Operado',
                    5 => 'Cancelado',
                    6 => 'Plantilla'
                ]
            ])
            ->add('file.filedocumentos', null, [
                'label' => 'Documentos'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'resumen' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_resumen.html.twig'
                    ],
                    'clonar' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_clonar.html.twig'
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

        $hoy = new \DateTime('today');
        $hoy = $hoy->format('Y/m/d');
        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'){
            $formMapper->add('file', ModelListType::class,[
                'btn_delete' => false
            ]);
        }
        $formMapper
            ->add('nombre')
            ->add('fecha', DatePickerType::class, [
                'label' => 'Fecha cotización',
                'dp_default_date' => $hoy,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd'
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('numeropasajeros', null, [
                'label' => 'Num Pax'
            ])
            ->add('comision', null, [
                'label' => 'Comisión'
            ])
            ->add('adelanto', null, [
                'label' => 'Adelanto'
            ])
            ->add('estadocotizacion', null, [
                'label' => 'Estado'
            ])
            ->add('cotpolitica', null, [
                'label' => 'Política'
            ])
            ->add('cotnotas',  null, [
                'label' => 'Notas'
            ])
            ->add('cotservicios', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Servicios'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])

        ;

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
            ->add('file')
            ->add('fecha', null, [
                'label' => 'Fecha cotización',
                'format' => 'Y/m/d'
            ])
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('numeropasajeros', null, [
                'label' => 'Cantidad de pasajeros'
            ])
            ->add('comision', null, [
                'label' => 'Comisión'
            ])
            ->add('estadocotizacion', null, [
                'label' => 'Estado'
            ])
            ->add('cotpolitica', null, [
                'label' => 'Política'
            ])
            ->add('cotnotas',  null, [
                'label' => 'Notas'
            ])

        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {

        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen/{token}');
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
