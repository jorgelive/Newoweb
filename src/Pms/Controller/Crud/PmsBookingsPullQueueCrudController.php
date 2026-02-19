<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

// ✅ Jerarquía de herencia restaurada
use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Factory\PmsBookingsPullQueueFactory;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsBookingsPullQueueCrudController.
 * Gestión de la cola de procesos para la obtención (Pull) de reservas.
 * Implementa UUID v7 y auditoría mediante TimestampTrait.
 */
final class PmsBookingsPullQueueCrudController extends BaseCrudController
{
    public function __construct(
        private readonly PmsBookingsPullQueueFactory $pullQueueFactory,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsBookingsPullQueue::class;
    }

    /**
     * ✅ Se mantiene el uso de la Factory para la creación de la entidad.
     */
    public function createEntity(string $entityFqcn): PmsBookingsPullQueue
    {
        return $this->pullQueueFactory->create();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarea de Pull')
            ->setEntityLabelInPlural('Cola de Pull (Reservas)')
            ->setDefaultSort(['runAt' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'lockedBy']);
    }

    /**
     * ✅ Configuración de acciones integrando seguridad por Roles.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID para visualización
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string)$value);

        // --- PANEL DE CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración del Job')->setIcon('fa fa-download');

        yield AssociationField::new('config', 'Configuración Beds24')
            ->setRequired(true);

        yield AssociationField::new('endpoint', 'Endpoint (Acción)')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();

        yield AssociationField::new('unidades', 'Unidades Filtradas')
            ->setHelp('Si se deja vacío, se asumen todas las unidades configuradas.');

        yield DateField::new('arrivalFrom', 'Llegadas Desde')->setColumns(6);
        yield DateField::new('arrivalTo', 'Llegadas Hasta')->setColumns(6);

        // --- PANEL DE EJECUCIÓN Y REINTENTOS ---
        yield FormField::addPanel('Estado de Ejecución')->setIcon('fa fa-clock');

        yield ChoiceField::new('status', 'Estado Actual')
            ->setChoices([
                'Pendiente' => PmsBookingsPullQueue::STATUS_PENDING,
                'En Proceso' => PmsBookingsPullQueue::STATUS_PROCESSING,
                'Completado' => PmsBookingsPullQueue::STATUS_SUCCESS,
                'Fallido' => PmsBookingsPullQueue::STATUS_FAILED,
            ])
            ->renderAsBadges([
                PmsBookingsPullQueue::STATUS_PENDING => 'secondary',
                PmsBookingsPullQueue::STATUS_PROCESSING => 'info',
                PmsBookingsPullQueue::STATUS_SUCCESS => 'success',
                PmsBookingsPullQueue::STATUS_FAILED => 'danger',
            ]);

        yield DateTimeField::new('runAt', 'Programado para')->setFormat('dd/MM/yyyy HH:mm');

        yield IntegerField::new('retryCount', 'Intentos Realizados')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield IntegerField::new('maxAttempts', 'Máximo de Intentos')
            ->setColumns(6);

        yield TextField::new('failedReason', 'Razón del Fallo')
            ->onlyOnDetail();

        // --- PANEL DE AUDITORÍA HTTP (RAW) ---
        yield FormField::addPanel('Logs de Intercambio (Raw)')->setIcon('fa fa-database')->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'HTTP Request Body')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'HTTP Response Body')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield IntegerField::new('lastHttpCode', 'Código HTTP')->onlyOnDetail();

        // --- PANEL DE RESULTADO PROCESADO ---
        yield FormField::addPanel('Resultado de Negocio')->setIcon('fa fa-check-circle')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Execution Summary (JSON)')
            ->setLanguage('js')
            ->formatValue(function ($value) {
                return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            })
            ->onlyOnDetail();

        // --- PANEL DE CONTROL DE WORKER ---
        yield FormField::addPanel('Información del Worker')->setIcon('fa fa-microchip')->renderCollapsed();

        yield TextField::new('lockedBy', 'Worker ID');
        yield DateTimeField::new('lockedAt', 'Bloqueado en');

        // --- AUDITORÍA DE SISTEMA ---
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