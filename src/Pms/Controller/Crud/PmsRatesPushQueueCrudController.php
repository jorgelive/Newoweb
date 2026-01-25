<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsRatesPushQueue;
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

final class PmsRatesPushQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string { return PmsRatesPushQueue::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola de Tarifas')
            ->setEntityLabelInPlural('Cola de Tarifas')
            ->setDefaultSort(['runAt' => 'ASC', 'id' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'unidad.nombre', 'unidadBeds24Map.beds24RoomId'])
            ->setPaginatorPageSize(30);
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

        // --- PANEL 1: CONTEXTO DE NEGOCIO (EL MAPA) ---
        yield FormField::addPanel('Contexto de Mapeo')->setIcon('fa fa-map-marker-alt');

        yield AssociationField::new('unidad', 'Unidad PMS')
            ->setRequired(true);

        yield AssociationField::new('unidadBeds24Map', 'Mapa Beds24')
            ->setHelp('Define el RoomID de destino')
            ->setRequired(true);

        yield AssociationField::new('endpoint', 'Endpoint')
            ->onlyOnDetail();

        yield AssociationField::new('tarifaRango', 'Origen (Rango)')
            ->onlyOnDetail()
            ->setHelp('Rango original que generó este cambio');

        // --- PANEL 2: PAYLOAD (DATOS A ENVIAR) ---
        yield FormField::addPanel('Datos de Tarifa (Payload)')->setIcon('fa fa-money-bill-wave');

        yield DateField::new('fechaInicio', 'Desde')
            ->setFormat('yyyy-MM-dd');

        yield DateField::new('fechaFin', 'Hasta')
            ->setFormat('yyyy-MM-dd');

        yield TextField::new('precio', 'Precio')
            ->setColumns(6);

        yield IntegerField::new('minStay', 'Min Stay')
            ->setColumns(6);

        yield AssociationField::new('moneda', 'Moneda')->onlyOnDetail();

        // --- PANEL 3: ESTADO DE EJECUCIÓN (WORKER) ---
        yield FormField::addPanel('Estado de Sincronización')->setIcon('fa fa-server');

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Pendiente'   => PmsRatesPushQueue::STATUS_PENDING,
                'Procesando'  => PmsRatesPushQueue::STATUS_PROCESSING,
                'Enviado OK'  => PmsRatesPushQueue::STATUS_SUCCESS,
                'Fallido'     => PmsRatesPushQueue::STATUS_FAILED,
            ])
            ->renderAsBadges([
                PmsRatesPushQueue::STATUS_PENDING    => 'secondary',
                PmsRatesPushQueue::STATUS_PROCESSING => 'info',
                PmsRatesPushQueue::STATUS_SUCCESS    => 'success',
                PmsRatesPushQueue::STATUS_FAILED     => 'danger',
            ]);

        yield DateTimeField::new('runAt', 'Próxima Ejecución');

        yield DateTimeField::new('lastSync', 'Sincronizado En')
            ->onlyOnIndex();

        yield IntegerField::new('retryCount', 'Reintentos')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield IntegerField::new('maxAttempts', 'Max Intentos')
            ->setColumns(6)
            ->onlyOnDetail();

        yield TextField::new('failedReason', 'Error')
            ->onlyOnDetail()
            ->renderAsHtml();

        // --- PANEL 4: AUDITORÍA TÉCNICA (Solo Detalle) ---
        yield FormField::addPanel('Auditoría Técnica (Logs)')->setIcon('fa fa-terminal')->onlyOnDetail();

        yield TextField::new('lockedBy', 'Worker Lock')->onlyOnDetail();
        yield DateTimeField::new('lockedAt', 'Lock Time')->onlyOnDetail();

        yield IntegerField::new('lastHttpCode', 'HTTP Code')->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Request JSON')
            ->setLanguage('javascript')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Response JSON')
            ->setLanguage('javascript')
            ->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Execution Result')
            ->setLanguage('javascript')
            ->formatValue(function ($value) {
                return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            })
            ->onlyOnDetail();
    }
}