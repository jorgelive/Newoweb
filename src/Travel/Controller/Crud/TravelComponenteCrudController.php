<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteTipoEnum;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class TravelComponenteCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelComponente::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Componente Logístico')
            ->setEntityLabelInPlural('Componentes Logísticos')
            ->setSearchFields(['id', 'nombre', 'tipo'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /**
     * 🔥 CONFIGURACIÓN DE FILTROS LATERALES
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // Filtro por la colección ManyToMany de Servicios (lado inverso, filtrable igual)
            ->add(EntityFilter::new('servicios', 'Servicio (Tour)'))
            // Filtro por el Enum de Categoría Operativa
            ->add(ChoiceFilter::new('tipo', 'Categoría Operativa')->setChoices(
                array_reduce(ComponenteTipoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e->value], [])
            ));
    }

    public function configureActions(Actions $actions): Actions
    {
        // 🔥 Botón de Clonar usando Stimulus y SweetAlert2
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneComponente')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar componente?',
                'data-panel--confirm-text-value' => 'Se duplicará el componente con todas sus tarifas e ítems internos. Podrás editarlo a continuación.',
                'data-panel--confirm-icon-value' => 'question',
                'data-panel--confirm-confirm-button-text-value' => 'Sí, clonar',
                'data-panel--confirm-confirm-color-value' => '#0ea5e9'
            ]);

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cloneAction)
            ->add(Crud::PAGE_DETAIL, $cloneAction)
            ->add(Crud::PAGE_EDIT, $cloneAction);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE)
            ->setPermission('cloneAction', Roles::MAESTROS_WRITE);
    }

    /**
     * 🔥 Deep Clone mediante llamada a __clone() en el Dominio.
     */
    public function cloneComponente(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelComponente $original */
        $original = $context->getEntity()->getInstance();

        // ¡Toda la magia recursiva ocurre aquí gracias a las entidades!
        $clon = clone $original;

        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Componente y tarifas clonadas exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Identidad del Componente')->setIcon('fa fa-box');

        yield TextField::new('nombre', 'Nombre Interno (Administrativo)')
            ->setColumns(6)
            ->setHelp('Ej: BTI - Boleto Turístico Integral');

        yield ChoiceField::new('tipo', 'Categoría Operativa')
            ->setChoices(array_reduce(ComponenteTipoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteTipoEnum ? $value->value : $value)
            ->setColumns(6);

        yield FormField::addPanel('Título Público (Lo que ve el cliente)')->setIcon('fa fa-language')
            ->setHelp('Si este componente no es un "Pool" y se muestra directamente al cliente, aquí defines cómo se lee en su PDF.');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        // 🔥 LECTURA OPTIMIZADA: Se eliminó el fw-bold rudo por un fw-semibold más fino y limpio
        yield TextField::new('virtualTitulo', 'Título Comercial')
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

        // ESCRITURA
        yield CollectionField::new('titulo', 'Título Comercial')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield FormField::addPanel('Trazabilidad')->setIcon('fa fa-link');

        // 🔥 LECTURA OPTIMIZADA: solo en Detalle, listado bonito de solo-lectura
        yield TextField::new('virtualServicios', 'Servicios (Tours)')
            ->hideOnForm() // se ve en índice y detalle, pero no en el formulario new/edit (para eso está el AssociationField)
            ->formatValue(static function ($value, $entity) {
                $servicios = $entity->getServicios();

                if ($servicios->isEmpty()) {
                    return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin servicios</span>';
                }

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 200px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($servicios as $servicio) {
                    $nombre = htmlspecialchars((string) $servicio->getNombreInterno());
                    $html .= sprintf(
                        '<li class="px-2 py-1 mb-1 bg-light border rounded small text-truncate" title="%s" style="display: block;">
                    <i class="fas fa-layer-group text-primary" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark fw-medium">%s</span>
                </li>',
                        $nombre, $nombre
                    );
                }
                $html .= '</ul>';

                return $html;
            })
            ->renderAsHtml();

        // ✏️ ESCRITURA: selección múltiple de Servicios existentes (relación inversa)
        yield AssociationField::new('servicios', 'Servicios (Tours) que usan este insumo')
            ->onlyOnForms()
            ->setFormTypeOption('by_reference', false)
            ->autocomplete()
            ->setColumns(12)
            ->setHelp('Selecciona los Servicios/Tours donde este componente ya está incluido. Al guardar, se vincula desde ambos lados automáticamente.');

        yield FormField::addPanel('Uso en Itinerarios')->setIcon('fa fa-route');

        yield TextField::new('virtualSegmentosInyectados', 'Segmentos donde se usa')
            ->hideOnForm() // se ve en índice y detalle, no en new/edit
            ->formatValue(static function ($value, $entity) {
                $coleccion = $entity->getSegmentoComponentesInyectados();

                if ($coleccion->isEmpty()) {
                    return '<span class="badge bg-light text-muted border">No inyectado en ningún segmento</span>';
                }

                $html = '<div class="d-flex flex-column gap-1" style="font-size: 11px; min-width: 260px; max-height: 220px; overflow-y: auto; padding-right: 5px;">';
                foreach ($coleccion as $sc) {
                    $segmentoNombre = $sc->getSegmento() ? htmlspecialchars((string) $sc->getSegmento()) : 'N/A';
                    $hora = $sc->getHora() ? $sc->getHora()->format('H:i') : 'Horario BD';
                    $ctx = $sc->getItinerarioContexto() ? htmlspecialchars($sc->getItinerarioContexto()->getNombreInterno()) : 'Global';
                    $colorCtx = $sc->getItinerarioContexto() ? 'text-primary' : 'text-success';
                    $iconCtx = $sc->getItinerarioContexto() ? 'fa-filter' : 'fa-globe';

                    $html .= sprintf(
                        '<div class="p-1 border rounded bg-white shadow-sm">
                    <strong class="d-block text-truncate mb-1" style="max-width: 280px;" title="%s">%s</strong>
                    <span class="text-muted"><i class="far fa-clock"></i> %s</span>
                    <span class="mx-1 text-muted">|</span>
                    <span class="%s fw-bold" title="Contexto de Plantilla"><i class="fas %s"></i> %s</span>
                </div>',
                        $segmentoNombre, $segmentoNombre, $hora, $colorCtx, $iconCtx, $ctx
                    );
                }
                $html .= '</div>';
                return $html;
            })
            ->renderAsHtml();

        yield FormField::addPanel('Configuración Operativa')->setIcon('fa fa-cogs');

        yield NumberField::new('duracion', 'Duración')
            ->setNumDecimals(1)
            ->setColumns(4)
            ->formatValue(static fn ($value) => $value ? sprintf('%s hrs', $value) : '-');

        yield IntegerField::new('anticipacionalerta', 'Alerta Temprana')
            ->setColumns(4)
            ->hideOnIndex()
            ->formatValue(static fn ($value) => $value ? sprintf('%d Días', $value) : '-');

        yield FormField::addPanel('Ítems y Upsells (Lo que incluye)')->setIcon('fa fa-list-check');

        // 🔥 LECTURA OPTIMIZADA: Detalle de inclusiones con scroll vertical limpio y alineado
        yield TextField::new('virtualItems', 'Detalle de Inclusiones')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $items = $entity->getComponenteItems();
                if ($items->isEmpty()) {
                    return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin inclusiones</span>';
                }

                $html = '<ul style="max-height: 150px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($items as $item) {
                    $nombre = htmlspecialchars((string) $item);
                    $html .= sprintf(
                        '<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s" style="display: block;">
                            <i class="fas fa-angle-right text-muted" style="margin-right: 4px;"></i> <span class="text-dark">%s</span>
                        </li>',
                        $nombre, $nombre
                    );
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // ESCRITURA
        yield CollectionField::new('componenteItems', 'Detalle de Inclusiones')
            ->useEntryCrudForm(TravelComponenteItemCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelComponenteItem())
            ->setFormTypeOption('required', false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield FormField::addPanel('Tarifario Base')->setIcon('fa fa-money-bill-wave');

        // 🔥 LECTURA OPTIMIZADA: Tarifas con scrollbar y diseño en bloque blanco alineado
        yield TextField::new('virtualTarifas', 'Costos Maestros')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $tarifas = $entity->getTarifas();
                if ($tarifas->isEmpty()) {
                    return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin costos maestros</span>';
                }

                $html = '<ul style="max-height: 150px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($tarifas as $tarifa) {
                    $nombre = htmlspecialchars((string) $tarifa);
                    $html .= sprintf(
                        '<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s" style="display: block;">
                            <i class="fas fa-tag text-success" style="font-size: 0.85em; margin-right: 4px;"></i> <span class="text-dark fw-medium">%s</span>
                        </li>',
                        $nombre, $nombre
                    );
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // ESCRITURA
        yield CollectionField::new('tarifas', 'Costos Maestros')
            ->useEntryCrudForm(TravelTarifaCrudController::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new TravelTarifa())
            ->setColumns(12);


    }
}