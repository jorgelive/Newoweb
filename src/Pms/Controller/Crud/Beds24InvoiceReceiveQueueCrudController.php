<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\Beds24InvoiceReceiveQueue;
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

/**
 * Cola de pull en-demanda de información financiera (invoiceItems). De sólo lectura:
 * la alimenta el cron `beds24_invoice_receive`. Espejo de la cola de mensajes.
 */
class Beds24InvoiceReceiveQueueCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return Beds24InvoiceReceiveQueue::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::ADMIN)
            ->setPermission(Action::DETAIL, Roles::ADMIN)
            ->setPermission(Action::DELETE, Roles::ADMIN);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola Facturas (Beds24)')
            ->setEntityLabelInPlural('Colas Facturas (Beds24)')
            ->setSearchFields(['targetBookId', 'status', 'failedReason'])
            ->setDefaultSort(['runAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setMaxLength(40)->onlyOnDetail();

        yield FormField::addPanel('Estado de Descarga (Pull)')->setIcon('fa fa-download');

        yield TextField::new('targetBookId', 'ID Reserva Destino (Beds24)')->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente'  => Beds24InvoiceReceiveQueue::STATUS_PENDING,
                'Procesando' => Beds24InvoiceReceiveQueue::STATUS_PROCESSING,
                'Completado' => Beds24InvoiceReceiveQueue::STATUS_SUCCESS,
                'Fallido'    => Beds24InvoiceReceiveQueue::STATUS_FAILED,
            ])
            ->renderAsBadges([
                Beds24InvoiceReceiveQueue::STATUS_PENDING    => 'warning',
                Beds24InvoiceReceiveQueue::STATUS_PROCESSING => 'info',
                Beds24InvoiceReceiveQueue::STATUS_SUCCESS    => 'success',
                Beds24InvoiceReceiveQueue::STATUS_FAILED     => 'danger',
            ])
            ->setColumns(6);

        yield DateTimeField::new('runAt', 'Programado para')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setColumns(6);

        yield IntegerField::new('retryCount', 'Reintentos')->setColumns(3);
        yield IntegerField::new('lastHttpCode', 'HTTP Code')->setColumns(3);

        yield FormField::addPanel('Relaciones')->setIcon('fa fa-link');
        yield AssociationField::new('config', 'Configuración PMS');
        yield AssociationField::new('endpoint', 'Endpoint Utilizado');

        yield FormField::addPanel('Auditoría Técnica (JSON/Raw)')
            ->setIcon('fa fa-code')
            ->renderCollapsed();

        yield TextField::new('failedReason', 'Razón del Fallo')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resultado Ejecución (JSON)')
            ->setLanguage('js')
            ->formatValue(fn ($value) => $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Último Request (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Última Respuesta (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();
    }
}
