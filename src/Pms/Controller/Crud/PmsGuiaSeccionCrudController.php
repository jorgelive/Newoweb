<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsGuiaSeccionCrudController.
 * Gestión de bloques o secciones de contenido para la guía digital.
 * Hereda de BaseCrudController y utiliza UUID v7 con seguridad prioritaria.
 */
class PmsGuiaSeccionCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuiaSeccion::class;
    }

    /**
     * Configuración de permisos basada en la clase global Roles.
     * ✅ Se aplica la herencia y LUEGO se imponen los permisos de Roles para prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // Obtenemos configuración global del panel base
        $actions = parent::configureActions($actions);

        // Aplicamos restricciones finales según el rol del usuario
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
            ->setEntityLabelInSingular('Sección de Guía')
            ->setEntityLabelInPlural('Secciones de Guía')
            ->setSearchFields(['id', 'titulo', 'icono'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // --- BLOQUE 1: CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración Básica')->setIcon('fa fa-cog');

        // ✅ Manejo de UUID (IdTrait) para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        yield BooleanField::new('esComun', 'Sección Común')
            ->setHelp('Si se activa, podrá ser vinculada a cualquier Guía de Unidad.')
            ->renderAsSwitch(true);

        yield TextField::new('icono', 'Icono (FontAwesome)')
            ->setHelp('Ej: fa-wifi, fa-utensils, fa-info-circle.')
            ->setRequired(false);

        // --- BLOQUE 2: TRADUCCIÓN ---
        yield FormField::addPanel('Contenido Multiidioma')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Título Automáticamente')
            ->setHelp('Utilizará Google Translate para completar los idiomas configurados al guardar.')
            ->onlyOnForms()
            ->hideOnIndex()
            ->setPermission(Roles::RESERVAS_WRITE);

        yield CollectionField::new('titulo', 'Título por Idioma')
            ->setHelp('Mapeo JSON. Use "es" como base para la traducción automática.')
            ->allowAdd()
            ->allowDelete();

        // --- BLOQUE 3: ESTRUCTURA ---
        yield FormField::addPanel('Ítems de Contenido')->setIcon('fa fa-layer-group');

        yield CollectionField::new('items', 'Ítems en esta Sección')
            ->useEntryCrudForm() // Requiere PmsGuiaItemCrudController para el subformulario
            ->setHelp('Gestione el contenido detallado (WiFi, Mapas, Tarjetas) de esta sección.');

        // --- BLOQUE 4: AUDITORÍA (TimestampTrait) ---
        yield FormField::addPanel('Auditoría del Sistema')->setIcon('fa fa-history')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado en')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última modificación')
            ->onlyOnDetail();
    }
}