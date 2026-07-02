<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
// 🔥 IMPORTAMOS LAS CLASES DE FILTROS DE EASYADMIN
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class TravelTarifaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelTarifa::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Tarifa')
            ->setEntityLabelInPlural('Tarifario Maestro')
            ->setSearchFields(['id', 'nombreInterno', 'monto'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /**
     * 🔥 NUEVO: CONFIGURACIÓN DE FILTROS LATERALES
     * Aquí definimos qué campos se pueden usar para filtrar la tabla principal.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // Filtro principal solicitado: Por componente
            ->add(EntityFilter::new('componente', 'Componente Logístico'))
            // Filtros extra de regalo que te serán muy útiles
            ->add(EntityFilter::new('moneda', 'Moneda'))
            ->add(EntityFilter::new('proveedor', 'Proveedor'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneTarifa')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar Tarifa?',
                'data-panel--confirm-text-value' => 'Se duplicará esta tarifa para crear una nueva.',
                'data-panel--confirm-icon-value' => 'question',
                'data-panel--confirm-confirm-button-text-value' => 'Sí, clonar',
                'data-panel--confirm-confirm-color-value' => '#0ea5e9'
            ]);

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cloneAction)
            ->add(Crud::PAGE_DETAIL, $cloneAction)
            ->add(Crud::PAGE_EDIT, $cloneAction)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_CONTINUE);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE)
            ->setPermission(Action::SAVE_AND_CONTINUE, Roles::MAESTROS_WRITE)
            ->setPermission('cloneAction', Roles::MAESTROS_WRITE);
    }

    public function cloneTarifa(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelTarifa $original */
        $original = $context->getEntity()->getInstance();

        $componenteOriginal = $original->getComponente();

        $clon = clone $original;
        $clon->setComponente($componenteOriginal);

        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Tarifa clonada exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        $isEmbedded = $this->isEmbedded();

        yield FormField::addPanel('Identificación y Costo')->setIcon('fa fa-tag');

        if (!$isEmbedded) {
            // Lectura
            yield TextField::new('componente', 'Componente Logístico')
                ->hideOnForm()
                ->formatValue(static fn($value) => $value ? sprintf('<span class="badge bg-light text-dark border"><i class="fas fa-cube text-muted"></i> %s</span>', htmlspecialchars((string) $value)) : '-')
                ->renderAsHtml();

            // Escritura
            yield AssociationField::new('componente', 'Componente Logístico')
                ->hideOnIndex()->hideOnDetail()->setColumns(6);
        }

        yield TextField::new('nombreInterno', 'Referencia Interna')
            ->setColumns(6);

        // Lectura
        yield TextField::new('moneda', 'Moneda')
            ->hideOnForm()
            ->formatValue(static fn($value) => $value ? sprintf('<span class="badge bg-secondary text-white">%s</span>', htmlspecialchars((string) $value)) : '-')
            ->renderAsHtml();

        // Escritura
        yield AssociationField::new('moneda', 'Moneda')
            ->hideOnIndex()->hideOnDetail()
            ->setColumns(3)->setRequired(true)->setFormTypeOption('attr', ['required' => true]);

        yield NumberField::new('monto', 'Costo Neto')
            ->setNumDecimals(2)
            ->setColumns(3)
            ->formatValue(static fn($value) => $value ? sprintf('<strong class="text-dark">%s</strong>', $value) : '0.00');

        // 🔥 LECTURA (Virtual): Badge HTML para Costo Fijo
        yield TextField::new('virtualCostoPorGrupo', '¿Costo Fijo (Grupal)?')
            ->hideOnForm()
            ->formatValue(static fn($value, $entity) => $entity->isCostoPorGrupo()
                ? '<span class="badge bg-primary text-white"><i class="fas fa-users"></i> Grupal Fijo</span>'
                : '<span class="badge bg-light text-dark border"><i class="fas fa-user text-muted"></i> Por Pasajero</span>')
            ->renderAsHtml();

        // 🔥 ESCRITURA (Real): Checkbox nativo
        yield BooleanField::new('costoPorGrupo', '¿Costo Fijo (Grupal)?')
            ->onlyOnForms()
            ->setHelp('Activa esto si el costo NO se debe multiplicar por la cantidad de pasajeros (Ej. Un bus completo).')
            ->setColumns(6);

        yield FormField::addPanel('Operaciones B2B (Requerimientos)')->setIcon('fa fa-truck-loading')
            ->setHelp('Datos sugeridos al momento de cotizar. El operador podrá cambiarlos libremente en el motor operativo.');

        // Lectura
        yield TextField::new('proveedor', 'Proveedor')
            ->hideOnForm()
            ->formatValue(static fn($value) => $value ? sprintf('<span class="badge bg-light text-dark border"><i class="fas fa-building text-info"></i> %s</span>', htmlspecialchars((string) $value)) : '<span class="text-muted small">Cualquiera</span>')
            ->renderAsHtml();

        // Escritura
        yield AssociationField::new('proveedor', 'Proveedor por Defecto')
            ->hideOnIndex()->hideOnDetail()
            ->setRequired(false)->setHelp('Sugerencia operativa (Ej: PeruRail).')->setColumns(6);

        yield TextField::new('nombreParaProveedor', 'Nombre en Tarifario del Proveedor')
            ->setRequired(false)
            ->setHelp('El texto exacto que el proveedor reconoce en sus reservas (Ej: Ticket Tren Expedition).')
            ->setColumns(6);

        yield FormField::addPanel('Restricciones de Venta (Constraints)')->setIcon('fa fa-filter')
            ->setHelp('Si dejas estos campos vacíos, la tarifa funcionará como "Comodín" y aplicará para cualquier pasajero o modalidad.');

        // 🔥 LECTURA (Virtual): Render HTML limpio para Modalidad
        yield TextField::new('virtualModalidad', 'Modalidad')
            ->hideOnForm()
            ->formatValue(static fn ($value, $entity) => $entity->getModalidad() ? sprintf('<span class="text-dark fw-medium">%s</span>', $entity->getModalidad()->value) : '<span class="text-muted small">Cualquiera</span>')
            ->renderAsHtml();

        // 🔥 ESCRITURA (Real): Select nativo
        yield ChoiceField::new('modalidad', 'Modalidad')
            ->setChoices(array_reduce(TarifaModalidadEnum::cases(), fn ($c, $e) => $c + [$e->name => $e], []))
            ->setRequired(false)->onlyOnForms()->setColumns(6);

        // 🔥 LECTURA (Virtual): Render HTML limpio para Procedencia
        yield TextField::new('virtualProcedencia', 'Mercado (Procedencia)')
            ->hideOnForm()
            ->formatValue(static fn ($value, $entity) => $entity->getProcedencia() ? sprintf('<span class="text-dark fw-medium">%s</span>', $entity->getProcedencia()->value) : '<span class="text-muted small">Cualquiera</span>')
            ->renderAsHtml();

        // 🔥 ESCRITURA (Real): Select nativo
        yield ChoiceField::new('procedencia', 'Mercado (Procedencia)')
            ->setChoices(array_reduce(TarifaProcedenciaEnum::cases(), fn ($c, $e) => $c + [$e->name => $e], []))
            ->setRequired(false)->onlyOnForms()->setColumns(6);

        yield IntegerField::new('edadMinima', 'Edad Mín.')->setRequired(false)->setColumns(3)->formatValue(static fn ($value) => $value ?? '-');
        yield IntegerField::new('edadMaxima', 'Edad Máx.')->setRequired(false)->setColumns(3)->formatValue(static fn ($value) => $value ?? '-');
        yield IntegerField::new('capacidadMinima', 'Cap. Mínima')->setRequired(false)->setColumns(3)->hideOnIndex()->formatValue(static fn ($value) => $value ?? '-');
        yield IntegerField::new('capacidadMaxima', 'Cap. Máxima')->setRequired(false)->setColumns(3)->hideOnIndex()->formatValue(static fn ($value) => $value ?? '-');

        yield FormField::addPanel('Traducciones del Costo (Opcional)')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        // 🔥 LECTURA (Virtual): Render HTML de idioma
        yield TextField::new('virtualTitulo', 'Título Visible al Cliente')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                if (is_iterable($entity->getTitulo())) {
                    foreach ($entity->getTitulo() as $item) {
                        if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                            return sprintf('<span class="text-dark fw-semibold" style="letter-spacing: -0.2px;">%s</span>', htmlspecialchars(strip_tags($item['content'])));
                        }
                    }
                }
                return '<span class="text-muted small"><i class="fas fa-language"></i> Sin título en español</span>';
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Real): El CollectionField original
        yield CollectionField::new('titulo', 'Título Visible al Cliente')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)->hideOnIndex()->hideOnDetail()->setColumns(12);
    }
}