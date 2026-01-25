<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBookingsPushQueue;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

final class PmsBookingsPushQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string { return PmsBookingsPushQueue::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Booking Push')
            ->setEntityLabelInPlural('Push Queue (Bookings)')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'lockedBy', 'beds24BookIdOriginal']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        // --- PANEL DE CONTEXTO ---
        yield FormField::addPanel('Contexto de Sincronización')->setIcon('fa fa-info-circle');
        yield AssociationField::new('beds24Config', 'Cuenta Beds24');
        yield AssociationField::new('endpoint', 'Acción API (Endpoint)');
        yield AssociationField::new('link', 'Vínculo Reserva')->onlyOnDetail();
        yield TextField::new('beds24BookIdOriginal', 'ID Beds24 Original')->onlyOnDetail();

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

        // ELIMINADO: needsSync ya no existe en el Baseline

        yield DateTimeField::new('runAt', 'Programado / Siguiente Intento');

        yield IntegerField::new('retryCount', 'Intentos Realizados')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield IntegerField::new('maxAttempts', 'Máximo de Intentos')
            ->setHelp('Límite de reintentos antes de marcar como error definitivo.')
            ->setColumns(6);

        yield TextField::new('failedReason', 'Mensaje de Error')->onlyOnDetail();

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
        yield DateTimeField::new('lastSync', 'Último Éxito')->onlyOnDetail();
        yield TextField::new('lockedBy', 'Worker ID');
        yield DateTimeField::new('lockedAt', 'Bloqueado en');
    }
}