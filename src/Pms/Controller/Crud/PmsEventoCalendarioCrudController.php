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
 * Gestión de eventos individuales (Bloqueos o Estancias sueltas).
 * Integra PmsEventoCalendarioFactory para integridad de links Beds24.
 */
final class PmsEventoCalendarioCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsEventoCalendarioFactory $eventoFactory // ✅ Inyección del Factory
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoCalendario::class;
    }

    /**
     * ✅ CREACIÓN: Prepara la entidad inicial.
     * Detecta si venimos con el flag `?es_bloqueo=1` para pre-configurar estados.
     */
    public function createEntity(string $entityFqcn): PmsEventoCalendario
    {
        // 1. Instancia base desde Factory
        $entity = $this->eventoFactory->createForUi();

        // 2. Detección de contexto "Bloqueo"
        $esBloqueo = (bool) $this->requestStack->getCurrentRequest()?->query->get('es_bloqueo');

        if ($esBloqueo) {
            $estadoBloqueo = $this->entityManager->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_BLOQUEO);
            if ($estadoBloqueo) {
                $entity->setEstado($estadoBloqueo);
            }

            $estadoNoPagado = $this->entityManager->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO);
            if ($estadoNoPagado) {
                $entity->setEstadoPago($estadoNoPagado);
            }
        }

        return $entity;
    }

    /**
     * ✅ PERSIST: Guardado inicial.
     * El formulario ya llenó la Unidad. Llamamos al Factory para crear los links (Root + Mirrors).
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsEventoCalendario) {
            // Defensa: Las OTAs raramente se crean a mano, pero si ocurre, también necesitan links.
            // Si es bloqueo o evento manual, generamos la estructura.
            $this->eventoFactory->hydrateLinksForUi($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * ✅ UPDATE: Edición.
     * Lógica crítica: Solo regeneramos links si cambió la Unidad Física.
     * Usamos computeChangeSet para máxima seguridad.
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsEventoCalendario) {
            // 1. Blindaje: OTAs no se editan estructuralmente desde UI
            if ($entityInstance->isOta()) {
                parent::updateEntity($entityManager, $entityInstance);
                return;
            }

            $uow = $entityManager->getUnitOfWork();

            // 2. Forzamos el cálculo de cambios para detectar modificación de relaciones
            $uow->computeChangeSet($entityManager->getClassMetadata(PmsEventoCalendario::class), $entityInstance);
            $changes = $uow->getEntityChangeSet($entityInstance);

            // 3. Si la clave 'pmsUnidad' aparece en los cambios, es que el usuario la tocó.
            if (array_key_exists('pmsUnidad', $changes)) {
                // Regenerar estructura (Esto borra los links viejos y crea nuevos para la nueva unidad)
                $this->eventoFactory->hydrateLinksForUi($entityInstance);
            }

            // Si NO cambió la unidad, no tocamos nada. Preservamos el beds24BookId existente.
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::BATCH_DELETE);
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DELETE);

        // Lógica de borrado seguro: No permitir borrar OTAs activas desde aquí
        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsEventoCalendario $eventoCalendario) {
                return $eventoCalendario->isSafeToDelete();
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_EDIT, Action::DELETE, $checkBorrado);

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
        // Resolución de contexto para UI dinámica
        [$isNewOrEdit, $isBloqueo, $isOta] = $this->resolveContext($pageName);

        // Construcción de campos base
        $f = $this->buildFields();

        // Aplicación de reglas de negocio visuales
        if ($isNewOrEdit && $isBloqueo) {
            $this->applyBloqueoRules($f);
        }
        if ($isOta) {
            $this->applyOtaRules($f);
        }

        // --- RENDERIZADO DEL FORMULARIO ---

        // 1. Identificadores
        yield TextField::new('id', 'UUID')->onlyOnDetail();
        yield TextField::new('localizador', 'Localizador')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6)
            ->formatValue(fn($v) => $v ? sprintf('<span class="badge badge-secondary">%s</span>', $v) : '');

        // 2. Estado Sincronización (Badge visual)
        yield $this->buildSyncStatusBadgeField()->hideOnForm();

        // 3. Datos Principales
        yield $f['descripcion'];

        yield FormField::addPanel('Detalles del Evento')->setIcon('fa fa-calendar-check');
        yield $f['reserva'];
        yield $f['pmsUnidad'];
        yield $f['estado'];
        yield $f['estadoPago'];

        yield FormField::addRow();
        yield $this->decorateInicioField($f['inicio']);
        yield $this->decorateFinField($f['fin']);

        // 4. Datos Económicos y Pax (Ocultos en bloqueos)
        yield $f['adultos']->setRequired(true)->setColumns(6);
        yield $f['ninos']->setRequired(true)->setColumns(6);
        yield $f['monto']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);
        yield $f['comision']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);

        // 5. Integración (Solo lectura)
        yield FormField::addPanel('Integración Beds24')->setIcon('fa fa-sync')->renderCollapsed();
        yield $f['isOta']->setDisabled(true);
        yield TextField::new('estadoBeds24', 'Estado en Beds24')->setDisabled(true);
        yield AssociationField::new('beds24Links', 'Vínculos Técnicos')->setDisabled(true);

        // 6. Auditoría
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-history')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Registrado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }

    // =========================================================================
    // HELPERS PRIVADOS DE UI
    // =========================================================================

    private function resolveContext(string $pageName): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $entityInstance = $this->getContext()?->getEntity()->getInstance();

        $isNewOrEdit = \in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true);

        // Es bloqueo si viene por URL o si la entidad ya tiene estado de bloqueo
        $isBloqueo = (bool)$request?->query->get('es_bloqueo') ||
            ($entityInstance instanceof PmsEventoCalendario &&
                $entityInstance->getEstado()?->getId() === PmsEventoEstado::CODIGO_BLOQUEO &&
                !$entityInstance->getReserva());

        $isOta = $entityInstance instanceof PmsEventoCalendario && $entityInstance->isOta();

        return [$isNewOrEdit, $isBloqueo, $isOta];
    }

    private function buildFields(): array
    {
        $tomSelectNoClear = ['placeholder' => false, 'attr' => ['required' => 'required']];

        return [
            'descripcion' => TextField::new('descripcion', 'Descripción/Motivo'),
            'pmsUnidad'   => AssociationField::new('pmsUnidad', 'Unidad')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'estado'      => AssociationField::new('estado', 'Estado')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'estadoPago'  => AssociationField::new('estadoPago', 'Estado de Pago')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),
            'reserva'     => AssociationField::new('reserva', 'Reserva Padre')->setDisabled(true),
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
        // En bloqueos, la descripción es obligatoria
        $f['descripcion']->setLabel('Motivo del Bloqueo')->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank(['message' => 'El motivo es obligatorio.'])]);

        // Ocultar campos irrelevantes para un bloqueo técnico
        foreach ([$f['estado'], $f['estadoPago'], $f['reserva'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision'], $f['isOta']] as $field) {
            $field->hideOnForm();
        }
    }

    private function applyOtaRules(array $f): void
    {
        // Bloquear campos críticos si viene de una OTA para proteger la sincronización
        foreach ([$f['pmsUnidad'], $f['reserva'], $f['inicio'], $f['fin'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision']] as $field) {
            $field->setDisabled(true);
        }
    }

    private function decorateInicioField(DateTimeField $inicio): DateTimeField
    {
        return $inicio->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                // JS Controller para UX de fechas (opcional)
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
            ->setFormTypeOptions(['widget' => 'single_text', 'html5' => true, 'attr' => ['step' => 60]]);
    }

    private function buildSyncStatusBadgeField(): TextField
    {
        return TextField::new('syncStatus', 'Estado Sincro')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced'  => '<span class="badge badge-success"><i class="fa fa-check"></i> Sync</span>',
                    'error'   => '<span class="badge badge-danger"><i class="fa fa-exclamation"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Pend.</span>',
                    default   => '<span class="badge badge-secondary">Local</span>',
                };
            })
            ->renderAsHtml();
    }
}