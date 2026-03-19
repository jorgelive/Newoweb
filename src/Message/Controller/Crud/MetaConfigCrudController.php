<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Exchange\Entity\MetaConfig;
use App\Message\Form\Type\MetaCredentialsType;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controlador CRUD para la configuración de la integración con WhatsApp Meta.
 * Gestiona credenciales, estados de activación y configuración de la API.
 */
class MetaConfigCrudController extends BaseCrudController
{
    /**
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs de EasyAdmin.
     * @param RequestStack $requestStack Pila de peticiones HTTP.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    /**
     * Retorna la clase de la entidad gestionada por este controlador.
     */
    public static function getEntityFqcn(): string
    {
        return MetaConfig::class;
    }

    /**
     * Configura las acciones disponibles y sus permisos asociados.
     *
     * @param Actions $actions Objeto de configuración de acciones de EasyAdmin.
     * @return Actions
     */
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

    /**
     * Configura los parámetros generales del CRUD (títulos, búsqueda, orden).
     *
     * @param Crud $crud Objeto de configuración general de EasyAdmin.
     * @return Crud
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuración Meta')
            ->setEntityLabelInPlural('Configuraciones Meta')
            ->setSearchFields(['nombre', 'baseUrl'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Configura los campos que se mostrarán en los distintos contextos (Index, Detail, Edit, New).
     *
     * @param string $pageName Nombre de la página actual generada por EasyAdmin.
     * @return iterable
     */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

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
            ->setHelp('Por defecto: https://graph.facebook.com')
            ->setColumns(12);

        // 🔥 MANEJO DINÁMICO DEL CAMPO JSON 'credentials'
        if (Crud::PAGE_EDIT === $pageName || Crud::PAGE_NEW === $pageName) {
            // En Formulario: Usamos nuestro FormType personalizado con inputs individuales.
            // Se eliminó addContent() por incompatibilidad de versión y se movió el texto a setHelp() en el Fieldset.
            yield FormField::addFieldset('Credenciales Técnicas')
                ->setHelp('Estos datos son sensibles y se guardan internamente en formato seguro (JSON).');

            yield ArrayField::new('credentials', 'Configuración de Credenciales')
                ->setFormType(MetaCredentialsType::class)
                ->setColumns(12);
        } else {
            // En Index y Detail: Mostramos el JSON formateado para lectura
            yield CodeEditorField::new('credentials', 'Credenciales Guardadas (JSON)')
                ->setLanguage('js')
                ->formatValue(function ($value) {
                    return $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';
                });
        }

        // --- PANEL 3: AUDITORÍA ---
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed()
            ->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }
}