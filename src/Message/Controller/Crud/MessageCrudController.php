<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Factory\MessageFactory;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly MessageFactory $messageFactory,
        private readonly EntityManagerInterface $entityManager,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    public function createEntity(string $entityFqcn)
    {
        $request = $this->requestStack->getCurrentRequest();
        $replyToId = $request->query->get('reply_to');

        if ($replyToId) {
            $incoming = $this->entityManager->getRepository(Message::class)->find($replyToId);
            if ($incoming) {
                return $this->messageFactory->createForUiReply($incoming);
            }
        }

        return $this->messageFactory->createForUiNew();
    }

    public function configureActions(Actions $actions): Actions
    {
        $replyAction = Action::new('reply', 'Responder', 'fa fa-reply')
            ->displayIf(fn(Message $m) => $m->getDirection() === Message::DIRECTION_INCOMING)
            ->linkToUrl(function (Message $entity) {
                return $this->adminUrlGenerator
                    ->setController(self::class)->setAction(Action::NEW)->set('reply_to', $entity->getId())->generateUrl();
            });

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $replyAction)->add(Crud::PAGE_DETAIL, $replyAction)
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
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
        $message = $this->getContext()?->getEntity()->getInstance();

        $allChannels = $this->entityManager->getRepository(MessageChannel::class)->findAll();
        $channelChoices = [];
        $activeChannelIds = [];

        foreach ($allChannels as $ch) {
            $idStr = (string) $ch->getId();
            $channelChoices[$ch->getName()] = $idStr;
            if ($ch->isActive()) {
                $activeChannelIds[] = $idStr; // Guardamos los activos para usarlos como default
            }
        }

        // 🔥 RECONSTRUCCIÓN DEL HISTORIAL (Para cuando se abre el CRUD suelto)
        if ($message instanceof Message && $message->getId() !== null) {
            $usedChannelIds = [];
            foreach ($allChannels as $ch) {
                $name = strtolower($ch->getName());
                if (str_contains($name, 'beds24') && !$message->getBeds24Queues()->isEmpty()) {
                    $usedChannelIds[] = (string) $ch->getId();
                }
                if (str_contains($name, 'whatsapp') && !$message->getWhatsappGupshupQueues()->isEmpty()) {
                    $usedChannelIds[] = (string) $ch->getId();
                }
            }
            $message->setTransientChannels($usedChannelIds);
        }

        yield IdField::new('id')->hideOnForm();
        yield FormField::addPanel('Nuevo Mensaje')->setIcon('fa fa-paper-plane');

        if ($this->isEmbedded()) {
            yield AssociationField::new('conversation')
                ->setFormTypeOption('row_attr', ['class' => 'd-none'])->setLabel(false);
        } else {
            yield AssociationField::new('conversation', 'Conversación / Huésped')
                ->setRequired(true)->hideOnIndex();
        }

        // 🔥 DOBLE CERROJO: Creamos el campo...
        $channelField = ChoiceField::new('transientChannels', 'Canales de Envío')
            ->setChoices($channelChoices)
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('Selecciona por dónde quieres enviar. Si usas una plantilla, esto será ignorado.')
            ->onlyOnForms()
            ->setFormTypeOption('disabled', $isEdit);

        // ... y si estamos en la pantalla "Añadir Nuevo" de forma independiente (no embebido),
        // obligamos a Symfony a marcar las cajas sí o sí pasando la propiedad 'data'.
        if ($pageName === Crud::PAGE_NEW && !$this->isEmbedded()) {
            $channelField->setFormTypeOption('data', $activeChannelIds);
        }

        yield $channelField;

        yield TextField::new('subjectLocal', 'Asunto (En tu idioma)')
            ->setRequired(false)->setColumns(12)->setFormTypeOption('disabled', $isEdit)->hideOnIndex();

        yield TextareaField::new('contentLocal', 'Texto del Mensaje (En tu idioma)')
            ->setColumns(12)->setFormTypeOption('disabled', $isEdit);

        yield FormField::addPanel('Mensaje Exacto (Idioma del Huésped)')->setIcon('fa fa-globe');
        yield TextField::new('subjectExternal', 'Asunto (Idioma del Huésped)')
            ->setRequired(false)->setColumns(12)->setFormTypeOption('disabled', $isEdit)->hideOnIndex();
        yield TextareaField::new('contentExternal', 'Texto Exacto (Idioma del Huésped)')
            ->setRequired(false)->setColumns(12)->setFormTypeOption('disabled', $isEdit)->hideOnIndex();

        yield FormField::addPanel('Opciones Avanzadas y Auditoría')->setIcon('fa fa-sliders-h');
        yield AssociationField::new('template', 'Usar Plantilla')
            ->setRequired(false)->setColumns(12)->setFormTypeOption('disabled', $isEdit);
        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormat('yyyy/MM/dd HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormat('yyyy/MM/dd HH:mm')->setFormTypeOption('disabled', true);
    }
}