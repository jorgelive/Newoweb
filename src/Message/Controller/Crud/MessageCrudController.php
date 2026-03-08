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
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
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

    public function createEntity(string $entityFqcn)
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
            ->setEntityLabelInSingular('Mensaje')
            ->setEntityLabelInPlural('Historial de Mensajes')
            ->setSearchFields(['contentLocal', 'contentExternal', 'subjectLocal', 'subjectExternal'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = $pageName === Crud::PAGE_EDIT;

        // 1. OBTENER LA CONVERSACIÓN ACTUAL (Robustecido)
        $eaContext = $this->getContext();
        $rootEntity = $eaContext?->getEntity()->getInstance();
        $request = $this->requestStack->getCurrentRequest();

        $conversation = null;

        if ($rootEntity instanceof MessageConversation) {
            $conversation = $rootEntity; // Embebido en la colección
        } elseif ($rootEntity instanceof Message && $rootEntity->getConversation() !== null) {
            $conversation = $rootEntity->getConversation(); // Editando un mensaje existente
        } elseif ($replyToId = $request->query->get('reply_to')) {
            $conversation = $this->em->getRepository(Message::class)->find($replyToId)?->getConversation(); // Modo Responder
        }

        // 2. CALCULAR PLANTILLAS VÁLIDAS
        $validTemplateIds = []; // 🔴 POR DEFECTO ESTÁ VACÍO (BLOQUEO DE SEGURIDAD)

        if ($conversation !== null) {
            $validTemplateIds = $this->getValidTemplateIds($conversation);
        }

        yield IdField::new('id')
            ->setMaxLength(40)
            ->hideOnForm();

        // ---------------------------------------------------------------------
        // PANEL: NUEVO MENSAJE
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Nuevo Mensaje')->setIcon('fa fa-paper-plane');

        if (!method_exists($this, 'isEmbedded') || !$this->isEmbedded()) {
            yield AssociationField::new('conversation', 'Conversación / Huésped')
                ->setRequired(true)
                ->hideOnIndex();
        }

        $channels = $this->em->getRepository(MessageChannel::class)->findAll();
        $channelChoices = [];
        foreach ($channels as $ch) {
            $channelChoices[$ch->getName()] = (string) $ch->getId();
        }

        yield ChoiceField::new('transientChannels', 'Canales de Envío')
            ->setChoices($channelChoices)
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('Si usas una plantilla, esta selección manual será ignorada.')
            ->onlyOnForms()
            ->setFormTypeOption('disabled', $isEdit);

        yield TextField::new('subjectLocal', 'Asunto (En tu idioma)')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex();

        yield TextareaField::new('contentLocal', 'Texto del Mensaje (En tu idioma)')
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit);

        // ---------------------------------------------------------------------
        // PANEL: MODO OVERRIDE
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Mensaje Exacto (Idioma del Huésped)')->setIcon('fa fa-globe');

        yield TextField::new('subjectExternal', 'Asunto (Idioma del Huésped)')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex();

        yield TextareaField::new('contentExternal', 'Texto Exacto (Idioma del Huésped)')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex();

        // ---------------------------------------------------------------------
        // PANEL: OPCIONES AVANZADAS Y PLANTILLA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Opciones Avanzadas y Auditoría')->setIcon('fa fa-sliders-h');

        // 🔥 3. APLICAR EL FILTRO DE PLANTILLAS ESTRICTO
        yield AssociationField::new('template', 'Usar Plantilla')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->setHelp($conversation
                ? 'Solo se muestran las plantillas permitidas para el canal de esta reserva.'
                : '⚠️ <b>Falta Contexto:</b> Guarda el mensaje sin plantilla primero, o créalo desde la vista de "Conversación" para habilitar las plantillas.')
            ->setQueryBuilder(function (QueryBuilder $qb) use ($validTemplateIds) {
                // 🔥 SIEMPRE aplicamos el filtro. Si la lista está vacía (por falta de contexto o rechazo), bloqueamos.
                if (empty($validTemplateIds)) {
                    $qb->andWhere('1 = 0');
                } else {
                    $qb->andWhere('entity.id IN (:ids)')->setParameter('ids', $validTemplateIds);
                }
                return $qb->orderBy('entity.name', 'ASC');
            });

        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormTypeOption('disabled', true);
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

        $source = $meta['source'] ?? 'manual';
        $agency = (string)($meta['agency_id'] ?? '');

        $validIds = [];

        foreach ($templates as $t) {
            // 1. Filtrar Módulo
            if ($t->getContextType() !== null && $t->getContextType() !== $contextType) { continue; }

            // 2. Filtrar Fuente / OTA
            $allowedSources = $t->getAllowedSources();
            if (!empty($allowedSources) && !in_array($source, $allowedSources, true)) { continue; }

            // 3. Filtrar Agencia B2B
            $allowedAgencies = $t->getAllowedAgencies();
            if (!empty($allowedAgencies) && !in_array($agency, $allowedAgencies, true)) { continue; }

            // 🔥 Extraemos como string estándar RFC4122 para evitar errores de hidratación de Doctrine en el IN (:ids)
            $validIds[] = $t->getId()->toRfc4122();
        }

        return $validIds;
    }
}