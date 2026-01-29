<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsGuia;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsGuiaCrudController.
 * Gestión de Guías de Unidades con soporte para traducción automática y UUID.
 * Hereda de BaseCrudController para estandarizar el comportamiento del panel.
 */
class PmsGuiaCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuia::class;
    }

    /**
     * Configuración de la seguridad granular.
     * ✅ Se aplica la herencia y LUEGO se imponen los permisos de Roles para prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // Primero obtenemos las acciones configuradas en el padre (BaseCrudController)
        $actions = parent::configureActions($actions);

        // Aplicamos los permisos después para que prevalezcan sobre la configuración base
        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Guía')
            ->setEntityLabelInPlural('Guías')
            ->setSearchFields(['id', 'titulo', 'unidad.nombre'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // --- SECCIÓN DE CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración General')->setIcon('fa fa-cog');

        // ✅ Manejo de UUID (IdTrait) para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        yield AssociationField::new('unidad', 'Unidad / Departamento')
            ->setRequired(true)
            ->setHelp('Seleccione la unidad a la que pertenece esta guía.');

        yield BooleanField::new('activo', 'Guía Publicada')
            ->renderAsSwitch(true);

        // --- SECCIÓN DE TRADUCCIÓN (Trait Global) ---
        yield FormField::addPanel('Contenido y Traducción')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', '¿Traducir automáticamente?')
            ->setHelp('Si se marca, se utilizará el servicio de traducción para completar los idiomas faltantes.')
            ->onlyOnForms()
            ->hideOnIndex()
            ->setPermission(Roles::RESERVAS_WRITE);

        yield CollectionField::new('titulo', 'Títulos (JSON/Array)')
            ->setHelp('Mapeo de idiomas (ej: es, en, pt).')
            ->allowAdd()
            ->allowDelete();

        // --- SECCIÓN DE ESTRUCTURA ---
        yield FormField::addPanel('Estructura de la Guía')->setIcon('fa fa-sitemap');

        yield CollectionField::new('guiaHasSecciones', 'Secciones Vinculadas')
            ->useEntryCrudForm()
            ->setHelp('Gestione las secciones y su orden específico para esta guía.');

        // --- SECCIÓN DE AUDITORÍA (TimestampTrait) ---
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-history')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Fecha de Creación')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última Modificación')
            ->onlyOnDetail();
    }
}