<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Message;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
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

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
        return parent::configureActions($actions)
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
            // 🔥 ORDENADO POR ÚLTIMA INTERACCIÓN POR DEFECTO
            ->setDefaultSort(['lastMessageAt' => 'DESC', 'createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof MessageConversation) {
            $this->linkMessages($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof MessageConversation) {
            $this->linkMessages($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function linkMessages(MessageConversation $conversation): void
    {
        foreach ($conversation->getMessages() as $message) {
            if ($message->getConversation() === null) {
                $message->setConversation($conversation);
            }
        }
    }

    public function configureFields(string $pageName): iterable
    {
        $conversation = $this->getContext()?->getEntity()->getInstance();
        $channels = $this->entityManager->getRepository(MessageChannel::class)->findAll();

        // 🔥 RECONSTRUCCIÓN DE CANALES PARA UI
        if ($conversation instanceof MessageConversation) {
            foreach ($conversation->getMessages() as $msg) {
                /** @var Message $msg */
                if ($msg->getId() !== null) {
                    $usedChannelIds = [];
                    foreach ($channels as $ch) {
                        $name = strtolower($ch->getName());
                        if (str_contains($name, 'beds24') && !$msg->getBeds24SendQueues()->isEmpty()) {
                            $usedChannelIds[] = (string) $ch->getId();
                        }
                        if (str_contains($name, 'whatsapp') && !$msg->getWhatsappGupshupSendQueues()->isEmpty()) {
                            $usedChannelIds[] = (string) $ch->getId();
                        }
                    }
                    $msg->setTransientChannels($usedChannelIds);
                }
            }
        }

        // --- SECCIÓN 1: IDENTIFICACIÓN Y ESTADO ---
        yield FormField::addPanel('Estado y Metadatos')->setIcon('fa fa-info-circle');
        yield IdField::new('id', 'UUID')
            ->setMaxLength(40)
            ->onlyOnDetail()
            ->setColumns(4);
        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Abierto' => MessageConversation::STATUS_OPEN,
                'Cerrado' => MessageConversation::STATUS_CLOSED,
                'Archivado' => MessageConversation::STATUS_ARCHIVED
            ])
            ->renderAsBadges([
                MessageConversation::STATUS_OPEN => 'success',
                MessageConversation::STATUS_CLOSED => 'secondary',
                MessageConversation::STATUS_ARCHIVED => 'dark'
            ])->setColumns(4);

        yield IntegerField::new('unreadCount', 'No leídos')
            ->hideOnForm()
            ->setColumns(4)
            ->formatValue(fn ($value) => $value > 0 ? sprintf('<span class="badge badge-danger">%d</span>', $value) : '0');

        // --- SECCIÓN 2: DATOS DEL HUÉSPED ---
        yield FormField::addPanel('Huésped e Idioma')->setIcon('fa fa-user');

        yield TextField::new('guestName', 'Nombre Completo')->setColumns(4);
        yield TextField::new('guestPhone', 'Teléfono / WhatsApp')->setColumns(4);

        yield AssociationField::new('idioma', 'Idioma')
            ->setQueryBuilder(fn (QueryBuilder $qb) => $qb->andWhere('entity.prioridad > 0')->orderBy('entity.prioridad', 'DESC'))
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setColumns(2);

        yield BooleanField::new('idiomaFijado', 'Bloquear Idioma (Fijado)')
            ->setHelp('Si se activa, el PMS no sobreescribirá este idioma.')
            ->renderAsSwitch(true)
            ->setColumns(2);

        // --- SECCIÓN 3: CONTEXTO PMS (Lógica de Negocio) ---
        yield FormField::addPanel('Contexto de Reserva (PMS)')->setIcon('fa fa-link');
        yield ChoiceField::new('contextType', 'Tipo de Contexto')->setChoices(['Reserva PMS' => 'pms_reserva', 'Manual' => 'manual'])->setColumns(3);
        yield TextField::new('contextId', 'ID / Localizador')->setColumns(3);
        yield TextField::new('contextOrigin', 'Origen (OTA)')->hideOnForm()->setColumns(3);
        yield TextField::new('contextStatusTag', 'Etiqueta PMS')->hideOnForm()->setColumns(3);

        yield ArrayField::new('contextItems', 'Unidades / Casitas')->hideOnForm()->setColumns(4);
        yield NumberField::new('contextFinancialTotal', 'Monto Total Reserva')->hideOnForm()->setColumns(4)->setNumDecimals(2);
        yield BooleanField::new('contextFinancialIsCleared', '¿Pagado?')->hideOnForm()->setColumns(4)->renderAsSwitch(false);

        // --- SECCIÓN 4: CRONOLOGÍA Y SESIONES DE CANAL ---
        yield FormField::addPanel('Cronología y Sesiones de Canal')->setIcon('fa fa-clock')->renderCollapsed();
        yield DateTimeField::new('createdAt', 'Fecha de Creación')->hideOnForm()->setColumns(3);
        yield DateTimeField::new('lastMessageAt', 'Último Mensaje (General)')->hideOnForm()->setColumns(3);
        yield DateTimeField::new('lastInboundAt', 'Última Respuesta Huésped')->hideOnForm()->setColumns(3);

        // Control de Sesión Meta (Visual y Práctico)
        yield DateTimeField::new('whatsappSessionValidUntil', 'Vencimiento Ventana Meta (24h)')->hideOnForm()->setColumns(3);
        yield BooleanField::new('isWhatsappSessionActive', '¿Chat Meta Activo?')
            ->hideOnForm()
            ->setHelp('Si está en verde, puedes enviar mensajes libres por WhatsApp.')
            ->renderAsSwitch(false) // Lo forzamos a ser de solo lectura visual
            ->setColumns(12);

        // --- SECCIÓN 5: CHAT INTERACTIVO ---
        if (!$this->isEmbedded()) {
            yield FormField::addPanel('Historial de Mensajes')->setIcon('fa fa-history');

            $prototype = clone $this->messageFactory->createForUiNew(
                $conversation instanceof MessageConversation ? $conversation : null
            );

            yield CollectionField::new('messages', 'Mensajes del Chat')
                ->useEntryCrudForm(MessageCrudController::class)
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('prototype_data', $prototype)
                ->allowDelete(false)
                ->addCssClass('field-collection-inline');
        }
    }
}