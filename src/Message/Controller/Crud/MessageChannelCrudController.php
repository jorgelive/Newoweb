<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageTemplate;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class MessageChannelCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageChannel::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

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
            ->setEntityLabelInSingular('Canal de Envío')
            ->setEntityLabelInPlural('Canales de Envío')
            ->setSearchFields(['id', 'name'])
            ->setDefaultSort(['name' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración General')->setIcon('fa fa-plug')->collapsible();

        yield TextField::new('id', 'ID Técnico (Código)')
            ->setHelp('Ej: gupshup, beds24. No se puede cambiar una vez creado.')
            ->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW)
            ->setColumns(6);

        yield TextField::new('name', 'Nombre del Canal')
            ->setColumns(6);

        yield ChoiceField::new('templateColumn', 'Columna de Plantilla Mapeada')
            ->setChoices(array_combine(MessageTemplate::getTemplateFields(), MessageTemplate::getTemplateFields()))
            ->setHelp('Indica qué campo JSON de la entidad Template leerá este canal.')
            ->setColumns(6);

        yield BooleanField::new('isActive', '¿Canal Activo?')
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

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