<?php


declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Beds24ReceiveQueue;
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

class Beds24ReceiveQueueCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack      $requestStack
    )
    {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Beds24ReceiveQueue::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // En la cola de entrada (Pull) no se permite crear ni editar manualmente
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
            ->setEntityLabelInSingular('Cola Entrada (Beds24)')
            ->setEntityLabelInPlural('Colas Entrada (Beds24)')
            ->setSearchFields(['targetBookId', 'status', 'failedReason'])
            ->setDefaultSort(['runAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

        // --- PANEL 1: ESTADO DE LA DESCARGA ---
        yield FormField::addPanel('Estado de Descarga (Pull)')->setIcon('fa fa-download');

        yield TextField::new('targetBookId', 'ID Reserva Destino (Beds24)')
            ->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente' => Beds24ReceiveQueue::STATUS_PENDING,
                'Procesando' => Beds24ReceiveQueue::STATUS_PROCESSING,
                'Completado' => Beds24ReceiveQueue::STATUS_SUCCESS,
                'Fallido' => Beds24ReceiveQueue::STATUS_FAILED,
            ])
            ->renderAsBadges([
                Beds24ReceiveQueue::STATUS_PENDING => 'warning',
                Beds24ReceiveQueue::STATUS_PROCESSING => 'info',
                Beds24ReceiveQueue::STATUS_SUCCESS => 'success',
                Beds24ReceiveQueue::STATUS_FAILED => 'danger',
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
        yield AssociationField::new('config', 'Configuración PMS');
        yield AssociationField::new('endpoint', 'Endpoint Utilizado');

        // --- PANEL 3: TRAZA TÉCNICA ---
        yield FormField::addPanel('Auditoría Técnica (JSON/Raw)')
            ->setIcon('fa fa-code')
            ->renderCollapsed();

        yield TextField::new('failedReason', 'Razón del Fallo')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resultado Ejecución (JSON)')
            ->setLanguage('js')
            ->formatValue(function ($value) {
                // Convertimos el array a string JSON formateado para que el editor lo pueda leer
                return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            })
            ->onlyOnDetail();

        yield CodeEditorField::new('lastRequestRaw', 'Último Request (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Última Respuesta (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();
    }
}