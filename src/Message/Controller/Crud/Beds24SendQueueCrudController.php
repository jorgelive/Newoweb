<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Beds24SendQueue;
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

class Beds24SendQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Beds24SendQueue::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // En la cola de salida no se permite crear ni editar manualmente
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola Beds24')
            ->setEntityLabelInPlural('Colas Beds24')
            ->setSearchFields(['targetBookId', 'status', 'failedReason'])
            ->setDefaultSort(['runAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        // --- PANEL 1: ESTADO DEL ENVÍO ---
        yield FormField::addPanel('Estado del Envío')->setIcon('fa fa-exchange-alt');

        yield TextField::new('targetBookId', 'ID Reserva (Beds24)')
            ->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente' => Beds24SendQueue::STATUS_PENDING,
                'Procesando' => Beds24SendQueue::STATUS_PROCESSING,
                'Completado' => Beds24SendQueue::STATUS_SUCCESS,
                'Fallido' => Beds24SendQueue::STATUS_FAILED,
                'Cancelado' => Beds24SendQueue::STATUS_CANCELLED,
            ])
            ->renderAsBadges([
                Beds24SendQueue::STATUS_PENDING => 'warning',
                Beds24SendQueue::STATUS_PROCESSING => 'info',
                Beds24SendQueue::STATUS_SUCCESS => 'success',
                Beds24SendQueue::STATUS_FAILED => 'danger',
                Beds24SendQueue::STATUS_CANCELLED => 'dark',
            ])
            ->setColumns(6);

        yield DateTimeField::new('runAt', 'Programado para')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setColumns(6);

        yield IntegerField::new('retryCount', 'Reintentos')
            ->setColumns(3);

        yield IntegerField::new('lastHttpCode', 'HTTP Code')
            ->setColumns(3);

        // --- PANEL 2: REFERENCIAS ---
        yield FormField::addPanel('Relaciones')->setIcon('fa fa-link');
        yield AssociationField::new('message', 'Mensaje Original');
        yield AssociationField::new('config', 'Configuración PMS');
        yield AssociationField::new('endpoint', 'Endpoint Utilizado');

        // --- PANEL 3: TRAZA TÉCNICA ---
        yield FormField::addPanel('Auditoría Técnica (JSON/Raw)')
            ->setIcon('fa fa-code')
            ->renderCollapsed();

        yield TextField::new('failedReason', 'Razón del Fallo')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resultado Ejecución (JSON)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Último Request (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Última Respuesta (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();
    }
}