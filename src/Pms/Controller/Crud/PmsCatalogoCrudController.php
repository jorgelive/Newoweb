<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsCatalogo;
use App\Pms\Enum\PmsCatalogoBloque;
use App\Pms\Form\Type\PmsCatalogoHasItemType;
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
 * Escaparate público de una unidad.
 *
 * No es «la guía filtrada»: tiene su propia estructura porque agrupa el mismo
 * contenido con otra jerarquía (ver docs/PmsGuiaHuesped.md §2.b). De ahí que el
 * formulario gire en torno a UNA cosa —colocar contenidos en bloques— y no a
 * un árbol de secciones.
 */
class PmsCatalogoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsCatalogo::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Escaparate')
            ->setEntityLabelInPlural('Escaparates (catálogo público)')
            ->setSearchFields(['id', 'titulo', 'unidad.nombre'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined()
            ->setHelp(
                'index',
                'Lo que ve un visitante SIN reserva en <code>/{establecimiento}/{unidad}</code>. '
                . 'Reutiliza los contenidos de la guía —no hay que reescribirlos— y los coloca en bloques comerciales.'
            );
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

    public function configureFields(string $pageName): iterable
    {
        // --- PANEL 1: CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración General')
            ->setIcon('fa fa-cog')
            ->collapsible();

        yield AssociationField::new('unidad', 'Unidad / Propiedad')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setColumns(12);

        yield BooleanField::new('activo', '¿Publicado?')
            ->renderAsSwitch(true)
            ->setColumns(6)
            ->setHelp('Con esto apagado, la página pública responde 404. No borra nada.');

        // TextField y no IntegerField: `renderAsHtml()` solo existe en el primero.
        // Y se apunta al stub `virtualContenidos`, no a `totalItemsActivos`:
        // TextField valida el valor CRUDO antes de formatearlo y un `int` lo
        // hace reventar. El número lo pone el formatValue desde la entidad.
        yield TextField::new('virtualContenidos', 'Contenidos')
            ->hideOnForm()
            ->formatValue(static function ($value, ?PmsCatalogo $entity): string {
                $n = $entity?->getTotalItemsActivos() ?? 0;

                return $n > 0
                    ? sprintf('<span class="badge badge-info">%d</span>', $n)
                    : '<span class="badge badge-warning">vacío</span>';
            })
            ->renderAsHtml();

        yield BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6)
            ->setHelp('⚠️ Reemplazará textos existentes.');

        // --- PANEL 2: TEXTOS COMERCIALES ---
        yield FormField::addPanel('Título y gancho')
            ->setIcon('fa fa-language')
            ->collapsible()
            ->setHelp('Son COMERCIALES y no tienen por qué coincidir con los de la guía: allí pone «Manual de Casita 3», aquí «Casita con vista al bosque».');

        yield CollectionField::new('titulo', 'Título del escaparate')
            ->setEntryType(TranslationTextType::class)
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->addCssClass('field-full-width')
            ->formatValue(self::primeraTraduccion(...));

        yield CollectionField::new('subtitulo', 'Gancho (bajo el título)')
            ->setEntryType(TranslationTextType::class)
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setRequired(false)
            ->setColumns(12)
            ->addCssClass('field-full-width')
            ->formatValue(self::primeraTraduccion(...));

        // --- PANEL 3: CONTENIDOS ---
        yield FormField::addPanel('Contenidos del escaparate')
            ->setIcon('fa fa-store')
            ->collapsible()
            ->setHelp(
                'El ORDEN DE LOS BLOQUES en la página lo fija el código, no este formulario '
                . '(destacado → descripción → fotos → servicios → ubicación → antes de reservar). '
                . 'Aquí se decide en qué bloque va cada contenido y su posición <em>dentro</em> de él. '
                . 'Un contenido sin sección en la guía aparece SOLO aquí: es la forma de poner algo puramente comercial.'
            );

        yield TextField::new('virtualBloques', 'Cómo queda la página')
            ->hideOnForm()
            ->formatValue(self::resumenBloques(...))
            ->renderAsHtml();

        yield CollectionField::new('catalogoHasItems', 'Contenidos y su bloque')
            ->onlyOnForms()
            ->setEntryType(PmsCatalogoHasItemType::class)
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->addCssClass('field-full-width');

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield TextField::new('id', 'UUID Técnico')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }

    /** Texto en español de un campo i18n, con el primero como respaldo. */
    private static function primeraTraduccion(mixed $value): string
    {
        if (empty($value) || !is_array($value)) {
            return '';
        }

        foreach ($value as $item) {
            if (($item['language'] ?? null) === 'es') {
                return $item['content'] ?? '';
            }
        }

        return reset($value)['content'] ?? '';
    }

    /**
     * Vista previa de la página agrupada por bloque, en el MISMO orden en que
     * se pintará. Es el campo que de verdad hace útil el detalle: la colección
     * cruda sale ordenada por `orden` y mezcla bloques, así que no deja ver la
     * forma final de la página — que es lo único que le importa al editor.
     */
    private static function resumenBloques(mixed $value, ?PmsCatalogo $entity): string
    {
        if (!$entity instanceof PmsCatalogo) {
            return '';
        }

        $porBloque = [];
        foreach ($entity->getCatalogoHasItems() as $rel) {
            if ($rel->getItem()) {
                $porBloque[$rel->getBloque()->value][] = $rel;
            }
        }

        if ([] === $porBloque) {
            return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Escaparate vacío: la página pública dará 404.</span>';
        }

        $html = '<div style="text-align: left; min-width: 260px;">';

        // Se recorre el ENUM, no el array: así el resumen refleja el orden real
        // de la página y no el de llegada de las filas.
        foreach (PmsCatalogoBloque::cases() as $bloque) {
            $filas = $porBloque[$bloque->value] ?? [];
            if ([] === $filas) {
                continue;
            }

            usort($filas, static fn ($a, $b): int => $a->getOrden() <=> $b->getOrden());

            $html .= sprintf(
                '<div class="mb-2"><span class="badge bg-secondary-subtle text-secondary">%s</span><ul style="margin: 4px 0 0; padding: 0; list-style: none;">',
                htmlspecialchars($bloque->getLabel())
            );

            foreach ($filas as $rel) {
                $activo = $rel->isActivo();

                $html .= sprintf(
                    '<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate"><i class="fas %s" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="%s">%s</span></li>',
                    $activo ? 'fa-check-circle text-success' : 'fa-eye-slash text-muted',
                    $activo ? 'text-dark fw-medium' : 'text-muted text-decoration-line-through',
                    htmlspecialchars((string) $rel->getItem()?->getNombreInterno())
                );
            }

            $html .= '</ul></div>';
        }

        return $html . '</div>';
    }
}
