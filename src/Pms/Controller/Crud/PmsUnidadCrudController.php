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

/**
 * @extends BaseCrudController<PmsUnidad>
 */
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
                if ($entity instanceof PmsUnidad && !$entity->isImage($entity->getImageName())) {
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

        yield FormField::addPanel('Acomodación')
            ->setIcon('fa fa-bed')
            ->setHelp(
                'Capacidad dice cuántos CABEN; esto dice cómo van. Es lo que el agente usa para '
                . 'contestar «quiero algo más cómodo», que es una comparación entre casitas y no '
                . 'una cuestión de aforo. <strong>Es un resumen de lo que ya cuenta la guía</strong> '
                . '(ítem «Descripción»): si cambias uno, mira el otro.'
            );

        yield IntegerField::new('habitaciones', 'Habitaciones')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Cuántos dormitorios. Es la medida de privacidad: dos grupos de 8 caben '
                . 'igual en una de 2 habitaciones que en una de 3, y no es lo mismo.');

        yield TextField::new('camas', 'Camas')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Una línea, como se diría al vender. Ej: '
                . '<code>Hab 1: 2 dobles · Hab 2: 2 dobles</code>');

        yield IntegerField::new('banos', 'Baños')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Cuántos baños tiene la casita, en total. Todos son privados: el '
                . 'apartamento es independiente. Es de lo primero que pregunta un grupo que se '
                . 'reparte entre gente que no se conoce.');

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

        // ⚠️ La sintaxis es de llave DOBLE y las claves van en inglés. Estas ayudas decían
        // `{codigo_puerta}` y `{codigo_caja}`, que no los resuelve NADIE: la regex del
        // interpolador (`PmsGuiaInterpolador`) sólo entiende `{{ clave }}`. Quien siguiera la
        // ayuda escribía un marcador muerto y el huésped lo veía en crudo en su guía.
        yield TextField::new('codigoPuerta', 'Smart Lock (Puerta)')
            ->hideOnIndex()
            ->setColumns(6)
            ->setHelp('En la guía: <code>{{ door_code }}</code>. Sólo se muestra dentro de la '
                . 'ventana de la estancia; fuera de ella sale el mensaje de bloqueo.');

        yield TextField::new('codigoCaja', 'Caja Fuerte')
            ->hideOnIndex()
            ->setColumns(6)
            ->setHelp('En la guía: <code>{{ safe_code }}</code>. Mismo trato que el de la '
                . 'puerta: sólo dentro de la ventana.');

        // ---------------------------------------------------------------------
        // PANEL: WIFI & TRADUCCIONES (Oculto en Index)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Conectividad (WiFi)')
            ->setIcon('fa fa-wifi')
            // No es un dato del interpolador de PHP como los códigos: es un WIDGET que pinta
            // la app del huésped (RichContentEngine.ts). Por eso no lleva la ventana de acceso.
            ->setHelp('En la guía: <code>{{ wifi_data }}</code> — pinta la tarjeta con todas '
                . 'las redes de abajo. En el chat, el asistente lo remite a «consultar_wifi».');

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
        // PANEL: PERSONA ADICIONAL
        //
        // Panel propio y no dentro del tarifario base a propósito: esto se aplica
        // SIEMPRE, salga el precio de un rango o de la tarifa base. Meterlo bajo
        // «Tarifario Base (Fallback)» daría a entender que sólo cuenta cuando no
        // hay tarifa cargada, que es justo lo contrario.
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Persona adicional')
            ->setIcon('fa fa-user-plus')
            ->setHelp(
                'La tarifa de una noche cubre a un grupo de cierto tamaño; a partir de ahí '
                . 'cada persona suma. Se aplica sobre CUALQUIER tarifa, sea de un rango o la '
                . 'base. Los niños cuentan igual que los adultos.'
            );

        yield IntegerField::new('paxIncluidos', 'Personas incluidas')
            ->hideOnIndex()
            ->setHelp(
                'Hasta cuántas personas cubre la tarifa sin recargo. NO es la capacidad: la '
                . 'Casita 1 admite 8 pero su tarifa cubre 5. <strong>En 0 no se cobra ningún '
                . 'suplemento</strong>, aunque haya precio puesto abajo.'
            );

        yield NumberField::new('precioPaxAdicional', 'Precio por persona extra')
            ->setNumDecimals(2)
            ->hideOnIndex()
            ->setHelp(
                'Lo que suma cada persona por encima de las incluidas, <strong>por noche</strong> '
                . 'y en la moneda de la tarifa base. En 0.00 no hay suplemento.'
            );

        // Una sola columna en el listado, para poder revisar de un vistazo que ninguna casita
        // se quedó sin la regla: dos campos sueltos ahí serían ruido.
        // Anclada al stub `virtualPaxExtra`, NO a `paxIncluidos`: TextField valida el valor
        // crudo antes de formatearlo y un `int` lo hace reventar con «can't be converted
        // into a string». El contenido lo pone el formatValue desde la entidad.
        yield TextField::new('virtualPaxExtra', 'Pax extra')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static function ($value, $entity) {
                if (!$entity instanceof PmsUnidad || !$entity->cobraPaxAdicional()) {
                    return '—';
                }

                return sprintf(
                    'desde %d → %s',
                    $entity->getPaxIncluidos() + 1,
                    $entity->getPrecioPaxAdicional()
                );
            });

        yield FormField::addPanel('Limpieza y servicio')
            ->setIcon('fa fa-broom')
            ->setHelp(
                'La limpieza se cobra SIEMPRE y va en el total. El servicio NO lo cobra este '
                . 'sistema: lo aplica la OTA por su cuenta, y se guarda aquí para poder cuadrar '
                . 'lo que el huésped acaba pagando allí con lo que cotizamos nosotros.'
            );

        yield NumberField::new('precioLimpieza', 'Limpieza')
            ->setNumDecimals(2)
            ->hideOnIndex()
            ->setHelp(
                '<strong>Por estancia, no por noche</strong>: se limpia al salir, una vez. '
                . 'Según el interruptor de abajo, <code>15.00</code> son 15 dólares o un 15%. '
                . 'En 0.00 no se cobra.'
            );

        yield BooleanField::new('limpiezaEsPorcentaje', 'La limpieza es %')
            ->hideOnIndex()
            ->renderAsSwitch(true)
            ->setHelp(
                'Cambia cómo se lee el número de arriba: apagado, <code>15.00</code> son 15 '
                . 'dólares; encendido, es un <strong>15% sobre alojamiento + suplemento por '
                . 'persona</strong> (la misma base que el % de servicio: más gente ensucia '
                . 'más). La limpieza no entra en su propia base. '
                . 'En 0.00 no se cobra limpieza y no aparece en la cotización, esté como esté '
                . 'este interruptor.'
            );

        yield NumberField::new('porcentajeServicio', '% de servicio')
            ->setNumDecimals(2)
            ->hideOnIndex()
            ->setHelp(
                'Se calcula sobre <strong>alojamiento + persona extra</strong>; la limpieza NO '
                . 'entra en la base. Sólo se aplica en los canales de abajo: si no marcas '
                . 'ninguno, no se aplica en ninguno.'
            );

        yield AssociationField::new('serviciosCanales', 'Canales con servicio')
            ->hideOnIndex()
            ->setFormTypeOption('by_reference', false)
            ->setHelp(
                'En qué canales se añade ese porcentaje. Los directos se dejan SIN marcar: a un '
                . 'cliente que reserva contigo no le cobra comisión nadie.'
            );

        // Una columna y no tres: en el listado sólo interesa comprobar de un vistazo que
        // ninguna casita se quedó sin la regla. Mismo criterio que «Pax extra».
        yield TextField::new('virtualLimpiezaServicio', 'Limpieza / servicio')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static function ($value, $entity) {
                if (!$entity instanceof PmsUnidad) {
                    return '—';
                }

                $limpieza = match (true) {
                    $entity->limpiezaEsPorcentaje() => rtrim(rtrim($entity->getPrecioLimpieza(), '0'), '.') . '%',
                    (float) $entity->getPrecioLimpieza() > 0.0 => $entity->getPrecioLimpieza(),
                    default => '—',
                };

                $canales = $entity->idsCanalesServicio();
                $servicio = (float) $entity->getPorcentajeServicio() > 0.0 && $canales !== []
                    ? sprintf('%s%% en %s', $entity->getPorcentajeServicio(), implode(', ', $canales))
                    : '—';

                return sprintf('%s · %s', $limpieza, $servicio);
            });

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
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true); // Visible pero readonly en form

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}