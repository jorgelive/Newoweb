<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsBeds24WebhookAuditCrudController.
 * Registro histórico de notificaciones entrantes desde Beds24.
 * Controlador de solo lectura heredando de BaseCrudController.
 */
final class PmsBeds24WebhookAuditCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsBeds24WebhookAudit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Auditoría Webhook')
            ->setEntityLabelInPlural('Auditoría Webhooks')
            ->setDefaultSort(['receivedAt' => 'DESC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    /**
     * Configuración de acciones.
     * Al ser un LOG, deshabilitamos la modificación de datos.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('eventType')
            ->add('remoteIp')
            ->add('receivedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. RESUMEN DE RECEPCIÓN
        // ============================================================
        yield FormField::addPanel('Resumen de Recepción')->setIcon('fa fa-info-circle');

        // ID Corto (Index)
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        // ID Completo (Detalle)
        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Received' => PmsBeds24WebhookAudit::STATUS_RECEIVED,
                'Processed' => PmsBeds24WebhookAudit::STATUS_PROCESSED,
                'Error' => PmsBeds24WebhookAudit::STATUS_ERROR,
            ])
            ->renderAsBadges([
                PmsBeds24WebhookAudit::STATUS_RECEIVED => 'info',
                PmsBeds24WebhookAudit::STATUS_PROCESSED => 'success',
                PmsBeds24WebhookAudit::STATUS_ERROR => 'danger',
            ]);

        yield TextField::new('eventType', 'Evento detectado');

        yield TextField::new('remoteIp', 'IP Origen');

        yield DateTimeField::new('receivedAt', 'Recibido')
            ->setFormat('yyyy/MM/dd HH:mm:ss');

        // ============================================================
        // 2. DATOS TÉCNICOS (Solo Detalle)
        // ============================================================
        yield FormField::addPanel('Datos del Request (Payload)')
            ->setIcon('fa fa-code')
            ->onlyOnDetail();

        // Cabeceras (Virtual Property)
        yield CodeEditorField::new('headersPretty', 'Cabeceras HTTP')
            ->setLanguage('js')
            ->onlyOnDetail();

        // Payload Original
        yield CodeEditorField::new('payloadRaw', 'Cuerpo Crudo (Raw)')
            ->setLanguage('js') // Raw suele ser texto plano o json minificado
            ->onlyOnDetail();

        // Payload JSON Formateado
        yield CodeEditorField::new('payloadPretty', 'Cuerpo Decodificado (JSON)')
            ->setLanguage('js')
            ->onlyOnDetail();

        // ============================================================
        // 3. PROCESAMIENTO
        // ============================================================
        yield FormField::addPanel('Procesamiento Interno')
            ->setIcon('fa fa-cogs')
            ->onlyOnDetail();

        yield CodeEditorField::new('processingMetaPretty', 'Metadatos de Proceso')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield TextareaField::new('errorMessage', 'Traza de Error')
            ->setFormTypeOption('disabled', true) // Readonly visual
            ->renderAsHtml(false)
            ->onlyOnDetail();

        // ============================================================
        // 4. AUDITORÍA (ESTÁNDAR)
        // ============================================================
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}