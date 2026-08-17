<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class TravelItinerarioCrudController extends BaseCrudController
{
    public function __construct(
        #[Autowire('%env(API_HOST_URL)%')] private string $apiUrl,
        AdminUrlGenerator $adminUrlGenerator,
        RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return TravelItinerario::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Plantilla de Itinerario')
            ->setEntityLabelInPlural('Plantillas Comerciales')
            ->setSearchFields(['id', 'slug', 'nombreInterno'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)
            ->addAssetMapperEntry('panel')
            ->addHtmlContentToHead(sprintf('<meta name="api-url" content="%s">', $this->apiUrl));
    }

    public function configureActions(Actions $actions): Actions
    {
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneItinerario')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar itinerario?',
                'data-panel--confirm-text-value' => 'Se duplicará esta plantilla de itinerario y todos sus segmentos de días. Podrás editarlo a continuación.',
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
     * @param AdminContext<TravelItinerario> $context
     */
    public function cloneItinerario(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelItinerario $original */
        $original = $context->getEntity()->getInstance();

        $clon = clone $original;
        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Plantilla de Itinerario y sus segmentos clonados exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    /**
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Definición de Plantilla')->setIcon('fa fa-book');

        yield TextField::new('slug', 'Slug / Código')->setColumns(6)
            ->setHelp('Código de identificación (ej. HD-COMBINADA-POOL-AM). No se envía a nadie.');
        yield TextField::new('nombreInterno', 'Nombre de Plantilla')->setColumns(6)
            ->setHelp('Nombre operativo, el que puede ir al proveedor.');

        yield IntegerField::new('duracionDias', 'Duración Total')
            ->setColumns(4)
            ->formatValue(static fn ($value) => $value ? sprintf('%d Días', $value) : '-');

        // LECTURA
        yield TextField::new('servicio', 'Servicio Vinculado')
            ->hideOnForm()
            ->formatValue(static function ($value) {
                if (!$value) return '<span class="text-muted"><i class="fas fa-exclamation-circle"></i> Sin servicio</span>';
                return sprintf('<span class="badge bg-light text-dark border"><i class="fas fa-cube text-muted" style="margin-right: 4px;"></i> %s</span>', htmlspecialchars((string) $value));
            })
            ->renderAsHtml();

        // ESCRITURA
        yield AssociationField::new('servicio', 'Servicio Vinculado')
            ->setColumns(6)
            ->autocomplete()
            ->hideOnIndex()
            ->hideOnDetail();

        yield FormField::addPanel('Presentación')->setIcon('fa fa-bullhorn');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        // LECTURA: Se usa el getter virtual
        yield TextField::new('virtualTitulo', 'Título Comercial')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                if (is_iterable($entity->getTitulo())) {
                    foreach ($entity->getTitulo() as $item) {
                        if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                            return sprintf('<span class="fw-bold">%s</span>', htmlspecialchars(strip_tags($item['content'])));
                        }
                    }
                }
                return '<span class="text-muted small"><i class="fas fa-language"></i> Sin título en español</span>';
            })
            ->renderAsHtml();

        // ESCRITURA: Se usa el campo real
        yield CollectionField::new('titulo', 'Título Comercial')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield FormField::addPanel('Estructura del Itinerario (Narrativa)')->setIcon('fa fa-stream');

        // 🔥 LECTURA MEJORADA: Se usa el getter virtual con mejor UI (Flexbox y Badge blanco)
        yield TextField::new('virtualSegmentos', 'Pasos del Itinerario')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $segmentos = $entity->getItinerarioSegmentos();
                if ($segmentos->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin segmentos definidos</span>';

                $iterator = $segmentos->getIterator();
                $iterator->uasort(function ($a, $b) {
                    if ($a->getDia() === $b->getDia()) return $a->getOrden() <=> $b->getOrden();
                    return $a->getDia() <=> $b->getDia();
                });

                $html = '<ul style="max-height: 220px; overflow-y: auto; text-align: left; min-width: 280px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($iterator as $rel) {
                    $dia = $rel->getDia();
                    $segmento = $rel->getSegmento();
                    $nombreSegmento = $segmento ? htmlspecialchars((string) $segmento->getNombreInterno()) : 'Segmento pendiente';

                    // Se itera la logística del segmento aplicable a ESTA plantilla y ESTE día
                    // (contexto = esta plantilla o global; día = este día o global) para:
                    //  - saber si aporta la hora de "servicio completo" (punto rojo)
                    //  - derivar las horas efectivas del paso (mín. inicio / máx. fin)
                    $promovido = false;
                    $inicios = [];
                    $fines = [];
                    if ($segmento) {
                        foreach ($segmento->getSegmentoComponentes() as $sc) {
                            $ctx = $sc->getItinerarioContexto();
                            $ctxOk = $ctx === null
                                || ($ctx->getId() && $entity->getId() && (string) $ctx->getId() === (string) $entity->getId());
                            $diaSc = $sc->getDia();
                            $diaOk = $diaSc === null || $diaSc === $dia;
                            if (!$ctxOk || !$diaOk) {
                                continue;
                            }
                            if ($sc->isHoraServicioCompleto()) {
                                $promovido = true;
                            }
                            if ($sc->getHora()) {
                                $inicios[] = $sc->getHora()->format('H:i');
                            }
                            if ($sc->getHoraFin()) {
                                $fines[] = $sc->getHoraFin()->format('H:i');
                            }
                        }
                    }
                    $dot = $promovido
                        ? '<span class="d-inline-block rounded-circle me-2 flex-shrink-0" style="width:8px;height:8px;background:#ef4444;" title="Aporta la hora de servicio completo de este día"></span>'
                        : '';

                    // Horas efectivas del paso (si algún componente aplicable tiene hora).
                    $horaTxt = '';
                    if ($inicios) {
                        sort($inicios);
                        $ini = $inicios[0];
                        $fin = '';
                        if ($fines) {
                            sort($fines);
                            $fin = ' – ' . end($fines);
                        }
                        $horaTxt = sprintf(
                            '<span class="text-muted ms-2 flex-shrink-0 text-nowrap" style="font-size: 0.85em;"><i class="far fa-clock"></i> %s%s</span>',
                            $ini,
                            $fin
                        );
                    }

                    // Implementación de Flexbox para alineación perfecta y text-white forzado para el contraste
                    $html .= sprintf(
                        '<li class="px-2 py-1 mb-1 bg-white border rounded small d-flex align-items-center" title="%s">
                            %s<span class="badge bg-primary text-white me-2" style="font-size: 0.75rem; min-width: 48px; text-align: center;">Día %d</span>
                            <span class="text-dark fw-medium text-truncate">%s</span>%s
                        </li>',
                        $nombreSegmento,
                        $dot,
                        $dia,
                        $nombreSegmento,
                        $horaTxt
                    );
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // ESCRITURA: Se usa el campo real
        yield CollectionField::new('itinerarioSegmentos', 'Pasos del Itinerario')
            ->useEntryCrudForm(TravelItinerarioSegmentoRelCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelItinerarioSegmentoRel())
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12)
            ->setHelp('Selecciona los segmentos del pool del servicio y ordénalos por día.');
    }
}