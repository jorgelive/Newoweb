<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Administración para la entidad CotizacionFile.
 * Gestiona la creación, edición y visualización de Files de Cotización.
 */
class CotizacionFileAdmin extends AbstractSecureAdmin
{
    /**
     * @var array<string, mixed> Variables personalizadas para la vista.
     */
    public array $vars = [];

    /**
     * Servicio de seguridad para verificar el usuario actual.
     */
    private Security $security;

    /**
     * Inyecta el servicio de seguridad mediante setter para evitar sobrescribir el constructor.
     *
     * @param Security $security El helper de seguridad de Symfony.
     */
    #[Required]
    public function setSecurity(Security $security): void
    {
        $this->security = $security;
    }

    /**
     * Define el prefijo del módulo para la organización en el menú.
     *
     * @return string
     */
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    /**
     * Configuración inicial del admin.
     */
    public function configure(): void
    {
        $this->classnameLabel = "File";
    }

    /**
     * Configura los valores de ordenamiento por defecto en el listado.
     *
     * @param array $sortValues
     */
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'modificado';
    }

    /**
     * Configura los botones de acción disponibles en las vistas.
     * Detecta si el usuario es anónimo para mostrar solo el botón de login/retorno.
     *
     * @param array       $buttonList Lista actual de botones.
     * @param string      $action     La acción actual (list, create, show, etc.).
     * @param object|null $object     El objeto actual (si aplica).
     *
     * @return array
     */
    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        // En Symfony moderno, getUser() devuelve null si no hay usuario o es anónimo.
        $user = $this->security->getUser();

        if (!$user) {
            // Caso Anónimo: Limpiamos botones estándar y mostramos solo el acceso administrativo.
            // Esto evita errores de renderizado de botones que requieren permisos.
            $buttonList = [];
            $buttonList['login_action'] = ['template' => 'oweb/admin/cotizacion_file/adminview_button.html.twig'];
        } else {
            // Caso Logueado: Lógica estándar de botones.
            if ($action != 'resumen') {
                $buttonList['resumen'] = ['template' => 'oweb/admin/cotizacion_file/resumen_button.html.twig'];
            } elseif ($action == 'resumen') {
                $buttonList['show'] = ['template' => 'oweb/admin/cotizacion_file/show_button.html.twig'];
            }

            $buttonList['archivomc'] = ['template' => 'oweb/admin/cotizacion_file/archivomc_button.html.twig'];
            $buttonList['archivodcc'] = ['template' => 'oweb/admin/cotizacion_file/archivodcc_button.html.twig'];
            $buttonList['archivopr'] = ['template' => 'oweb/admin/cotizacion_file/archivopr_button.html.twig'];
            $buttonList['archivocon'] = ['template' => 'oweb/admin/cotizacion_file/archivocon_button.html.twig'];
            $buttonList['resumenclipboard'] = ['template' => 'oweb/admin/cotizacion_file/resumen_clipboard_button.html.twig'];
        }

        return $buttonList;
    }

    /**
     * Configura los filtros disponibles en el listado.
     *
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('pais')
            ->add('idioma')
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('catalogo', null, [
                'label' => 'Catálogo'
            ])
        ;
    }

    /**
     * Configura los campos mostrados en el listado.
     *
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
            ->add('telefono', null, [
                'label' => 'Teléfono',
                'editable' => true
            ])
            ->add('catalogo', null, [
                'label' => 'Catálogo',
                'editable' => true
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
                        'template' => 'oweb/admin/cotizacion_file/list__action_resumen.html.twig'
                    ],
                    'archivomc' => [
                        'template' => 'oweb/admin/cotizacion_file/list__action_archivomc.html.twig'
                    ],
                    'archivodcc' => [
                        'template' => 'oweb/admin/cotizacion_file/list__action_archivodcc.html.twig'
                    ],
                    'archivopr' => [
                        'template' => 'oweb/admin/cotizacion_file/list__action_archivopr.html.twig'
                    ],
                    'archivocon' => [
                        'template' => 'oweb/admin/cotizacion_file/list__action_archivocon.html.twig'
                    ],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    /**
     * Configura los campos del formulario de edición/creación.
     *
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        // Se obtiene el parámetro 'pcode' del request actual si existe
        $temp = $this->getRequest()->get('pcode');

        $formMapper
            ->add('nombre')
            ->add('pais')
            ->add('idioma')
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('catalogo', null, [
                'label' => 'Catálogo'
            ])
        ;

        // Lógica condicional para mostrar colecciones
        // Nota: Se mantiene comentada la condición original según el código provisto,
        // pero se mantiene la estructura por si se desea reactivar.
        // if($this->getRequest()->get('pcode') != 'app.admin.cotizacioncotizacion'){
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
        // }

        // Definición de variables de rutas para componentes JS/AJAX
        $this->vars['cotservicios']['serviciopath'] = 'api_oweb_servicio_servicio_ajaxinfo';
        $this->vars['cotcomponentes']['componentepath'] = 'api_oweb_servicio_componente_ajaxinfo';
        $this->vars['cotservicios']['itinerariopath'] = 'api_oweb_servicio_itinerario_ajaxinfo';
        $this->vars['cottarifas']['tarifapath'] = 'api_oweb_servicio_tarifa_ajaxinfo';
    }

    /**
     * Configura los campos de la vista de detalle (Show).
     *
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('nombre')
            ->add('pais')
            ->add('idioma')
            ->add('telefono', null, [
                'label' => 'Teléfono'
            ])
            ->add('catalogo', null, [
                'label' => 'Catálogo'
            ])
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

    /**
     * Configura las rutas personalizadas del admin.
     *
     * @param RouteCollectionInterface $collection
     */
    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen/{token}');
        $collection->add('archivomc', $this->getRouterIdParameter() . '/archivomc');
        $collection->add('archivodcc', $this->getRouterIdParameter() . '/archivodcc');
        $collection->add('archivopr', $this->getRouterIdParameter() . '/archivopr');
        $collection->add('archivocon', $this->getRouterIdParameter() . '/archivocon');
    }

    /**
     * Define el patrón base para las rutas de este admin.
     *
     * @param bool $isChildAdmin
     * @return string
     */
    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return 'app/cotizacionfile';
    }
}