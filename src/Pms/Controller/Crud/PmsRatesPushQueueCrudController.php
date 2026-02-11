<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsRatesPushQueue;
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
 * PmsRatesPushQueueCrudController.
 * Gestión de la cola de envío de tarifas hacia Beds24.
 * Hereda de BaseCrudController y utiliza UUID v7 con prioridad de Roles.
 */
final class PmsRatesPushQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsRatesPushQueue::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola de Tarifas')
            ->setEntityLabelInPlural('Cola de Tarifas')
            ->setDefaultSort(['runAt' => 'ASC', 'id' => 'DESC'])
            ->setSearchFields(['id', 'status', 'failedReason', 'unidad.nombre', 'unidadBeds24Map.beds24RoomId'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    /**
     * ✅ Configuración de acciones y permisos.
     * Prioridad absoluta a Roles sobre la configuración base del panel.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // Primero obtenemos la configuración global
        $actions = parent::configureActions($actions);

        // Aplicamos permisos específicos después para garantizar la restricción
        return $actions
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

        // --- PANEL 1: CONTEXTO DE MAPEO ---
        yield FormField::addPanel('Contexto de Mapeo')->setIcon('fa fa-map-marker-alt');

        yield AssociationField::new('unidad', 'Unidad PMS')
            ->setRequired(true);

        yield AssociationField::new('unidadBeds24Map', 'Mapa Beds24')
            ->setHelp('Define el RoomID de destino en la API.')
            ->setRequired(true);

        yield AssociationField::new('endpoint', 'Endpoint Técnico')
            ->onlyOnDetail();

        yield AssociationField::new('tarifaRango', 'Origen (Rango)')
            ->onlyOnDetail()
            ->setHelp('Rango original que disparó este cambio.');

        // --- PANEL 2: PAYLOAD (DATOS A ENVIAR) ---
        yield FormField::addPanel('Datos de Tarifa')->setIcon('fa fa-money-bill-wave');

        yield DateField::new('fechaInicio', 'Desde')
            ->setFormat('yyyy-MM-dd');

        yield DateField::new('fechaFin', 'Hasta')
            ->setFormat('yyyy-MM-dd');

        yield TextField::new('precio', 'Precio')
            ->setColumns(6);

        yield IntegerField::new('minStay', 'Estancia Mínima')
            ->setColumns(6);

        yield AssociationField::new('moneda', 'Moneda')->onlyOnDetail();

        // --- PANEL 3: ESTADO DE EJECUCIÓN ---
        yield FormField::addPanel('Sincronización (Worker)')->setIcon('fa fa-server');

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Pendiente'   => PmsRatesPushQueue::STATUS_PENDING,
                'Procesando'  => PmsRatesPushQueue::STATUS_PROCESSING,
                'Enviado OK'  => PmsRatesPushQueue::STATUS_SUCCESS,
                'Fallido'     => PmsRatesPushQueue::STATUS_FAILED,
                'Cancelado'   => PmsRatesPushQueue::STATUS_CANCELED,

            ])
            ->renderAsBadges([
                PmsRatesPushQueue::STATUS_PENDING    => 'secondary',
                PmsRatesPushQueue::STATUS_PROCESSING => 'info',
                PmsRatesPushQueue::STATUS_SUCCESS    => 'success',
                PmsRatesPushQueue::STATUS_FAILED     => 'danger',
                PmsRatesPushQueue::STATUS_CANCELED  => 'warning',

            ]);

        yield DateTimeField::new('runAt', 'Programado');

        yield DateTimeField::new('lastSync', 'Sincronizado En')
            ->onlyOnIndex();

        yield IntegerField::new('retryCount', 'Reintentos')
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield IntegerField::new('maxAttempts', 'Límite Intentos')
            ->setColumns(6)
            ->onlyOnDetail();

        yield TextField::new('failedReason', 'Mensaje de Error')
            ->onlyOnDetail();

        // --- PANEL 4: AUDITORÍA TÉCNICA ---
        yield FormField::addPanel('Trazabilidad Técnica')->setIcon('fa fa-terminal')->onlyOnDetail();

        yield TextField::new('lockedBy', 'Worker Lock')->onlyOnDetail();
        yield DateTimeField::new('lockedAt', 'Bloqueado En')->onlyOnDetail();
        yield IntegerField::new('lastHttpCode', 'HTTP Code')->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Request JSON (Raw)')
            ->setLanguage('javascript')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Response JSON (Raw)')
            ->setLanguage('javascript')
            ->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resultado del Proceso')
            ->setLanguage('javascript')
            ->formatValue(function ($value) {
                return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            })
            ->onlyOnDetail();

        // --- PANEL 5: AUDITORÍA DE SISTEMA ---
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