<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Form\Type\PmsEventoCalendarioEmbeddedType;
use App\Pms\Form\Type\PmsReservaHuespedType;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsReservaCrudController.
 * Gestión central de reservas, huéspedes y eventos vinculados.
 * Hereda de BaseCrudController y utiliza UUID v7 con seguridad prioritaria.
 */
class PmsReservaCrudController extends BaseCrudController
{
    public function __construct(
        private readonly PmsEventoCalendarioFactory $eventoCalendarioFactory,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsReserva::class;
    }

    /**
     * ✅ Configuración de acciones y seguridad.
     * Los permisos de Roles se aplican DESPUÉS del parent para prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        $returnTo = $this->requestStack->getCurrentRequest()?->query->get('returnTo');

        $actions->disable(Action::BATCH_DELETE);

        // Botón Global: Crear Bloqueo
        $crearBloqueo = Action::new('crearBloqueo', 'Crear Bloqueo')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-danger')
            ->linkToUrl(function () use ($returnTo) {
                return $this->adminUrlGenerator
                    ->setController(PmsEventoCalendarioCrudController::class)
                    ->setAction(Action::NEW)
                    ->set('es_bloqueo', 1)
                    ->set('returnTo', $returnTo)
                    ->generateUrl();
            });

        $actions
            ->add(Crud::PAGE_INDEX, $crearBloqueo)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);

        // Lógica de borrado blindada (Solo locales cancelados o sincronizaciones limpias)
        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsReserva $reserva) {
                foreach ($reserva->getEventosCalendario() as $evento) {
                    if ($evento->isOta()) return false;
                    if (!$evento->isSynced()) return false;

                    $estado = $evento->getEstado();
                    if (!$estado || $estado->getId() !== PmsEventoEstado::CODIGO_CANCELADA) {
                        return false;
                    }
                }
                return true;
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);

        // Aplicamos lógica base y luego sobreescribimos con Roles
        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission('crearBloqueo', Roles::RESERVAS_WRITE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Reserva')
            ->setEntityLabelInPlural('Reservas')
            ->setDefaultSort(['fechaLlegada' => 'DESC'])
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', 'panel/pms/pms_reserva/index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('referenciaCanal')
            ->add('beds24MasterId')
            ->add('nombreCliente')
            ->add('fechaLlegada');
    }

    public function configureFields(string $pageName): iterable
    {
        $entity = null;
        if ($pageName === Crud::PAGE_DETAIL) {
            $context = $this->getContext();
            $entity = $context?->getEntity()->getInstance();
        }

        // ✅ UUID para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        // --- ESTADO SYNC ---
        yield TextField::new('syncStatusAggregate', 'Estado Sincro')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced'  => '<span class="badge badge-success"><i class="fa fa-check"></i> OK</span>',
                    'error'   => '<span class="badge badge-danger"><i class="fa fa-exclamation-triangle"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Wait</span>',
                    default   => '',
                };
            })
            ->renderAsHtml()
            ->hideOnForm();

        yield FormField::addPanel('Datos del Titular')->setIcon('fa fa-user');
        yield AssociationField::new('channel', 'Canal de Venta')
            ->setFormTypeOption('disabled', true);

        yield TextField::new('nombreCliente', 'Nombre')->setColumns(6);
        yield TextField::new('apellidoCliente', 'Apellido')->setColumns(6);

        yield TextField::new('telefono', 'Teléfono')->setColumns(6);
        yield EmailField::new('emailCliente', 'Email')->setColumns(6);
        yield AssociationField::new('pais', 'País (Maestro)')->setColumns(6);
        yield AssociationField::new('idioma', 'Idioma (Maestro)')->setColumns(6);

        yield BooleanField::new('datosLocked', 'Bloquear Datos')
            ->setHelp('Evita que la sincronización automática sobrescriba cambios manuales.');

        yield FormField::addPanel('Eventos de Calendario (Estancias)')->setIcon('fa fa-calendar');
        yield CollectionField::new('eventosCalendario', 'Gestión de Eventos')
            ->setEntryIsComplex(true)
            ->setFormTypeOption('entry_type', PmsEventoCalendarioEmbeddedType::class)
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('prototype_data', $this->eventoCalendarioFactory->crearInstanciaPorDefecto())
            ->allowAdd()
            ->allowDelete()
            ->onlyOnForms();

        yield CollectionField::new('eventosCalendario', 'Detalle de Estancias')
            ->setTemplatePath('panel/pms/pms_reserva/fields/detail_eventos.html.twig')
            ->onlyOnDetail();

        // --- NAMELIST / PRE CHECK-IN ---
        yield FormField::addPanel('Huéspedes / Pasajeros')->setIcon('fa fa-users');
        yield CollectionField::new('huespedes', 'Lista Namelist')
            ->setEntryType(PmsReservaHuespedType::class)
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex(true)
            ->hideOnIndex();

        yield FormField::addPanel('Resumen de Ocupación')->setIcon('fa fa-calculator')->renderCollapsed();
        yield DateField::new('fechaLlegada', 'Fecha Check-in')
            ->setFormTypeOption('disabled', true)->setColumns(6);
        yield DateField::new('fechaSalida', 'Fecha Check-out')
            ->setFormTypeOption('disabled', true)->setColumns(6);

        yield MoneyField::new('montoTotal', 'Importe Total (USD)')
            ->setCurrency('USD')
            ->setStoredAsCents(false)
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield FormField::addPanel('Identificadores de Integración')->setIcon('fa fa-fingerprint')->renderCollapsed();
        $refCanal = $entity?->getReferenciaCanal();
        if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_NEW || !empty($refCanal)) {
            yield TextField::new('referenciaCanal', 'Ref. Canal / OTA')
                ->setFormTypeOption('disabled', true);
        }

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        yield FormField::addPanel('Auditoría Técnica')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado el')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Actualizado el')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->onlyOnDetail();
    }
}