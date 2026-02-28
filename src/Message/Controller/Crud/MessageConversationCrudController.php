<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageFactory;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly MessageFactory $messageFactory,
        private readonly EntityManagerInterface $entityManager,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageConversation::class;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof MessageConversation) {
            foreach ($entityInstance->getMessages() as $message) {
                if ($message->getConversation() === null) {
                    $message->setConversation($entityInstance);
                }
            }
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof MessageConversation) {
            foreach ($entityInstance->getMessages() as $message) {
                if ($message->getConversation() === null) {
                    $message->setConversation($entityInstance);
                }
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
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
        $conversation = $this->getContext()?->getEntity()->getInstance();
        $channels = $this->entityManager->getRepository(MessageChannel::class)->findAll();

        // 🔥 RECONSTRUCCIÓN DEL HISTORIAL: Llenamos las colas guardadas.
        if ($conversation instanceof MessageConversation) {
            foreach ($conversation->getMessages() as $msg) {
                if ($msg->getId() !== null) {
                    $usedChannelIds = [];
                    foreach ($channels as $ch) {
                        $name = strtolower($ch->getName());
                        // NOTA: Asume que el nombre del canal contiene "beds24" o "whatsapp"
                        if (str_contains($name, 'beds24') && !$msg->getBeds24Queues()->isEmpty()) {
                            $usedChannelIds[] = (string) $ch->getId();
                        }
                        if (str_contains($name, 'whatsapp') && !$msg->getWhatsappGupshupQueues()->isEmpty()) {
                            $usedChannelIds[] = (string) $ch->getId();
                        }
                    }
                    $msg->setTransientChannels($usedChannelIds);
                }
            }
        }

        yield IdField::new('id', 'ID Conversación')->hideOnForm();

        yield FormField::addPanel('Estado')->setIcon('fa fa-comments');
        yield ChoiceField::new('status', 'Estado de la Conversación')
            ->setChoices(['Abierto' => MessageConversation::STATUS_OPEN, 'Cerrado' => MessageConversation::STATUS_CLOSED, 'Archivado' => MessageConversation::STATUS_ARCHIVED])
            ->renderAsBadges([MessageConversation::STATUS_OPEN => 'success', MessageConversation::STATUS_CLOSED => 'secondary', MessageConversation::STATUS_ARCHIVED => 'dark'])
            ->setColumns(12);

        yield FormField::addPanel('Enlace Lógico (Desacoplado)')->setIcon('fa fa-link');
        yield ChoiceField::new('contextType', 'Módulo Origen')->setChoices(['Reserva PMS' => 'pms_reserva', 'Registro Manual / Walk-in' => 'manual'])->setColumns(6);
        yield TextField::new('contextId', 'ID del Registro (UUID / Ref)')->setColumns(6);

        yield FormField::addPanel('Datos del Contacto')->setIcon('fa fa-user');
        yield TextField::new('guestName', 'Nombre del Contacto')->setColumns(4);
        yield TextField::new('guestPhone', 'Teléfono')->setColumns(4);
        yield AssociationField::new('idioma', 'Idioma')
            ->setColumns(4)->setRequired(true)->setFormTypeOption('attr', ['required' => true])
            ->setQueryBuilder(fn (QueryBuilder $qb) => $qb->andWhere('entity.prioridad > 0')->orderBy('entity.prioridad', 'DESC'));

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();
        yield DateTimeField::new('createdAt', 'Creado')->setFormat('yyyy/MM/dd HH:mm')->hideOnForm();

        if (!$this->isEmbedded()) {
            yield FormField::addPanel('Historial de Chat')->setIcon('fa fa-history');

            // 🔥 INYECCIÓN DEL PROTOTIPO: Esto obliga a EasyAdmin a marcar los
            // checkboxes cuando el usuario hace clic en "Añadir Mensaje"
            $prototype = clone $this->messageFactory->createForUiNew();

            yield CollectionField::new('messages', 'Mensajes')
                ->useEntryCrudForm(MessageCrudController::class)
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('prototype_data', $prototype); // <-- Clave para el embebido
        }
    }
}