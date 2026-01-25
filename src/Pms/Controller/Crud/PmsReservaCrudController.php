<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Form\Type\PmsEventoCalendarioEmbeddedType;
use App\Pms\Form\Type\PmsReservaHuespedType;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class PmsReservaCrudController extends BaseCrudController
{
    public function __construct(
        private PmsEventoCalendarioFactory $eventoCalendarioFactory,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsReserva::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // 1. OBTENCIÓN DIRECTA: El Listener ya inyectó el valor correcto (Index o Referer)
        $returnTo = $this->requestStack->getCurrentRequest()?->query->get('returnTo');

        $actions->disable(Action::BATCH_DELETE);

        // 2. CONFIGURACIÓN DEL BOTÓN CON EL TOKEN YA EXISTENTE
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

        // 3. LÓGICA DE BORRADO BLINDADA
        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsReserva $reserva) {
                foreach ($reserva->getEventosCalendario() as $evento) {
                    if ($evento->isOta()) return false;
                    if (!$evento->isSynced()) return false;

                    $estado = $evento->getEstado();
                    if (!$estado || $estado->getCodigo() !== PmsEventoEstado::CODIGO_CANCELADA) {
                        return false;
                    }
                }
                return true;
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);

        return parent::configureActions($actions);
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

        yield IdField::new('id')->hideOnForm();

        // --- ESTADO SYNC ---
        yield TextField::new('syncStatusAggregate', 'Estado Sync')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced' => '<span class="badge badge-success"><i class="fa fa-check"></i> OK</span>',
                    'error' => '<span class="badge badge-danger"><i class="fa fa-exclamation-triangle"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Wait</span>',
                    default => '',
                };
            })
            ->renderAsHtml()
            ->hideOnForm();

        yield FormField::addPanel('Datos del Titular')->setIcon('fa fa-user');
        yield AssociationField::new('channel', 'Canal')->setFormTypeOption('disabled', true);
        yield TextField::new('nombreCliente', 'Nombre')->setColumns(6);
        yield TextField::new('apellidoCliente', 'Apellido')->setColumns(6);

        yield TextField::new('telefono', 'Teléfono')->setColumns(6);
        yield EmailField::new('emailCliente', 'Email')->setColumns(6);
        yield AssociationField::new('pais', 'País')->setColumns(6);
        yield AssociationField::new('idioma', 'Idioma')->setColumns(6);
        yield BooleanField::new('datosLocked', 'Datos bloqueados')->setHelp('Evita sobrescritura de datos personales.');

        yield FormField::addPanel('Eventos de Calendario')->setIcon('fa fa-calendar');
        yield CollectionField::new('eventosCalendario', 'Eventos')
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
        yield FormField::addPanel('Namelist / Pre Check-in')->setIcon('fa fa-users-viewfinder');
        yield CollectionField::new('huespedes', 'Lista de Pasajeros')
            ->setEntryType(PmsReservaHuespedType::class)
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex(true)
            ->hideOnIndex();

        yield FormField::addPanel('Fechas y ocupación')->setIcon('fa fa-clock')->renderCollapsed();
        yield DateField::new('fechaLlegada', 'Llegada')->setFormTypeOption('disabled', true)->setColumns(6);
        yield DateField::new('fechaSalida', 'Salida')->setFormTypeOption('disabled', true)->setColumns(6);
        yield MoneyField::new('montoTotal', 'Monto total (USD)')
            ->setCurrency('USD')->setStoredAsCents(false)->setFormTypeOption('disabled', true)->setColumns(6);

        yield FormField::addPanel('Identificadores Externos')->setIcon('fa fa-fingerprint')->renderCollapsed();
        $refCanal = $entity?->getReferenciaCanal();
        if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_NEW || !empty($refCanal)) {
            yield TextField::new('referenciaCanal', 'Referencia canal')->setFormTypeOption('disabled', true);
        }

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();
        yield DateTimeField::new('created', 'Creado')->setFormat('yyyy/MM/dd HH:mm')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield DateTimeField::new('updated', 'Actualizado')->setFormat('yyyy/MM/dd HH:mm')->setFormTypeOption('disabled', true)->hideOnIndex();
    }
}