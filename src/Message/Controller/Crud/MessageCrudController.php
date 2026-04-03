<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\MessageDataResolverRegistry;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class MessageCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly MessageDataResolverRegistry $resolverRegistry
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    public function createEntity(string $entityFqcn): Message
    {
        $message = new Message();
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);

        $request = $this->requestStack->getCurrentRequest();
        $replyToId = $request->query->get('reply_to');

        if ($replyToId) {
            $incoming = $this->em->getRepository(Message::class)->find($replyToId);
            if ($incoming) {
                $message->setConversation($incoming->getConversation());
                if ($incoming->getChannel()) {
                    $message->setTransientChannels([(string) $incoming->getChannel()->getId()]);
                }
            }
        }

        return $message;
    }

    public function configureActions(Actions $actions): Actions
    {
        $replyAction = Action::new('reply', 'Responder', 'fa fa-reply')
            ->displayIf(fn(Message $m) => $m->getDirection() === Message::DIRECTION_INCOMING)
            ->linkToUrl(function (Message $entity) {
                return $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction(Action::NEW)
                    ->set('reply_to', $entity->getId())
                    ->generateUrl();
            });

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $replyAction)
            ->add(Crud::PAGE_DETAIL, $replyAction)
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mensaje (Auditoría)')
            ->setEntityLabelInPlural('Auditoría de Mensajes')
            ->setSearchFields(['contentLocal', 'contentExternal', 'subjectLocal', 'subjectExternal', 'id'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    // =====================================================================
    // 🔥 NUEVO: FILTROS PARA AUDITORÍA
    // =====================================================================
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Estado')->setChoices([
                'Pendiente' => Message::STATUS_PENDING,
                'En Cola (Queued)' => Message::STATUS_QUEUED,
                'Enviado/Delivered' => Message::STATUS_SENT,
                'Fallido' => Message::STATUS_FAILED,
                'Recibido' => Message::STATUS_RECEIVED,
                'Leído' => Message::STATUS_READ,
                'Cancelado' => Message::STATUS_CANCELLED,
            ]))
            ->add(ChoiceFilter::new('direction', 'Dirección')->setChoices([
                'Saliente (Tú -> Huésped)' => Message::DIRECTION_OUTGOING,
                'Entrante (Huésped -> Tú)' => Message::DIRECTION_INCOMING,
            ]))
            ->add(ChoiceFilter::new('senderType', 'Remitente')->setChoices([
                'Host (Manual)' => Message::SENDER_HOST,
                'Huésped' => Message::SENDER_GUEST,
                'Sistema Automático' => Message::SENDER_SYSTEM,
                'Nota Interna' => Message::SENDER_INTERNAL,
            ]))
            ->add(DateTimeFilter::new('createdAt', 'Fecha de Creación'))
            ->add(DateTimeFilter::new('scheduledAt', 'Fecha Programada'));
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = $pageName === Crud::PAGE_EDIT;
        $request = $this->requestStack->getCurrentRequest();

        // 1. OBTENER LA CONVERSACIÓN ACTUAL
        $conversation = null;
        $instance = $this->getContext()?->getEntity()->getInstance();

        if ($instance instanceof MessageConversation) {
            $conversation = $instance;
        } elseif ($instance instanceof Message && $instance->getConversation() !== null) {
            $conversation = $instance->getConversation();
        }

        if ($conversation === null && $request !== null) {
            $crudController = $request->query->get('crudControllerFqcn') ?? $request->attributes->get('crudControllerFqcn');
            $entityId = $request->query->get('entityId') ?? $request->attributes->get('entityId');

            if ($crudController === MessageConversationCrudController::class && $entityId) {
                $conversation = $this->em->getRepository(MessageConversation::class)->find($entityId);
            }

            if ($conversation === null && $request->query->has('reply_to')) {
                $replyToId = $request->query->get('reply_to');
                $conversation = $this->em->getRepository(Message::class)->find($replyToId)?->getConversation();
            }
        }

        $validTemplateIds = [];
        if ($conversation !== null) {
            $validTemplateIds = $this->getValidTemplateIds($conversation);
        }

        // =====================================================================
        // PANEL 1: IDENTIFICACIÓN Y ESTADO GENERAL
        // =====================================================================
        yield FormField::addPanel('Estado y Metadatos')->setIcon('fa fa-info-circle');

        yield IdField::new('id', 'UUID')->setMaxLength(40)->onlyOnDetail();

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Pendiente' => Message::STATUS_PENDING,
                'En Cola' => Message::STATUS_QUEUED,
                'Enviado' => Message::STATUS_SENT,
                'Fallido' => Message::STATUS_FAILED,
                'Recibido' => Message::STATUS_RECEIVED,
                'Leído' => Message::STATUS_READ,
                'Cancelado' => Message::STATUS_CANCELLED,
            ])
            ->renderAsBadges([
                Message::STATUS_PENDING => 'warning',
                Message::STATUS_QUEUED => 'info',
                Message::STATUS_SENT => 'success',
                Message::STATUS_FAILED => 'danger',
                Message::STATUS_RECEIVED => 'primary',
                Message::STATUS_READ => 'success',
                Message::STATUS_CANCELLED => 'dark',
            ])
            ->setFormTypeOption('disabled', true)
            ->hideWhenCreating()
            ->setColumns(3);

        yield ChoiceField::new('direction', 'Dirección')
            ->setChoices([
                'Saliente' => Message::DIRECTION_OUTGOING,
                'Entrante' => Message::DIRECTION_INCOMING,
            ])
            ->renderAsBadges([
                Message::DIRECTION_OUTGOING => 'primary',
                Message::DIRECTION_INCOMING => 'secondary',
            ])
            ->setFormTypeOption('disabled', true)
            ->hideWhenCreating()
            ->setColumns(3);

        yield ChoiceField::new('senderType', 'Remitente')
            ->setChoices([
                'Host' => Message::SENDER_HOST,
                'Huésped' => Message::SENDER_GUEST,
                'Sistema' => Message::SENDER_SYSTEM,
                'Nota' => Message::SENDER_INTERNAL,
            ])
            ->setFormTypeOption('disabled', true)
            ->hideWhenCreating()
            ->setColumns(3);

        if (!method_exists($this, 'isEmbedded') || !$this->isEmbedded()) {
            yield AssociationField::new('conversation', 'Conversación')
                ->setRequired(true)
                ->setFormTypeOption('disabled', $isEdit)
                ->setColumns(3);
        }

        // =====================================================================
        // PANEL 2: REDACCIÓN Y CONTENIDO
        // =====================================================================
        yield FormField::addPanel('Contenido del Mensaje')->setIcon('fa fa-paper-plane');

        $channels = $this->em->getRepository(MessageChannel::class)->findAll();
        $channelChoices = [];
        foreach ($channels as $ch) {
            $channelChoices[$ch->getName()] = (string) $ch->getId();
        }

        yield ChoiceField::new('transientChannels', 'Canales (Forzados)')
            ->setChoices($channelChoices)
            ->allowMultipleChoices()
            ->renderExpanded()
            ->onlyOnForms()
            ->setFormTypeOption('disabled', $isEdit);

        yield TextareaField::new('contentLocal', 'Texto (Local)')
            ->setColumns(6)
            ->setFormTypeOption('disabled', $isEdit);

        yield TextareaField::new('contentExternal', 'Texto (Huésped)')
            ->setColumns(6)
            ->setFormTypeOption('disabled', $isEdit);

        // =====================================================================
        // PANEL 3: LÍNEA DE TIEMPO (AHORA VISIBLE EN INDEX)
        // =====================================================================
        yield FormField::addPanel('Línea de Tiempo')->setIcon('fa fa-clock');

        yield DateTimeField::new('createdAt', 'Creado')->setFormat('yyyy-MM-dd HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('scheduledAt', 'Programado (RunAt)')->setFormat('yyyy-MM-dd HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Últ. Actualización')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex()->setFormTypeOption('disabled', true);

        // =====================================================================
        // PANEL 4: AUDITORÍA AVANZADA (COLAS Y METADATA PRETTY JSON)
        // =====================================================================
        yield FormField::addPanel('Auditoría Avanzada (Workers & JSON)')->setIcon('fa fa-bug');

        yield CollectionField::new('beds24SendQueues', 'Colas Beds24')
            ->hideOnIndex()
            ->hideWhenCreating()
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        yield CollectionField::new('whatsappMetaSendQueues', 'Colas WhatsApp')
            ->hideOnIndex()
            ->hideWhenCreating()
            ->setFormTypeOption('disabled', true)
            ->setColumns(6);

        // 🔥 Magia: Formatear la metadata a Pretty JSON de solo lectura
        yield CodeEditorField::new('metadata', 'Metadata Debug (JSON)')
            ->hideOnIndex()
            ->hideWhenCreating()
            ->setLanguage('javascript') // Para el syntax highlighting en EasyAdmin
            ->setColumns(12)
            ->setFormTypeOption('disabled', true)
            ->formatValue(function ($value) {
                // Formateamos el array interno de Doctrine a JSON legible
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
            });

        // =====================================================================
        // PANEL 5: PLANTILLA
        // =====================================================================
        yield FormField::addPanel('Plantilla')->setIcon('fa fa-file-alt')->renderCollapsed();

        yield AssociationField::new('template', 'Plantilla Origen')
            ->autocomplete(false)
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->setQueryBuilder(function (QueryBuilder $qb) use ($validTemplateIds, $conversation) {
                if ($conversation === null || empty($validTemplateIds)) {
                    $qb->andWhere('1 = 0');
                } else {
                    $binaryIds = array_map(function ($uid) {
                        return $uid->toBinary();
                    }, $validTemplateIds);
                    $qb->andWhere('entity.id IN (:ids)')->setParameter('ids', $binaryIds);
                }
                return $qb->orderBy('entity.name', 'ASC');
            });
    }

    /**
     * Evalúa las plantillas contra los datos en vivo.
     */
    private function getValidTemplateIds(MessageConversation $conversation): array
    {
        $templates = $this->em->getRepository(MessageTemplate::class)->findAll();
        $contextType = $conversation->getContextType();

        $resolver = $this->resolverRegistry->getResolver($contextType);
        $meta = $resolver ? $resolver->getMetadata($conversation->getContextId()) : [];

        $source = strtolower(trim((string)($meta['source'] ?? 'manual')));
        $agency = (string)($meta['agency_id'] ?? '');

        $validIds = [];

        foreach ($templates as $t) {
            if (!empty($t->getContextType()) && $t->getContextType() !== $contextType) {
                continue;
            }

            $allowedSources = $t->getAllowedSources();
            if (!empty($allowedSources)) {
                $allowedSources = array_map('strtolower', $allowedSources);
                if (!in_array($source, $allowedSources, true)) {
                    continue;
                }
            }

            $allowedAgencies = $t->getAllowedAgencies();
            if (!empty($allowedAgencies) && !in_array($agency, $allowedAgencies, true)) {
                continue;
            }

            $validIds[] = $t->getId();
        }

        return $validIds;
    }
}