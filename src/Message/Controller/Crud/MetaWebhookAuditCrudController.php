<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MetaWebhookAudit;
use App\Panel\Controller\Crud\BaseCrudController;
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
 * MetaWebhookAuditCrudController.
 * Registro histórico de notificaciones entrantes desde Meta (WhatsApp Cloud API).
 * Controlador de solo lectura heredando de BaseCrudController para garantizar trazabilidad.
 */
final class MetaWebhookAuditCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MetaWebhookAudit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Auditoría Webhook Meta')
            ->setEntityLabelInPlural('Auditorías Webhooks Meta')
            ->setDefaultSort(['receivedAt' => 'DESC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    /**
     * Configuración de acciones.
     * Al ser un LOG de auditoría estricto, deshabilitamos la modificación y eliminación de datos.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            // Asumo el mismo rol de visualización que en el PMS, ajústalo a tu política si requieres algo como Roles::MESSAGES_SHOW
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

        // ID Corto (Index) para no romper el diseño de la tabla
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        // ID Completo (Detalle) para trazabilidad exacta
        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Recibido' => MetaWebhookAudit::STATUS_RECEIVED,
                'Procesado' => MetaWebhookAudit::STATUS_PROCESSED,
                'Error' => MetaWebhookAudit::STATUS_ERROR,
            ])
            ->renderAsBadges([
                MetaWebhookAudit::STATUS_RECEIVED => 'info',
                MetaWebhookAudit::STATUS_PROCESSED => 'success',
                MetaWebhookAudit::STATUS_ERROR => 'danger',
            ]);

        yield TextField::new('eventType', 'Evento detectado');

        yield TextField::new('remoteIp', 'IP Origen');

        yield DateTimeField::new('receivedAt', 'Recibido')
            ->setFormat('dd/MM/yyyy HH:mm:ss');

        // ============================================================
        // 2. DATOS TÉCNICOS (Solo Detalle)
        // ============================================================
        yield FormField::addPanel('Datos del Request (Payload)')
            ->setIcon('fa fa-code')
            ->onlyOnDetail();

        // Cabeceras (Propiedad Virtual)
        yield CodeEditorField::new('headersPretty', 'Cabeceras HTTP')
            ->setLanguage('js')
            ->onlyOnDetail();

        // Payload Original (Para debug estricto si falla la decodificación)
        yield CodeEditorField::new('payloadRaw', 'Cuerpo Crudo (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        // Payload JSON Formateado (Propiedad Virtual)
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
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}