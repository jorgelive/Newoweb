<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

// Importación añadida

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
            ->setEntityLabelInSingular('Webhook Audit')
            ->setEntityLabelInPlural('Webhooks Audit')
            ->setDefaultSort(['receivedAt' => 'DESC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
        return parent::configureActions($actions);
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
        $id = IdField::new('id');

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
        $remoteIp = TextField::new('remoteIp', 'IP');
        $receivedAt = DateTimeField::new('receivedAt', 'Recibido')->setFormat('yyyy/MM/dd HH:mm');

        $created = DateTimeField::new('created', 'Creado')->setFormat('yyyy/MM/dd HH:mm');
        $updated = DateTimeField::new('updated', 'Actualizado')->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $status,
                $eventType,
                $remoteIp,
                $receivedAt,
            ];
        }

        return [
            FormField::addPanel('Resumen')->setIcon('fa fa-info-circle'),
            $id,
            $status,
            $eventType,
            $remoteIp,
            $receivedAt,

            FormField::addPanel('Payload')->setIcon('fa fa-code'),

            // --- HEADERS (CodeEditor) ---
            CodeEditorField::new('headersPretty', 'Headers')
                ->setLanguage('js')
                ->setFormTypeOption('disabled', true)
                ->onlyOnDetail()
                ->formatValue(function ($value) {
                    if (empty($value)) return '';
                    if (is_string($value)) return $value; // Si ya es string, lo devolvemos tal cual
                    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }),

            // --- PAYLOAD RAW (CodeEditor) ---
            CodeEditorField::new('payloadRaw', 'Payload crudo')
                ->setLanguage('js')
                ->setFormTypeOption('disabled', true)
                ->onlyOnDetail()
                ->formatValue(function ($value) {
                    if (empty($value)) return '';
                    if (is_string($value)) return $value;
                    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }),

            // --- PAYLOAD PRETTY (CodeEditor) ---
            CodeEditorField::new('payloadPretty', 'Payload (JSON)')
                ->setLanguage('js')
                ->setFormTypeOption('disabled', true)
                ->onlyOnDetail()
                ->formatValue(function ($value) {
                    if (empty($value)) return '';
                    if (is_string($value)) return $value;
                    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }),

            FormField::addPanel('Proceso')->setIcon('fa fa-cogs'),

            // --- PROCESSING META (CodeEditor) ---
            CodeEditorField::new('processingMetaPretty', 'Processing meta')
                ->setLanguage('js')
                ->setFormTypeOption('disabled', true)
                ->onlyOnDetail()
                ->formatValue(function ($value) {
                    if (empty($value)) return '';
                    if (is_string($value)) return $value;
                    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }),

            // El error suele ser texto plano (stack trace), mejor dejarlo como Textarea
            TextareaField::new('errorMessage', 'Error')
                ->setFormTypeOption('disabled', true)
                ->renderAsHtml(false)
                ->onlyOnDetail(),

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $created->onlyOnDetail(),
            $updated->onlyOnDetail(),
        ];
    }
}