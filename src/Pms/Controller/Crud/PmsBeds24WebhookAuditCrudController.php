<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Dispatch\ProcessBeds24WebhookDispatch;
use App\Pms\Entity\PmsBeds24WebhookAudit;
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
use Symfony\Component\Messenger\MessageBusInterface;

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
        // 🔥 1. Creamos la acción personalizada para reejecutar el webhook
        $retryWebhook = Action::new('retryWebhook', 'Reejecutar', 'fa fa-sync')
            ->linkToCrudAction('retryWebhookAction') // Enlaza con el método que creamos abajo
            ->setCssClass('btn btn-warning text-dark')
            ->displayIf(static function (PmsBeds24WebhookAudit $audit) {
                return in_array($audit->getStatus(), [
                    PmsBeds24WebhookAudit::STATUS_ERROR,
                    PmsBeds24WebhookAudit::STATUS_PARTIAL_ERROR // <-- ¡El botón ahora saldrá aquí también!
                ], true);
            });

        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // 🔥 Añadimos el botón tanto a la vista de lista (INDEX) como al detalle (DETAIL)
            ->add(Crud::PAGE_INDEX, $retryWebhook)
            ->add(Crud::PAGE_DETAIL, $retryWebhook);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            // Aseguramos que la acción de reintento requiera un nivel de acceso adecuado (ej. Admin o algo similar si lo tienes)
            // ->setPermission('retryWebhook', Roles::ROLE_ADMIN)
            ;
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
                'Queued' => PmsBeds24WebhookAudit::STATUS_QUEUED,
                'Received' => PmsBeds24WebhookAudit::STATUS_RECEIVED,
                'Processed' => PmsBeds24WebhookAudit::STATUS_PROCESSED,
                'Partial Error' => PmsBeds24WebhookAudit::STATUS_PARTIAL_ERROR,
                'Error' => PmsBeds24WebhookAudit::STATUS_ERROR,
            ])
            ->renderAsBadges([
                PmsBeds24WebhookAudit::STATUS_QUEUED => 'info',
                PmsBeds24WebhookAudit::STATUS_RECEIVED => 'primary',
                PmsBeds24WebhookAudit::STATUS_PROCESSED => 'success',
                PmsBeds24WebhookAudit::STATUS_PARTIAL_ERROR => 'warning',
                PmsBeds24WebhookAudit::STATUS_ERROR => 'danger',
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
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }

    /**
     * 🔥 LÓGICA DE LA ACCIÓN PERSONALIZADA
     * Este método recibe el clic del botón "Reejecutar".
     * Reencola el webhook fallido en Messenger utilizando los datos crudos almacenados.
     */
    public function retryWebhookAction(
        AdminContext $context,
        MessageBusInterface $messageBus,
        EntityManagerInterface $em
    ): Response {
        /** @var PmsBeds24WebhookAudit $audit */
        $audit = $context->getEntity()->getInstance();

        // 1. Extraer el Token de las cabeceras guardadas
        $headers = $audit->getHeaders();
        $token = '';

        // Buscamos la llave 'x-beds24-webhook-token'. Symfony suele guardar todo en minúsculas en los headers.
        if (isset($headers['x-beds24-webhook-token'][0])) {
            $token = $headers['x-beds24-webhook-token'][0];
        }

        // Si no encontramos el token, abortamos para no encolar algo que va a fallar de todos modos
        if (empty($token)) {
            $this->addFlash('danger', 'Error crítico: No se encontró el token de seguridad (x-beds24-webhook-token) en las cabeceras almacenadas de este registro.');
            return $this->redirect($context->getReferrer() ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        // 2. Despachar el mensaje al bus (igual que si entrara por el Controller original)
        $message = new ProcessBeds24WebhookDispatch(
            (string) $audit->getId(),
            (string) $audit->getPayloadRaw(), // Nos aseguramos de enviar el rawPayload
            $token
        );

        $messageBus->dispatch($message);

        // 3. Actualizar el estado visual del registro a "En Cola"
        $audit->setStatus(PmsBeds24WebhookAudit::STATUS_QUEUED);
        $audit->setErrorMessage(null); // Limpiamos el error viejo para no confundir

        $em->flush(); // Guardamos el cambio de estado

        // 4. Feedback visual al usuario
        $this->addFlash('success', '¡Webhook reencolado con éxito! El sistema lo procesará en segundo plano en los próximos segundos.');

        // 5. Retornar al usuario a donde estaba
        return $this->redirect($context->getReferrer() ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());    }
}