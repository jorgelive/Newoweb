<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Factory\PmsBookingsPullQueueFactory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

final class PmsBookingsPullQueueCrudController extends BaseCrudController
{
    public function __construct(
        private readonly PmsBookingsPullQueueFactory $pullQueueFactory,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string { return PmsBookingsPullQueue::class; }

    public function createEntity(string $entityFqcn): PmsBookingsPullQueue
    {
        return $this->pullQueueFactory->create();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pull Job')
            ->setEntityLabelInPlural('Pull Queue (Bookings)')
            ->setDefaultSort(['runAt' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'lockedBy']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        // --- PANEL DE CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración del Job')->setIcon('fa fa-download');
        yield AssociationField::new('beds24Config', 'Configuración Beds24')->setRequired(true);

        yield AssociationField::new('endpoint', 'Endpoint (Acción)')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();

        yield AssociationField::new('unidades', 'Unidades Filtradas');

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

        yield TextField::new('failedReason', 'Razón del Fallo')->onlyOnDetail();

        // --- PANEL DE AUDITORÍA HTTP (ACTUALIZADO A RAW) ---
        yield FormField::addPanel('Logs de Intercambio (Raw)')->setIcon('fa fa-database')->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'HTTP Request Body')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'HTTP Response Body')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield IntegerField::new('lastHttpCode', 'Código HTTP')->onlyOnDetail();

        // --- PANEL DE RESULTADO PROCESADO (NUEVO) ---
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
    }
}