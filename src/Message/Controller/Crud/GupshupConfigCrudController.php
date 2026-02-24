<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\GupshupConfig;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField; // 🔥 Importamos el editor de código
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class GupshupConfigCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return GupshupConfig::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuración Gupshup')
            ->setEntityLabelInPlural('Configuraciones Gupshup')
            ->setSearchFields(['nombre', 'baseUrl'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        // --- PANEL 1: IDENTIFICACIÓN ---
        yield FormField::addPanel('Identificación de la App')
            ->setIcon('fa fa-key')
            ->collapsible();

        yield TextField::new('nombre', 'Nombre de la Cuenta')
            ->setHelp('Ej: WhatsApp Producción Hotel')
            ->setColumns(8);

        yield BooleanField::new('activo', '¿En Producción?')
            ->renderAsSwitch(true)
            ->setColumns(4);

        // --- PANEL 2: CONEXIÓN Y CREDENCIALES ---
        yield FormField::addPanel('Conexión y Seguridad')
            ->setIcon('fa fa-plug')
            ->collapsible();

        yield TextField::new('baseUrl', 'API Base URL')
            ->setHelp('Por defecto: https://api.gupshup.io/wa/api/v1/msg')
            ->setColumns(12);

        // 🔥 Usamos CodeEditorField con lenguaje 'js' para manejar el JSON
        yield CodeEditorField::new('credentials', 'Credenciales (JSON)')
            ->setLanguage('js')
            ->setHelp('Estructura requerida: {"apiKey": "TU_KEY", "appId": "TU_ID"}')
            ->setColumns(12);

        // --- PANEL 3: AUDITORÍA ---
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }
}