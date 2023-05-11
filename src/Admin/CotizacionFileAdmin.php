<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;


class CotizacionFileAdmin extends AbstractAdmin
{

    public $vars;

    public function configure(): void
    {
        $this->classnameLabel = "File";
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'modificado';
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['resumen'] = ['template' => 'cotizacion_file_admin/resumen_button.html.twig'];
        $buttonList['archivodcc'] = ['template' => 'cotizacion_file_admin/archivodcc_button.html.twig'];
        $buttonList['archivopr'] = ['template' => 'cotizacion_file_admin/archivopr_button.html.twig'];
        $buttonList['archivocon'] = ['template' => 'cotizacion_file_admin/archivocon_button.html.twig'];

        return $buttonList;
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('pais')
            ->add('idioma')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('pais', FieldDescriptionInterface::TYPE_STRING, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'pais']],
            ])
            ->add('idioma', FieldDescriptionInterface::TYPE_STRING, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'idioma']],
            ])
            ->add('filedocumentos', null, [
                'label' => 'Documentos'
            ])
            ->add('cotizaciones', null, [
                'label' => 'Cotizaciones',
                'associated_property' => 'nombre'
            ])
            ->add('modificado',  null, [
                'label' => 'Modificación',
                'format' => 'Y/m/d H:i'

            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'resumen' => [
                        'template' => 'cotizacion_file_admin\list__action_resumen.html.twig'
                    ],
                    'archivodcc' => [
                        'template' => 'cotizacion_file_admin\list__action_archivodcc.html.twig'
                    ],
                    'archivopr' => [
                        'template' => 'cotizacion_file_admin\list__action_archivopr.html.twig'
                    ],
                    'archivocon' => [
                        'template' => 'cotizacion_file_admin\list__action_archivocon.html.twig'
                    ],
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
        $temp = $this->getRequest()->get('pcode');

        $formMapper
            ->add('nombre')
            ->add('pais')
            ->add('idioma');

        if($this->getRequest()->get('pcode') != 'app.admin.cotizacioncotizacion'){
            $formMapper
                ->add('filepasajeros', CollectionType::class, [
                    'by_reference' => false,
                    'label' => 'Name List'
                ], [
                    'edit' => 'inline',
                    'inline' => 'table'
                ])
                ->add('filedocumentos', CollectionType::class, [
                    'by_reference' => false,
                    'label' => 'Documentos'
                ], [
                    'edit' => 'inline',
                    'inline' => 'table'
                ]);
        }

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
            ->add('nombre')
            ->add('pais')
            ->add('idioma')
            ->add('filepasajeros', null, [
                'label' => 'Name List'
            ])
            ->add('filedocumentos', null, [
                'label' => 'Documentos'
            ])
            ->add('cotizaciones', null, [
                'label' => 'Cotizaciones'
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen/{token}');
        $collection->add('archivodcc', $this->getRouterIdParameter() . '/archivodcc');
        $collection->add('archivopr', $this->getRouterIdParameter() . '/archivopr');
        $collection->add('archivocon', $this->getRouterIdParameter() . '/archivocon');
    }

}
