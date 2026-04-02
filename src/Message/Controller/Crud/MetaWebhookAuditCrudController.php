<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MetaWebhookAudit;
use App\Message\Service\Meta\Webhook\WhatsappMetaWebhookMessageFastTrackService;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        // 🔥 1. Acción personalizada para reejecutar el webhook sincrónico
        $retryWebhook = Action::new('retryWebhook', 'Reejecutar', 'fa fa-sync')
            ->linkToCrudAction('retryWebhookAction')
            ->setCssClass('btn btn-warning text-dark')
            ->displayIf(static function (MetaWebhookAudit $audit) {
                // Solo mostrar si el estado actual es Error o Parcial
                return in_array($audit->getStatus(), [MetaWebhookAudit::STATUS_ERROR, 'partial_error'], true);
            });

        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $retryWebhook)
            ->add(Crud::PAGE_DETAIL, $retryWebhook);

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
                'Error Parcial' => 'partial_error',
                'Error' => MetaWebhookAudit::STATUS_ERROR,
            ])
            ->renderAsBadges([
                MetaWebhookAudit::STATUS_RECEIVED => 'info',
                MetaWebhookAudit::STATUS_PROCESSED => 'success',
                'partial_error' => 'warning',
                MetaWebhookAudit::STATUS_ERROR => 'danger',
            ]);

        yield TextField::new('eventType', 'Evento detectado');
        yield TextField::new('remoteIp', 'IP Origen');
        yield DateTimeField::new('receivedAt', 'Recibido')->setFormat('dd/MM/yyyy HH:mm:ss');

        // ============================================================
        // 2. DATOS TÉCNICOS (Solo Detalle)
        // ============================================================
        yield FormField::addPanel('Datos del Request (Payload)')->setIcon('fa fa-code')->onlyOnDetail();
        yield CodeEditorField::new('headersPretty', 'Cabeceras HTTP')->setLanguage('js')->onlyOnDetail();
        yield CodeEditorField::new('payloadRaw', 'Cuerpo Crudo (Raw)')->setLanguage('js')->onlyOnDetail();
        yield CodeEditorField::new('payloadPretty', 'Cuerpo Decodificado (JSON)')->setLanguage('js')->onlyOnDetail();

        // ============================================================
        // 3. PROCESAMIENTO
        // ============================================================
        yield FormField::addPanel('Procesamiento Interno')->setIcon('fa fa-cogs')->onlyOnDetail();
        yield CodeEditorField::new('processingMetaPretty', 'Metadatos de Proceso')->setLanguage('js')->onlyOnDetail();
        yield TextareaField::new('errorMessage', 'Traza de Error')
            ->setFormTypeOption('disabled', true)
            ->renderAsHtml(false)
            ->onlyOnDetail();

        // ============================================================
        // 4. AUDITORÍA (ESTÁNDAR)
        // ============================================================
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();
        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
    }

    /**
     * 🔥 LÓGICA DE LA ACCIÓN PERSONALIZADA (Modo Sincrónico FastTrack)
     * Extrae el payload de la base de datos y lo vuelve a pasar por el FastTrackService.
     */
    public function retryWebhookAction(
        AdminContext $context,
        WhatsappMetaWebhookMessageFastTrackService $fastTrackService,
        EntityManagerInterface $em
    ): Response {
        /** @var MetaWebhookAudit $audit */
        $audit = $context->getEntity()->getInstance();
        $payload = $audit->getPayload();

        if (empty($payload) || !is_array($payload)) {
            $this->addFlash('danger', 'El JSON almacenado está vacío o es inválido. No se puede reejecutar.');
            return $this->redirect($context->getReferrer() ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        $responseDetails = [];
        $globalErrors = [];
        $processedAny = false;

        // Replicamos la lógica de enrutamiento que está en el Controller principal.
        // Lo ideal a futuro es extraer este bloque a un método "processPayload(array $payload)" en el FastTrackService.
        if (isset($payload['entry'])) {
            foreach ($payload['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    $value = $change['value'];

                    // 1. MESSAGES
                    if (isset($value['messages']) && isset($value['contacts'])) {
                        $contactData = $value['contacts'][0];
                        foreach ($value['messages'] as $messageData) {
                            try {
                                $res = $fastTrackService->processMessage($messageData, $contactData);
                                $responseDetails['messages'][] = $res['id'];
                            } catch (Throwable $e) {
                                $globalErrors[] = ['type' => 'message', 'id' => $messageData['id'] ?? 'unknown', 'error' => $e->getMessage()];
                            }
                        }
                        $processedAny = true;
                    }

                    // 2. CALLS
                    if (isset($value['calls'])) {
                        foreach ($value['calls'] as $callData) {
                            try {
                                $res = $fastTrackService->processCall($callData, $value['contacts'][0] ?? []);
                                $responseDetails['calls'][] = $res['id'];
                            } catch (Throwable $e) {
                                $globalErrors[] = ['type' => 'call', 'id' => $callData['id'] ?? 'unknown', 'error' => $e->getMessage()];
                            }
                        }
                        $processedAny = true;
                    }

                    // 3. STATUSES
                    if (isset($value['statuses'])) {
                        foreach ($value['statuses'] as $statusData) {
                            try {
                                $res = $fastTrackService->processStatus($statusData);
                                $responseDetails['statuses'][] = $res['id'];
                            } catch (Throwable $e) {
                                $globalErrors[] = ['type' => 'status', 'id' => $statusData['id'] ?? 'unknown', 'error' => $e->getMessage()];
                            }
                        }
                        $processedAny = true;
                    }
                }
            }
        }

        // Evaluar resultado final
        $finalStatus = empty($globalErrors) ? MetaWebhookAudit::STATUS_PROCESSED : 'partial_error';
        if (!empty($globalErrors) && empty($responseDetails['messages']) && empty($responseDetails['statuses'])) {
            $finalStatus = MetaWebhookAudit::STATUS_ERROR;
        }

        // Actualizar el registro
        $audit->setStatus($finalStatus);
        $audit->setProcessingMeta([
            'mode' => 'crud_retry', // Distinguimos que fue reprocesado manualmente
            'details' => $responseDetails,
            'errors' => $globalErrors
        ]);

        if (!empty($globalErrors)) {
            $audit->setErrorMessage('Errores en Reintento: ' . json_encode($globalErrors, JSON_UNESCAPED_UNICODE));
            $this->addFlash('warning', 'Se reejecutó el webhook, pero ocurrieron algunos errores. Revisa la traza.');
        } else {
            $audit->setErrorMessage(null); // Limpiamos si fue exitoso
            $this->addFlash('success', '¡Webhook de Meta reprocesado exitosamente!');
        }

        $em->flush();

        return $this->redirect($context->getReferrer() ?? $this->generateUrl('admin'));
    }
}