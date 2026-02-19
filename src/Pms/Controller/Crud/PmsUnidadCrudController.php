<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Panel\Form\Type\WifiNetworkType;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Entity\PmsUnidad;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;
use Vich\UploaderBundle\Form\Type\VichImageType;

final class PmsUnidadCrudController extends BaseCrudController
{
    // ... (Constructor y métodos getEntityFqcn, configureActions, configureCrud, configureFilters se mantienen igual) ...
    // Solo pego aquí el constructor para contexto, asumo que tienes el resto arriba.
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private ParameterBagInterface $params
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidad::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
        $actions = parent::configureActions($actions);
        return $actions->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Unidad')
            ->setEntityLabelInPlural('Unidades')
            ->setDefaultSort(['nombre' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo')
            ->add('tarifaBaseActiva')
            ->add('beds24Maps');
    }

    public function configureFields(string $pageName): iterable
    {
        // ---------------------------------------------------------------------
        // PANEL: GENERAL
        // ---------------------------------------------------------------------
        yield FormField::addPanel('General')->setIcon('fa fa-home');

        // ID: Versión corta para Index
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        // ID: Versión completa para Detail
        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield AssociationField::new('establecimiento', 'Establecimiento')
            ->setRequired(true);

        yield TextField::new('nombre', 'Nombre');

        // 1. Resolver rutas para el ImageField nativo
        $pathRelativo = $this->params->get('pms.path.unidad_images');
        $basePath = '/' . ltrim($pathRelativo, '/');
        $uploadDir = $this->params->get('app.public_dir') . '/' . ltrim($pathRelativo, '/');

        // --- COLUMNA 1: VISTA PREVIA (Index) ---
        yield LiipImageField::new('imageUrl', 'Vista Previa')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(function ($value, $entity) {
                if ($entity instanceof PmsUnidad && method_exists($entity, 'isImage') && !$entity->isImage($entity->getImageName())) {
                    return $entity->getIconPathFor($entity->getImageName());
                }
                return $value;
            });

        // --- COLUMNA 2: SUBIDA DE ARCHIVO ---
        yield TextField::new('imageFile', 'Archivo / Imagen')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setHelp('Soporta imágenes (JPG, PNG, WEBP). Máx 5MB.')
            ->setColumns(12);

        yield TextField::new('codigoInterno', 'Código interno')
            ->setRequired(false);

        yield IntegerField::new('capacidad', 'Capacidad')
            ->setRequired(false);

        // ---------------------------------------------------------------------
        // PANEL: ESTADO
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Estado Operativo')->setIcon('fa fa-toggle-on');

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        // ---------------------------------------------------------------------
        // PANEL: SEGURIDAD (Oculto en Index)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Códigos de Acceso y Seguridad')->setIcon('fa fa-key');

        yield TextField::new('codigoPuerta', 'Smart Lock (Puerta)')
            ->hideOnIndex()
            ->setColumns(6)
            ->setHelp('Variable guía: <b>{codigo_puerta}</b>');

        yield TextField::new('codigoCaja', 'Caja Fuerte')
            ->hideOnIndex()
            ->setColumns(6)
            ->setHelp('Variable guía: <b>{codigo_caja}</b>');

        // ---------------------------------------------------------------------
        // PANEL: WIFI & TRADUCCIONES (Oculto en Index)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Conectividad (WiFi)')
            ->setIcon('fa fa-wifi')
            ->setHelp('Variable global: <b>{wifi-data}</b>');

        // Switches de Traducción (Solo en Formularios)
        yield BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6)
            ->setHelp('⚠️ Reemplazará textos existentes.');

        // Colección WiFi
        yield CollectionField::new('wifiNetworks', 'Redes WiFi')
            ->hideOnIndex()
            ->setEntryType(WifiNetworkType::class)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex(true)
            ->renderExpanded()
            ->setColumns(12);

        // ---------------------------------------------------------------------
        // PANEL: TARIFA BASE
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Tarifario Base (Fallback)')->setIcon('fa fa-money-bill');

        yield BooleanField::new('tarifaBaseActiva', 'Tarifa base activa')
            ->hideOnIndex();

        yield NumberField::new('tarifaBasePrecio', 'Precio base')
            ->setNumDecimals(2)
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        yield AssociationField::new('tarifaBaseMoneda', 'Moneda base')
            ->setRequired(true)
            ->setFormTypeOptions([ // Opcional: optimización de autocomplete
                'placeholder' => '',
                'attr' => ['data-ea-widget' => 'ea-autocomplete']
            ]);

        yield IntegerField::new('tarifaBaseMinStay', 'Min. stay base')
            ->hideOnIndex()
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        // ---------------------------------------------------------------------
        // PANEL: INTEGRACIONES
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Integración Beds24')->setIcon('fa fa-link');

        yield AssociationField::new('beds24Maps', 'Beds24 Maps');

        // ---------------------------------------------------------------------
        // PANEL: TRAZABILIDAD (Solo Detalle)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Trazabilidad Técnica')->setIcon('fa fa-cogs')
            ->onlyOnDetail();

        yield AssociationField::new('tarifaQueues', 'Tarifa Queues')
            ->onlyOnDetail();

        yield AssociationField::new('bookingsPullQueues', 'Pull Queue Jobs')
            ->onlyOnDetail();

        // ---------------------------------------------------------------------
        // PANEL: AUDITORÍA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true); // Visible pero readonly en form

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}