<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Form\Type\PmsGuiaHasSeccionType;
use App\Panel\Form\Type\TranslationTextType;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class PmsGuiaCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuia::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Guía')
            ->setEntityLabelInPlural('Guías')
            ->setSearchFields(['id', 'titulo', 'unidad.nombre'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        // El ID siempre oculto en formulario
        yield IdField::new('id')->hideOnForm();

        // --- PANEL 1: CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración General')
            ->setIcon('fa fa-cog')
            ->collapsible(); // Esto crea el efecto Accordion

        yield AssociationField::new('unidad', 'Unidad / Propiedad')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setColumns(12);

        yield BooleanField::new('activo', '¿Publicada?')
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6)
            ->setHelp('⚠️ Reemplazará textos existentes.');

        // --- PANEL 2: CONTENIDO TRADUCIBLE ---
        yield FormField::addPanel('Títulos y Traducciones')
            ->setIcon('fa fa-language')
            ->collapsible();

        yield CollectionField::new('titulo', 'Títulos de la Guía')
            ->setEntryType(TranslationTextType::class)
            ->setEntryIsComplex(true)      // ✅ Para usar tus banderas
            ->showEntryLabel(false)
            ->renderExpanded(true)         // ✅ Evita conflictos de colapsos
            ->setColumns(12)               // ✅ Full Width real con tu CSS
            ->addCssClass('field-full-width')
            ->formatValue(function ($value) {
                if (empty($value) || !is_array($value)) return '';
                foreach ($value as $item) {
                    if (isset($item['language']) && $item['language'] === 'es') return $item['content'] ?? '';
                }
                return reset($value)['content'] ?? '';
            });

        // --- PANEL 3: ESTRUCTURA ---
        yield FormField::addPanel('Secciones de la Guía')
            ->setIcon('fa fa-sitemap')
            ->collapsible();

        yield CollectionField::new('guiaHasSecciones', 'Orden y Selección de Secciones')
            ->setEntryType(PmsGuiaHasSeccionType::class)
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->addCssClass('field-full-width');

        // --- PANEL 4: AUDITORÍA (Solo Detalle) ---
        yield FormField::addPanel('Información de Auditoría')
            ->setIcon('fa fa-history')
            ->onlyOnDetail()
            ->collapsible();

        yield TextField::new('id', 'UUID Técnico')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Creado el')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado el')->onlyOnDetail();
    }
}