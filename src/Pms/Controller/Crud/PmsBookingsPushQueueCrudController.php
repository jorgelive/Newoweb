<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

// ✅ Jerarquía de herencia restaurada
use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsBookingsPushQueueCrudController.
 * Gestión de la cola de envío (Push) de actualizaciones hacia Beds24.
 * Implementa UUID v7 y auditoría mediante TimestampTrait.
 */
final class PmsBookingsPushQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsBookingsPushQueue::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Booking Push')
            ->setEntityLabelInPlural('Push Queue (Bookings)')
            // ✅ UUID v7 permite orden cronológico natural
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'lockedBy', 'beds24BookIdOriginal']);
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
        // ✅ Manejo de UUID (IdTrait)
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string)$value);

        // --- PANEL DE CONTEXTO ---
        yield FormField::addPanel('Contexto de Sincronización')->setIcon('fa fa-info-circle');

        yield AssociationField::new('config', 'Cuenta Beds24')
            ->setRequired(true);

        yield AssociationField::new('endpoint', 'Acción API (Endpoint)')
            ->setRequired(true);

        yield AssociationField::new('link', 'Vínculo Reserva')
            ->onlyOnDetail();

        yield TextField::new('beds24BookIdOriginal', 'ID Beds24 Original')
            ->onlyOnDetail();

        // --- PANEL DE ESTADO Y WORKFLOW ---
        yield FormField::addPanel('Estado y Reintentos')->setIcon('fa fa-traffic-light');

        yield ChoiceField::new('status', 'Status Actual')
            ->setChoices([
                'Pendiente' => PmsBookingsPushQueue::STATUS_PENDING,
                'En Proceso' => PmsBookingsPushQueue::STATUS_PROCESSING,
                'Éxito' => PmsBookingsPushQueue::STATUS_SUCCESS,
                'Fallido' => PmsBookingsPushQueue::STATUS_FAILED,
                'Cancelado' => PmsBookingsPushQueue::STATUS_CANCELLED,
            ])
            ->renderAsBadges([
                PmsBookingsPushQueue::STATUS_PENDING => 'secondary',
                PmsBookingsPushQueue::STATUS_PROCESSING => 'info',
                PmsBookingsPushQueue::STATUS_SUCCESS => 'success',
                PmsBookingsPushQueue::STATUS_FAILED => 'danger',
                PmsBookingsPushQueue::STATUS_CANCELLED => 'warning',
            ]);

        yield DateTimeField::new('runAt', 'Programado / Siguiente Intento')
            ->setFormat('dd/MM/yyyy HH:mm');

        yield IntegerField::new('retryCount', 'Intentos Realizados')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield IntegerField::new('maxAttempts', 'Máximo de Intentos')
            ->setHelp('Límite de reintentos antes de marcar como error definitivo.')
            ->setColumns(6);

        yield TextField::new('failedReason', 'Mensaje de Error')
            ->onlyOnDetail();

        // --- PANEL DE AUDITORÍA HTTP (RAW) ---
        yield FormField::addPanel('Payloads Crudos (Auditoría)')->setIcon('fa fa-terminal')->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Request Body (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Response Body (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield IntegerField::new('lastHttpCode', 'Código HTTP')->onlyOnDetail();

        // --- PANEL DE RESULTADO PROCESADO ---
        yield FormField::addPanel('Resultado de Negocio')->setIcon('fa fa-check-circle')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resumen Ejecución (JSON)')
            ->setLanguage('js')
            ->formatValue(function ($value) {
                return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            })
            ->onlyOnDetail();

        // --- PANEL DE CONTROL DE WORKER ---
        yield FormField::addPanel('Trazabilidad Técnica')->setIcon('fa fa-history')->renderCollapsed();

        yield DateTimeField::new('lastSync', 'Último Éxito')
            ->onlyOnDetail();

        yield TextField::new('lockedBy', 'Worker ID');
        yield DateTimeField::new('lockedAt', 'Bloqueado en');

        // --- AUDITORÍA DE SISTEMA (TimestampTrait) ---
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