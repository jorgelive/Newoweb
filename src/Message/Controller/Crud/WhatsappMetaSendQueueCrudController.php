<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\WhatsappMetaSendQueue;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
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

/**
 * WhatsappMetaSendQueueCrudController.
 * Gestión de la cola de salida de mensajes vía WhatsApp Meta API.
 * Optimizado para seguimiento de estados de entrega y auditoría de payloads.
 */
final class WhatsappMetaSendQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return WhatsappMetaSendQueue::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola WhatsApp')
            ->setEntityLabelInPlural('Cola WhatsApp (Meta)')
            ->setSearchFields(['destinationPhone', 'status', 'deliveryStatus', 'wamId'])
            // ✅ Ordenado por actualización más reciente
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        // ✅ Activamos Detail y deshabilitamos edición/creación manual
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'UUID')
            ->onlyOnDetail();

        // --- PANEL 1: DESTINO Y ESTADO ---
        yield FormField::addPanel('Estado del Envío')->setIcon('fa fa-whatsapp');

        yield TextField::new('destinationPhone', 'Destinatario')
            ->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente' => 'pending',
                'Éxito' => 'success',
                'Fallido' => 'failed',
            ])
            ->renderAsBadges([
                'pending' => 'warning',
                'success' => 'success',
                'failed' => 'danger'
            ])
            ->setColumns(6);

        yield ChoiceField::new('deliveryStatus', 'WhatsApp Status (Meta)')
            ->setChoices([
                'Submitted' => 'submitted',
                'Delivered' => 'delivered',
                'Read' => 'read',
                'Failed' => 'failed'
            ])
            ->renderAsBadges([
                'read' => 'info',
                'delivered' => 'primary',
                'submitted' => 'secondary',
                'failed' => 'danger'
            ])
            ->setColumns(6);

        yield DateTimeField::new('runAt', 'Programado para')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setColumns(6);

        yield TextField::new('wamId', 'WhatsApp Message ID (WAMID)')
            ->onlyOnDetail();

        // --- PANEL 2: RELACIONES ---
        yield FormField::addPanel('Relaciones')->setIcon('fa fa-link');
        yield AssociationField::new('message', 'Mensaje PMS');
        yield AssociationField::new('config', 'Configuración Meta');

        // --- PANEL 3: AUDITORÍA TÉCNICA ---
        yield FormField::addPanel('Trazabilidad y Payloads')
            ->setIcon('fa fa-terminal')
            ->onlyOnDetail();

        yield IntegerField::new('retryCount', 'Intentos realizados');
        yield TextField::new('failedReason', 'Razón del Fallo');

        yield CodeEditorField::new('lastRequestRaw', 'Payload Enviado (JSON)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Respuesta API Meta')
            ->setLanguage('js')
            ->onlyOnDetail();

        // --- PANEL DE SISTEMA ---
        yield FormField::addPanel('Tiempos de Sistema')->setIcon('fa fa-clock')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}