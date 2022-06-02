<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;

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
            $buttonList['show'] = ['template' => 'cotizacion_cotizacion_admin/resumen_button.html.twig'];
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
                'format' => 'Y/m/d',
            ])
            ->add('file', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'file']]
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

        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'){
            $formMapper->add('file');
        }
        $formMapper
            ->add('nombre')
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
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen');
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
