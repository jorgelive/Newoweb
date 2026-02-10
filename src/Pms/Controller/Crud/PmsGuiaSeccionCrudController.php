<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem; // ✅ Cambiamos la entidad de referencia
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

class PmsGuiaSeccionCrudController extends BaseCrudController
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
        return PmsGuiaSeccion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sección de Guía')
            ->setEntityLabelInPlural('Secciones de Guía')
            ->setSearchFields(['id', 'nombreInterno', 'titulo', 'icono'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        // --- BLOQUE 1: CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración Básica')
            ->setIcon('fa fa-cog')
            ->collapsible();

        yield TextField::new('nombreInterno', 'Nombre Interno (Admin)')
            ->setHelp('Nombre descriptivo para uso administrativo interno. No se muestra al cliente.')
            ->setColumns(12);

        yield BooleanField::new('esComun', 'Sección Común')
            ->setHelp('Si se activa, podrá ser vinculada a cualquier Guía de Unidad.')
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield TextField::new('icono', 'Icono (FontAwesome)')
            ->setHelp('Ej: fa-wifi, fa-utensils.')
            ->setColumns(6);

        // --- BLOQUE 2: TRADUCCIÓN ---
        yield FormField::addPanel('Contenido Multiidioma')
            ->setIcon('fa fa-language')
            ->collapsible();

        yield BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6)
            ->setHelp('⚠️ Reemplazará textos existentes.');

        yield CollectionField::new('titulo', 'Título por Idioma')
            ->setEntryType(TranslationTextType::class)
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->addCssClass('field-full-width')
            ->formatValue(function ($value) {
                if (empty($value) || !is_array($value)) return '';
                foreach ($value as $item) {
                    if (isset($item['language']) && $item['language'] === 'es') return $item['content'] ?? '';
                }
                return reset($value)['content'] ?? '';
            });

        // --- BLOQUE 3: ESTRUCTURA (ACTUALIZADO) ---
        yield FormField::addPanel('Ítems de Contenido')
            ->setIcon('fa fa-layer-group')
            ->collapsible();

        // 🔴 CAMBIO IMPORTANTE: Ahora apuntamos a 'seccionHasItems' y usamos el Crud intermedio
        yield CollectionField::new('seccionHasItems', 'Ítems en esta Sección')
            ->useEntryCrudForm(PmsGuiaSeccionHasItemCrudController::class) // ✅ Usamos el controlador de la tabla intermedia
            ->setEntryIsComplex(true)
            ->setColumns(12)
            ->addCssClass('field-full-width')
            ->setFormTypeOption('by_reference', false)
            // ✅ Instanciamos la relación, no el ítem directo
            ->setFormTypeOption('prototype_data', new PmsGuiaSeccionHasItem())
            ->setFormTypeOption('entry_options', [
                'empty_data' => function ($form) {
                    return new PmsGuiaSeccionHasItem();
                },
            ]);

        // --- BLOQUE 4: AUDITORÍA ---
        yield FormField::addPanel('Auditoría del Sistema')
            ->setIcon('fa fa-history')
            ->onlyOnDetail()
            ->collapsible();

        yield TextField::new('id', 'UUID')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Registrado en')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Última modificación')->onlyOnDetail();
    }
}