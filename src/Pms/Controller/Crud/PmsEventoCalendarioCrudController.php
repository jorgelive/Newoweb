<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * PmsEventoCalendarioCrudController.
 * Gestión de eventos de alojamiento, bloqueos y sincronización con Beds24.
 * Hereda de BaseCrudController y utiliza UUID v7.
 */
class PmsEventoCalendarioCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private PmsEventoCalendarioFactory $eventoFactory
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoCalendario::class;
    }

    /**
     * ✅ Crea la entidad usando la Factory y gestiona estados iniciales.
     */
    public function createEntity(string $entityFqcn): PmsEventoCalendario
    {
        $entity = $this->eventoFactory->crearInstanciaPorDefecto();
        $esBloqueo = $this->requestStack->getCurrentRequest()?->query->get('es_bloqueo');

        if ($esBloqueo) {
            $estadoBloqueo = $this->entityManager->getRepository(PmsEventoEstado::class)
                ->findOneBy(['id' => PmsEventoEstado::CODIGO_BLOQUEO]);
            if ($estadoBloqueo) {
                $entity->setEstado($estadoBloqueo);
            }

            $estadoNoPagado = $this->entityManager->getRepository(PmsEventoEstadoPago::class)
                ->findOneBy(['id' => 'no-pagado']);
            if ($estadoNoPagado) {
                $entity->setEstadoPago($estadoNoPagado);
            }
        }
        return $entity;
    }

    /**
     * ✅ Configuración de acciones y permisos.
     * Los permisos de Roles se aplican DESPUÉS del parent para prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::BATCH_DELETE);
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        // Lógica de borrado seguro delegada a la entidad
        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsEventoCalendario $evento) {
                return $evento->isSafeToDelete();
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        [$isNewOrEdit, $isBloqueo, $isOta] = $this->resolveContext($pageName);
        $f = $this->buildFields();

        if ($isNewOrEdit && $isBloqueo) $this->applyBloqueoRules($f);
        if ($isOta) $this->applyOtaRules($f);

        // --- Renderizado ---

        // ✅ UUID para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        yield $this->buildSyncStatusBadgeField()->hideOnForm();
        yield $f['descripcion'];

        yield FormField::addPanel('Detalles del Evento')->setIcon('fa fa-calendar-check');
        yield $f['reserva'];
        yield $f['pmsUnidad'];
        yield $f['estado'];
        yield $f['estadoPago'];

        yield FormField::addRow();
        yield $this->decorateInicioField($f['inicio']);
        yield $this->decorateFinField($f['fin']);

        yield $f['adultos']->setRequired(true)->setColumns(6);
        yield $f['ninos']->setRequired(true)->setColumns(6);
        yield $f['monto']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);
        yield $f['comision']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);

        yield FormField::addPanel('Integración Beds24')->setIcon('fa fa-sync')->renderCollapsed();
        yield $f['isOta']->setDisabled(true);
        yield TextField::new('estadoBeds24', 'Estado en Beds24')->setDisabled(true);
        yield AssociationField::new('beds24Links', 'Vínculos de Sincronización')->setDisabled(true);

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-history')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Registrado en')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Última actualización')->onlyOnDetail();
    }

    // --- Helpers de Configuración ---

    private function resolveContext(string $pageName): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $entityInstance = $this->getContext()?->getEntity()->getInstance();

        $isNewOrEdit = \in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true);

        $isBloqueo = (bool)$request?->query->get('es_bloqueo') ||
            ($entityInstance instanceof PmsEventoCalendario &&
                $entityInstance->getEstado()?->getId() === PmsEventoEstado::CODIGO_BLOQUEO &&
                !$entityInstance->getReserva());

        $isOta = $entityInstance instanceof PmsEventoCalendario && $entityInstance->isOta();

        return [$isNewOrEdit, $isBloqueo, $isOta];
    }

    private function buildFields(): array
    {
        $tomSelectNoClear = [
            'placeholder' => false,
            'attr' => ['required' => 'required'],
        ];

        return [
            'descripcion' => TextField::new('descripcion', 'Descripción'),
            'pmsUnidad'   => AssociationField::new('pmsUnidad', 'Unidad')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'estado'      => AssociationField::new('estado', 'Estado')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'estadoPago'  => AssociationField::new('estadoPago', 'Estado de Pago')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'reserva'     => AssociationField::new('reserva', 'Reserva Vincular')->setDisabled(true),
            'inicio'      => DateTimeField::new('inicio', 'Llegada (Check-in)'),
            'fin'         => DateTimeField::new('fin', 'Salida (Check-out)'),
            'adultos'     => IntegerField::new('cantidadAdultos', 'Nº Adultos'),
            'ninos'       => IntegerField::new('cantidadNinos', 'Nº Niños'),
            'monto'       => MoneyField::new('monto', 'Precio Total'),
            'comision'    => MoneyField::new('comision', 'Comisión Canal'),
            'isOta'       => BooleanField::new('isOta', 'Origen OTA'),
        ];
    }

    private function applyBloqueoRules(array $f): void
    {
        $f['descripcion']->setLabel('Motivo del Bloqueo')->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank(['message' => 'El motivo es obligatorio.'])]);

        foreach ([$f['estado'], $f['estadoPago'], $f['reserva'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision'], $f['isOta']] as $field) {
            $field->hideOnForm();
        }
    }

    private function applyOtaRules(array $f): void
    {
        foreach ([$f['pmsUnidad'], $f['reserva'], $f['inicio'], $f['fin'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision'], $f['estado'], $f['estadoPago']] as $field) {
            $field->setDisabled(true);
        }
    }

    private function decorateInicioField(DateTimeField $inicio): DateTimeField
    {
        return $inicio->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'step' => 60,
                    'data-controller' => 'panel--pms-reserva--form-evento-fechas',
                    'data-action' => 'change->panel--pms-reserva--form-evento-fechas#updateEnd'
                ]
            ]);
    }

    private function decorateFinField(DateTimeField $fin): DateTimeField
    {
        return $fin->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                'attr' => ['step' => 60]
            ]);
    }

    private function buildSyncStatusBadgeField(): TextField
    {
        return TextField::new('syncStatus', 'Estado Sincro')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced'  => '<span class="badge badge-success"><i class="fa fa-check"></i> Sincronizado</span>',
                    'error'   => '<span class="badge badge-danger"><i class="fa fa-exclamation-triangle"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Pendiente</span>',
                    default   => '<span class="badge badge-secondary"><i class="fa fa-home"></i> Local</span>',
                };
            })
            ->renderAsHtml();
    }
}