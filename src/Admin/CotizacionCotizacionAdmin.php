<?php

namespace App\Admin;

use App\Entity\CotizacionEstadocotizacion;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class CotizacionCotizacionAdmin extends AbstractAdmin
{

    public array $vars = [];

    private TokenStorageInterface $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;

    }

    public function configure(): void
    {
        $this->classnameLabel = "Cotización";
        $this->setFormTheme([0 => 'cotizacion_cotizacion_admin/form_admin_fields.html.twig']);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'fechaingreso';
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        if(empty($this->tokenStorage->getToken())){
            $buttonList['show'] = ['template' => 'cotizacion_file_admin/adminview_button.html.twig'];
        }else{

            if($action == 'resumen'){
                $buttonList['operaciones'] = ['template' => 'cotizacion_cotizacion_admin/operaciones_button.html.twig'];
            }elseif($action == 'operaciones'){
                $buttonList['resumen'] = ['template' => 'cotizacion_cotizacion_admin/resumen_button.html.twig'];
            }elseif($action != 'resumen'){
                $buttonList['resumen'] = ['template' => 'cotizacion_cotizacion_admin/resumen_button.html.twig'];
                $buttonList['operaciones'] = ['template' => 'cotizacion_cotizacion_admin/operaciones_button.html.twig'];
            }
            $buttonList['show'] = ['template' => 'cotizacion_cotizacion_admin/show_button.html.twig'];
            $buttonList['edit'] = ['template' => 'cotizacion_cotizacion_admin/edit_button.html.twig'];
            $buttonList['resumenclipboard'] = ['template' => 'cotizacion_cotizacion_admin/resumen_clipboard_button.html.twig'];
            $buttonList['clonar'] = ['template' => 'cotizacion_cotizacion_admin/clonar_button.html.twig'];
            $buttonList['fileshow'] = ['template' => 'cotizacion_cotizacion_admin/fileshow_button.html.twig'];
        }
        return $buttonList;
    }

    protected function configureFilterParameters(array $parameters): array
    {
        //si no hay filtro
        if(count($parameters) <= 4){ //cuando no hay filtro existen 4 parametros
            $parameters = array_merge([
                'estadocotizacion' => [
                    'value' => 3,
                    //'type' => 1
                ]
            ], $parameters);
        }

        return $parameters;
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('file')
            ->add('estadocotizacion', null, [
                'label' => 'Estado'
            ])
            ->add('fechaingreso', CallbackFilter::class,[
                'label' => 'Fecha de ingreso',
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
            ->add('fecha', CallbackFilter::class,[
                'label' => 'Fecha cotización',
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
            ->add('nombre')
            ->add('numeropasajeros', null, [
                'label' => 'Cantidad de pasajeros'
            ])
            ->add('hoteloculto', null, [
                'label' => 'Hotel oculto'
            ])
            ->add('precioocultoresumen', null, [
                'label' => 'Precio oculto'
            ])
            ->add('cotpolitica', null, [
                'label' => 'Política'
            ])
            ->add('cotnotas',  null, [
                'label' => 'Notas'
            ])
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('fechaingreso', null, [
                'label' => 'Entrada',
                'format' => 'Y/m/d H:i',
                'sortable' => true
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
                'editable' => true
            ])
            ->add('numeropasajeros', null, [
                'label' => 'Num Pax'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_show.html.twig'
                    ],
                    'edit' => [],
                    'delete' => [
                        'template' => 'base_sonata_admin\list__action_delete.html.twig'
                    ],
                    'resumen' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_resumen.html.twig'
                    ],
                    'operaciones' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_operaciones.html.twig'
                    ],
                    'clonar' => [
                        'template' => 'cotizacion_cotizacion_admin\list__action_clonar.html.twig'
                    ],
                    'traducir' => [
                        'template' => 'cotizacion_cotizacion_admin/list__action_traducir.html.twig'
                    ]
                ]
            ])
            ->add('comision', FieldDescriptionInterface::TYPE_FLOAT, [
                'editable' => true,
                'row_align' => 'right',
                'label' => 'Comisión'
            ])
            ->add('estadocotizacion', FieldDescriptionInterface::TYPE_CHOICE, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'estadocotizacion']],
                'label' => 'Estado',
                'editable' => true,
                'class' => 'App\Entity\CotizacionEstadocotizacion',
                'required' => true,
                'choices' => [
                    CotizacionEstadocotizacion::DB_VALOR_PENDIENTE => 'Pendiente',
                    CotizacionEstadocotizacion::DB_VALOR_ARCHIVADO => 'Archivado',
                    CotizacionEstadocotizacion::DB_VALOR_CONFIRMADO => 'Confirmado',
                    CotizacionEstadocotizacion::DB_VALOR_OPERADO => 'Operado',
                    CotizacionEstadocotizacion::DB_VALOR_CANCELADO => 'Cancelado',
                    CotizacionEstadocotizacion::DB_VALOR_PLANTILLA => 'Plantilla',
                    CotizacionEstadocotizacion::DB_VALOR_WAITING => 'Waiting'
                ]
            ])
            ->add('file.filedocumentos', null, [
                'label' => 'Documentos',
                'template' => 'base_sonata_admin/list_one_to_many.html.twig'
            ])
            ->add('hoteloculto', null, [
                'label' => 'Hotel oculto',
                'editable' => true
            ])
            ->add('precioocultoresumen', null, [
                'label' => 'Precio oculto',
                'editable' => true
            ])
            ->add('fechasalida', null, [
                'label' => 'Salida',
                'format' => 'Y/m/d H:i',
                'sortable' => true
            ])
            ->add('modificado', null, [
                'label' => 'Modificación',
                'format' => 'Y/m/d H:i',
                'sortable' => true
            ])

        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {

        $hoy = new \DateTime('today');
        $hoy = $hoy->format('Y/m/d');
        if($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'){
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
            ->add('resumen', null, [
                'label' => 'Resumen',
                'required' => false,
                'attr' => ['class' => 'ckeditor']
            ]);

        if($this->getRequest()->getLocale() != $this->getRequest()->getDefaultLocale()) {
            $formMapper
                ->add('resumenoriginal', null, [
                    'label' => 'Resumen original',
                    'attr' => ['class' => 'ckeditorread'],
                    'disabled' => true
                ]);
        }

        $formMapper
            ->add('numeropasajeros', null, [
                'label' => 'Num Pax'
            ])
            ->add('comision', null, [
                'label' => 'Comisión'
            ])
            ->add('hoteloculto', null, [
                'label' => 'Hotel oculto'
            ])
            ->add('precioocultoresumen', null, [
                'label' => 'Precio oculto'
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
            ->add('resumen', null, [
                'label' => 'Resumen'
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
        $collection->add('operaciones', $this->getRouterIdParameter() . '/operaciones/{tokenoperaciones}');
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
        $collection->add('email', $this->getRouterIdParameter() . '/email');
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
