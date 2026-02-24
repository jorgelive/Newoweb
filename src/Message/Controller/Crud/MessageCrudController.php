<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
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
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    /**
     * 🔥 MAGIA EA: Pre-completar datos si venimos del botón "Responder"
     */
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
            // 🔥 Actualizado con la nueva semántica
            ->setSearchFields(['contentLocal', 'contentExternal', 'subjectLocal', 'subjectExternal'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = $pageName === Crud::PAGE_EDIT;

        yield IdField::new('id')->hideOnForm();

        // ---------------------------------------------------------------------
        // PANEL: MENSAJE LOCAL (El idioma del recepcionista)
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
            ->setHelp('Selecciona por dónde quieres enviar este mensaje. Si usas una plantilla, esta selección será ignorada.')
            ->onlyOnForms()
            ->setFormTypeOption('disabled', $isEdit);

        yield TextField::new('subjectLocal', 'Asunto (En tu idioma)')
            ->setRequired(false)
            ->setHelp('Opcional. Se usará como Asunto para Emails o se concatenará en negrita al inicio en WhatsApp.')
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex();

        yield TextareaField::new('contentLocal', 'Texto del Mensaje (En tu idioma)')
            ->setHelp('Escribe tu respuesta aquí. El sistema la traducirá automáticamente al idioma del huésped si es necesario.')
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit);

        // ---------------------------------------------------------------------
        // PANEL: MENSAJE EXTERNO (Idioma del Huésped / Override / Entrada)
        // Este es el bloque que seguramente envuelves o controlas con Stimulus
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Mensaje Exacto (Idioma del Huésped)')
            ->setIcon('fa fa-globe')
            ->setHelp('Override manual o texto original recibido por el huésped.');

        yield TextField::new('subjectExternal', 'Asunto (Idioma del Huésped)')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex(); // Oculto en el listado para no saturar

        yield TextareaField::new('contentExternal', 'Texto Exacto (Idioma del Huésped)')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->hideOnIndex();

        // ---------------------------------------------------------------------
        // PANEL: OPCIONES AVANZADAS Y AUDITORÍA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Opciones Avanzadas y Auditoría')
            ->setIcon('fa fa-sliders-h');

        yield AssociationField::new('template', 'Usar Plantilla')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormTypeOption('disabled', $isEdit)
            ->setHelp('Opcional. Si seleccionas una plantilla, el texto manual será ignorado.');

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}