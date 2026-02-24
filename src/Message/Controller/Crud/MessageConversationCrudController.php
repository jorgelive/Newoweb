<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageConversation;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class MessageConversationCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageConversation::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Conversación')
            ->setEntityLabelInPlural('Conversaciones')
            ->setSearchFields(['id', 'guestName', 'guestPhone', 'contextId'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID Conversación')->hideOnForm();

        yield FormField::addPanel('Estado')->setIcon('fa fa-comments');

        yield ChoiceField::new('status', 'Estado de la Conversación')
            ->setChoices([
                'Abierto' => MessageConversation::STATUS_OPEN,
                'Cerrado' => MessageConversation::STATUS_CLOSED,
                'Archivado' => MessageConversation::STATUS_ARCHIVED,
            ])
            ->renderAsBadges([
                MessageConversation::STATUS_OPEN => 'success',
                MessageConversation::STATUS_CLOSED => 'secondary',
                MessageConversation::STATUS_ARCHIVED => 'dark',
            ])
            ->setColumns(12);

        yield FormField::addPanel('Enlace Lógico (Desacoplado)')->setIcon('fa fa-link');

        yield ChoiceField::new('contextType', 'Módulo Origen')
            ->setChoices([
                'Reserva PMS' => 'pms_reserva',
                'Registro Manual / Walk-in' => 'manual',
            ])
            ->setColumns(6);

        yield TextField::new('contextId', 'ID del Registro (UUID / Ref)')
            ->setHelp('Introduce el identificador exacto de la entidad vinculada.')
            ->setColumns(6);

        yield FormField::addPanel('Datos del Contacto y Preferencias')->setIcon('fa fa-user');

        yield TextField::new('guestName', 'Nombre del Contacto')
            ->setHelp('Se autocompleta con el nombre del huésped.')
            ->setColumns(4);

        yield TextField::new('guestPhone', 'Teléfono (WhatsApp/SMS)')
            ->setHelp('Se autocompleta. Si lo modificas manualmente, este valor tendrá prioridad.')
            ->setColumns(4);

        // 🔥 RELACIÓN FÍSICA AL MAESTRO DE IDIOMAS
        yield AssociationField::new('idioma', 'Idioma de la Conversación')
            ->setHelp('Idioma vivo del chat. Nace con el PMS pero puede cambiar. Se usa para las traducciones.')
            ->setColumns(4)
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setQueryBuilder(function (QueryBuilder $queryBuilder) {
                // Filtramos usando la entidad MaestroIdioma
                return $queryBuilder
                    ->andWhere('entity.prioridad > 0')
                    ->orderBy('entity.prioridad', 'DESC')
                    ->addOrderBy('entity.nombre', 'ASC');
            });

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->hideOnForm();

        // Ocultamos el historial de mensajes si este formulario se está abriendo dentro de otro (isEmbedded)
        if (!method_exists($this, 'isEmbedded') || !$this->isEmbedded()) {
            yield FormField::addPanel('Historial de Chat')->setIcon('fa fa-history');

            yield CollectionField::new('messages', 'Mensajes')
                ->useEntryCrudForm(MessageCrudController::class);
        }
    }
}