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
     * Configuración de acciones y permisos.
     * ✅ Se deshabilita la edición y creación para garantizar la inmutabilidad de los logs.
     * ✅ Se integra la seguridad por Roles.
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
        // ✅ Manejo de UUID
        $id = TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        $status = ChoiceField::new('status', 'Estado')
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

        $eventType = TextField::new('eventType', 'Evento');
        $remoteIp = TextField::new('remoteIp', 'IP Origen');
        $receivedAt = DateTimeField::new('receivedAt', 'Recibido')
            ->setFormat('yyyy/MM/dd HH:mm:ss');

        // ✅ Auditoría mediante TimestampTrait
        $createdAt = DateTimeField::new('createdAt', 'Creado')->setFormat('yyyy/MM/dd HH:mm');
        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                TextField::new('id', 'ID Corto')->formatValue(fn($v) => substr((string)$v, 0, 8) . '...'),
                $status,
                $eventType,
                $remoteIp,
                $receivedAt,
            ];
        }

        return [
            FormField::addPanel('Resumen de Recepción')->setIcon('fa fa-info-circle'),
            $id,
            $status,
            $eventType,
            $remoteIp,
            $receivedAt,

            FormField::addPanel('Datos del Request (Payload)')->setIcon('fa fa-code'),

            // --- HEADERS (Usando el método virtual de la entidad) ---
            CodeEditorField::new('headersPretty', 'Cabeceras HTTP')
                ->setLanguage('js')
                ->onlyOnDetail(),

            // --- PAYLOAD RAW ---
            CodeEditorField::new('payloadRaw', 'Cuerpo Crudo (Raw)')
                ->setLanguage('js')
                ->onlyOnDetail(),

            // --- PAYLOAD JSON ---
            CodeEditorField::new('payloadPretty', 'Cuerpo Decodificado (JSON)')
                ->setLanguage('js')
                ->onlyOnDetail(),

            FormField::addPanel('Procesamiento Interno')->setIcon('fa fa-cogs'),

            // --- PROCESSING META ---
            CodeEditorField::new('processingMetaPretty', 'Metadatos de Proceso')
                ->setLanguage('js')
                ->onlyOnDetail(),

            TextareaField::new('errorMessage', 'Traza de Error')
                ->setFormTypeOption('disabled', true)
                ->onlyOnDetail(),

            FormField::addPanel('Auditoría Técnica')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->onlyOnDetail(),
            $updatedAt->onlyOnDetail(),
        ];
    }
}