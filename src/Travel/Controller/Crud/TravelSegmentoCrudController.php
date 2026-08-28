<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Controller\Trait\RenderGaleriaTrait;
use App\Panel\Filter\ItinerarioPlantillaFilter;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelSegmento;
use Doctrine\DBAL\Connection;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelSegmentoImagen;
use App\Travel\Enum\ComponenteModoEnum;
use App\Security\Roles;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Travel\Enum\PuntoModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class TravelSegmentoCrudController extends BaseCrudController
{

    use RenderGaleriaTrait;
    public function __construct(
        #[Autowire('%travel.path.segmento_imagenes%')]
        private readonly string $uploadPath,
        private readonly CacheManager $imagineCacheManager,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly Connection $conexion,
    ) {
        parent::__construct($this->adminUrlGenerator, $this->requestStack);
    }

    protected function getImagineCacheManager(): CacheManager
    {
        return $this->imagineCacheManager;
    }

    public static function getEntityFqcn(): string
    {
        return TravelSegmento::class;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('servicios', 'Servicio (Tour)'))
            ->add(ItinerarioPlantillaFilter::new('itinerarioSegmentosInyectados.itinerario', 'Plantilla (Itinerario)'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Segmento')
            ->setEntityLabelInPlural('Segmentos de itinerario');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $entityManager->remove($entityInstance);
            $entityManager->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            $this->addFlash('danger', '⛔ <strong>Acción denegada:</strong> No puedes eliminar este segmento porque está siendo utilizado en uno o más Itinerarios.');
            $entityManager->refresh($entityInstance);
        }
    }

    public function configureActions(Actions $actions): Actions
    {
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneSegmento')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar segmento?',
                'data-panel--confirm-text-value' => 'Se duplicará este segmento narrativo con todas sus notas y componentes logísticos.',
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
     * @param AdminContext<TravelSegmento> $context
     */
    public function cloneSegmento(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelSegmento $original */
        $original = $context->getEntity()->getInstance();

        $clon = clone $original;
        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Segmento narrativo y su logística clonados exitosamente.');

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
    /**
     * Cuántos idiomas tiene un campo traducible, y si eso es normal o sospechoso.
     *
     * ⚠️ `#[AutoTranslate]` sólo corre al guardar **por el ORM**. Una fila cargada por SQL se
     * queda con el español solo, y hoy eso no lo dice ninguna pantalla: el campo se ve lleno.
     * Ver el reparto migración vs. comando en CLAUDE.md.
     *
     * @param list<array{language?: string, content?: string|null}> $traducciones
     */
    private function selloDeIdiomas(array $traducciones): string
    {
        $idiomas = [];

        foreach ($traducciones as $item) {
            if (isset($item['language'], $item['content']) && trim((string) $item['content']) !== '') {
                $idiomas[] = strtoupper((string) $item['language']);
            }
        }

        if (count($idiomas) >= 7) {
            return sprintf(
                '<span class="badge bg-success-subtle text-success-emphasis border">%d idiomas</span>',
                count($idiomas),
            );
        }

        return sprintf(
            '<span class="badge bg-warning-subtle text-warning-emphasis border" title="AutoTranslate no corrió, o la fila se cargó por SQL">⚠ sólo %s</span>',
            htmlspecialchars(implode(' ', $idiomas) ?: 'sin contenido'),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Pieza')->setIcon('fa fa-puzzle-piece');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualServicios', 'Servicios')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $servicios = $entity->getServicios();
                if ($servicios->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin servicios vinculados</span>';

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($servicios as $servicio) {
                    $nombre = htmlspecialchars((string) $servicio);
                    $html .= sprintf('<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s" style="display: block;"><i class="fas fa-layer-group text-primary" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark fw-medium">%s</span></li>', $nombre, $nombre);
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield AssociationField::new('servicios', 'Servicios')
            ->setFormTypeOptions(['by_reference' => false, 'multiple' => true])
            ->setHelp('Pools de Servicios (Contenedores) donde estará disponible.')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(6);

        yield TextField::new('slug', 'Slug / Código')->setColumns(6);
        yield TextField::new('nombreInterno', 'Nombre del Segmento')->setColumns(6);
        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        // ⚠️ Cuántas cotizaciones tienen ya congelado este segmento.
        //
        // Cambia cómo se edita: los snapshots ya emitidos NO se tocan, pero saber que la pieza
        // está viva en doce propuestas evita retoques a la ligera. Va `onlyOnDetail` a propósito:
        // en el índice sería una consulta por fila.
        //
        // ⚠️ `cotizacion_segmento.segmento_maestro_id` es `varchar(36)` CON guiones y
        // `travel_segmento.id` es `binary(16)`. Comparar en crudo devuelve **cero filas y ningún
        // error**, que es la peor forma de fallar: parece «no se usa en ninguna».
        yield TextField::new('virtualUsoEnCotizaciones', 'Congelado en cotizaciones')
            ->onlyOnDetail()
            ->formatValue(function ($value, TravelSegmento $entity) {
                $id = $entity->getId();

                if ($id === null) {
                    return '<span class="text-muted small">sin guardar</span>';
                }

                $usos = (int) $this->conexion->fetchOne(
                    'SELECT COUNT(DISTINCT cs.cotizacion_id)
                       FROM cotizacion_segmento sg
                       JOIN cotizacion_cotservicio cs ON cs.id = sg.cotservicio_id
                      WHERE sg.segmento_maestro_id = :id',
                    ['id' => (string) $id],
                );

                if ($usos === 0) {
                    return '<span class="badge bg-light text-muted border">Todavía en ninguna</span>';
                }

                return sprintf(
                    '<span class="badge bg-info-subtle text-info-emphasis border">%d cotizaci%s</span> '
                    . '<span class="text-muted small">— lo ya emitido no cambia al editar aquí.</span>',
                    $usos,
                    $usos === 1 ? 'ón' : 'ones',
                );
            })
            ->renderAsHtml();

        yield FormField::addPanel('Uso en Plantillas (Itinerarios)')->setIcon('fa fa-route');

        yield TextField::new('virtualItinerarios', 'Plantillas donde se usa')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $coleccion = $entity->getItinerarioSegmentosInyectados();

                if ($coleccion->isEmpty()) {
                    return '<span class="badge bg-light text-muted border">No inyectado en ninguna plantilla</span>';
                }

                $html = '<div class="d-flex flex-column gap-1" style="font-size: 11px; min-width: 250px; max-height: 220px; overflow-y: auto; padding-right: 5px;">';
                foreach ($coleccion as $rel) {
                    $itinerarioNombre = $rel->getItinerario() ? htmlspecialchars((string) $rel->getItinerario()) : 'N/A';
                    $servicioNombre = $rel->getItinerario() && $rel->getItinerario()->getServicio()
                        ? htmlspecialchars((string) $rel->getItinerario()->getServicio())
                        : null;

                    $html .= sprintf(
                        '<div class="p-1 border rounded bg-white shadow-sm">
                    <strong class="d-block text-truncate mb-1" style="max-width: 280px;" title="%s">%s</strong>
                    <span class="text-muted"><i class="fas fa-calendar-day"></i> Día %d</span>%s
                </div>',
                        $itinerarioNombre,
                        $itinerarioNombre,
                        $rel->getDia(),
                        $servicioNombre
                            ? sprintf(' <span class="mx-1 text-muted">|</span> <span class="text-primary fw-bold"><i class="fas fa-layer-group"></i> %s</span>', $servicioNombre)
                            : ''
                    );
                }
                $html .= '</div>';
                return $html;
            })
            ->renderAsHtml();

        // ── Dónde empieza y dónde termina ──────────────────────────────────────
        // Va ANTES del relato y no al final a propósito: es lo que el proveedor pregunta primero
        // y lo que decidía a mano quien montaba la orden.
        yield FormField::addPanel('Dónde empieza y dónde termina')->setIcon('fa fa-map-pin')
            ->setHelp(
                'De aquí sale el «dónde recojo / dónde dejo» de la orden de servicio. El servicio '
                . 'que abarca el día toma su origen del PRIMER segmento de la plantilla y su '
                . 'destino del ÚLTIMO. Si el extremo es el hotel del pasajero, elige «El '
                . 'alojamiento del pasajero» y no hace falta punto: se resuelve al emitir.'
            );

        yield TextField::new('virtualPuntos', 'Recojo → Entrega')
            ->hideOnForm()
            ->renderAsHtml();

        yield ChoiceField::new('inicioModo', 'Empieza en')
            ->setChoices(array_reduce(PuntoModoEnum::cases(), static fn ($c, $e) => $c + [$e->etiqueta() => $e], []))
            ->formatValue(static fn ($value) => $value instanceof PuntoModoEnum ? $value->etiqueta() : $value)
            ->onlyOnForms()
            ->setColumns(3);

        yield AssociationField::new('inicioPunto', 'Punto de inicio')
            ->autocomplete()
            ->onlyOnForms()
            ->setColumns(3)
            ->setHelp('Sólo si arriba has elegido «Un punto fijo».');

        yield ChoiceField::new('finModo', 'Termina en')
            ->setChoices(array_reduce(PuntoModoEnum::cases(), static fn ($c, $e) => $c + [$e->etiqueta() => $e], []))
            ->formatValue(static fn ($value) => $value instanceof PuntoModoEnum ? $value->etiqueta() : $value)
            ->onlyOnForms()
            ->setColumns(3);

        yield AssociationField::new('finPunto', 'Punto de fin')
            ->autocomplete()
            ->onlyOnForms()
            ->setColumns(3)
            ->setHelp('Sólo si arriba has elegido «Un punto fijo».');

        yield FormField::addPanel('Contenido Narrativo')->setIcon('fa fa-pen-fancy');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualTitulo', 'Título del Segmento')
            ->hideOnForm()
            ->formatValue(function ($value, TravelSegmento $entity) {
                foreach ($entity->getTitulo() as $item) {
                    if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                        return sprintf(
                            '<span class="fw-bold">%s</span> %s',
                            htmlspecialchars(strip_tags((string) $item['content'])),
                            $this->selloDeIdiomas($entity->getTitulo()),
                        );
                    }
                }

                return '<span class="text-muted small"><i class="fas fa-language"></i> Sin título en español</span>';
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield CollectionField::new('titulo', 'Título del Segmento')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        // 🔥 LECTURA (Getter Virtual)
        //
        // El cuerpo NO se veía en el detalle —el campo real es `onlyOnForms`—, así que para leer
        // lo que un segmento cuenta había que abrirlo en edición. Es justo lo que más se
        // inspecciona, y editar para mirar es cómo se cambian cosas sin querer.
        //
        // Lleva el recuento de idiomas al lado porque `#[AutoTranslate]` sólo corre al guardar
        // por el ORM: un segmento cargado por SQL se queda con el español solo y **no lo dice**.
        // Ver el aviso de migración vs. comando en CLAUDE.md.
        yield TextField::new('virtualContenido', 'Cuerpo del Relato')
            ->hideOnForm()
            ->formatValue(function ($value, TravelSegmento $entity) {
                $español = null;

                foreach ($entity->getContenido() as $item) {
                    if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                        $español = (string) $item['content'];
                    }
                }

                if ($español === null || trim($español) === '') {
                    return '<span class="text-muted small"><i class="fas fa-language"></i> Sin cuerpo en español</span>';
                }

                return sprintf(
                    '<div class="mb-1">%s</div><div class="border rounded p-2 bg-light" style="font-size:13px; max-height:340px; overflow:auto;">%s</div>',
                    $this->selloDeIdiomas($entity->getContenido()),
                    $español,
                );
            })
            ->renderAsHtml()
            ->setColumns(12);

        // 🔥 ESCRITURA (Campo Real)
        yield CollectionField::new('contenido', 'Cuerpo del Relato')
            ->setEntryType(TranslationHtmlType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield FormField::addPanel('Logística y Multimedia')->setIcon('fa fa-cogs');

        // 🔥 LECTURA (Getter Virtual ya existente)
        yield TextField::new('virtualLogistica', 'Logística Inyectada')
            ->hideOnForm()
            ->formatValue(function ($value, TravelSegmento $entity) {
                // Sin `@var`: el getter ya declara `Collection<int, TravelSegmentoComponente>`.
                // La anotación que había aquí lo rebajaba a `iterable`, y con eso `isEmpty()`
                // —que es de `Collection`, no de `iterable`— pasaba a ser una llamada sin
                // respaldo en el tipo.
                $coleccion = $entity->getSegmentoComponentes();
                if ($coleccion->isEmpty()) return '<span class="badge bg-light text-muted border">Sin logística</span>';

                $html = '<div class="d-flex flex-column gap-1" style="font-size: 11px; min-width: 250px; max-height: 220px; overflow-y: auto; padding-right: 5px;">';
                foreach ($coleccion as $sc) {
                    $compName = $sc->getComponente() ? htmlspecialchars((string) $sc->getComponente()) : 'N/A';

                    // Rango horario: inicio – fin (o solo inicio, o "Horario BD" si no hay hora).
                    $horaIni = $sc->getHora() ? $sc->getHora()->format('H:i') : null;
                    $horaFin = $sc->getHoraFin() ? $sc->getHoraFin()->format('H:i') : null;
                    $horaTxt = $horaIni ? ($horaFin ? ($horaIni . ' – ' . $horaFin) : $horaIni) : 'Horario BD';

                    // Día del filtro (si aplica a un día concreto de la plantilla).
                    $diaTxt = $sc->getDia() !== null
                        ? sprintf(' <span class="badge bg-light text-dark border" style="font-size:0.85em;"><i class="fas fa-calendar-day text-muted"></i> Día %d</span>', $sc->getDia())
                        : '';

                    // Ícono pequeño del modo comercial (no crece la fila).
                    [$modoIcon, $modoColor] = match ($sc->getModo()) {
                        ComponenteModoEnum::INCLUIDO => ['fa-circle-check', 'text-success'],
                        ComponenteModoEnum::NO_INCLUIDO => ['fa-circle-xmark', 'text-danger'],
                        ComponenteModoEnum::CORTESIA => ['fa-gift', 'text-info'],
                        ComponenteModoEnum::REEMPLAZADO => ['fa-arrows-rotate', 'text-warning'],
                    };
                    $modoTxt = sprintf('<i class="fas %s %s ms-1" title="%s"></i>', $modoIcon, $modoColor, ucfirst($sc->getModo()->value));

                    $ctx = $sc->getItinerarioContexto() ? htmlspecialchars($sc->getItinerarioContexto()->getNombreInterno()) : 'Global';
                    $colorCtx = $sc->getItinerarioContexto() ? 'text-primary' : 'text-success';
                    $iconCtx = $sc->getItinerarioContexto() ? 'fa-filter' : 'fa-globe';
                    // Puntito rojo: su hora está promovida al horario de toda la excursión (servicio completo).
                    $dot = $sc->isHoraServicioCompleto()
                        ? '<span class="d-inline-block rounded-circle me-1 align-middle" style="width:8px;height:8px;background:#ef4444;" title="Hora de servicio completo (toda la excursión)"></span>'
                        : '';
                    $html .= sprintf('<div class="p-1 border rounded bg-white shadow-sm"><strong class="d-block text-truncate mb-1" style="max-width: 280px;" title="%s">%s%s%s</strong><span class="text-muted"><i class="far fa-clock"></i> %s</span>%s <span class="mx-1 text-muted">|</span> <span class="%s fw-bold" title="Contexto de Plantilla"><i class="fas %s"></i> %s</span></div>', $compName, $dot, $compName, $modoTxt, $horaTxt, $diaTxt, $colorCtx, $iconCtx, $ctx);
                }
                $html .= '</div>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 LECTURA — La cadena que decide si este segmento saldrá CON fotos.
        //
        // Reproduce en el panel la regla que corre en `pax` (`galeriaPorBloque`,
        // `docs/Cotizaciones.md` §6.t), porque hasta ahora no había ninguna pantalla que
        // contestara «¿este segmento va a salir con fotos?» y había que ir a mirar cuatro sitios:
        // la tarifa, el prestador, su bandera de visibilidad y el buzón de imágenes.
        //
        // El fallo que caza es MUDO: un prestador oculto no inyecta ni una imagen por muchas que
        // le subas, y no da error en ningún sitio.
        yield TextField::new('virtualCadenaFotos', '¿De dónde saldrán las fotos?')
            ->onlyOnDetail()
            ->formatValue(static function ($value, TravelSegmento $entity) {
                $propias = $entity->getImagenes()->count();

                if ($propias > 0) {
                    return sprintf(
                        '<div class="alert alert-success py-2 mb-0"><i class="fas fa-images"></i> '
                        . '<strong>Manda su galería propia</strong> (%d foto%s). '
                        . 'La regla 1 gana: no se promueve nada del prestador.</div>',
                        $propias,
                        $propias === 1 ? '' : 's',
                    );
                }

                $componentes = $entity->getSegmentoComponentes();

                if ($componentes->isEmpty()) {
                    return '<div class="alert alert-secondary py-2 mb-0">Sin componentes: este segmento '
                        . 'saldrá <strong>sin fotos</strong>.</div>';
                }

                $filas = [];
                $total = 0;

                foreach ($componentes as $sc) {
                    $nombre = $sc->getComponente() !== null
                        ? htmlspecialchars((string) $sc->getComponente())
                        : 'componente sin nombre';

                    $tarifa = $sc->getTarifaPredeterminada();

                    if ($tarifa === null) {
                        $filas[] = sprintf(
                            '<div class="mb-2"><strong>%s</strong><div class="ms-3 text-danger small">'
                            . '<i class="fas fa-triangle-exclamation"></i> Sin tarifa predeterminada: '
                            . 'no aporta prestador ni fotos.</div></div>',
                            $nombre,
                        );
                        continue;
                    }

                    $cadena = sprintf(
                        '<div class="ms-3 small">└ tarifa: <code>%s</code> — %s %s</div>',
                        htmlspecialchars((string) $tarifa->getNombreInterno()),
                        htmlspecialchars((string) $tarifa->getMonto()),
                        htmlspecialchars((string) ($tarifa->getMoneda()?->getId() ?? '')),
                    );

                    $prestador = $tarifa->getPrestador();

                    if ($prestador === null) {
                        $cadena .= '<div class="ms-4 small text-warning-emphasis">'
                            . '<i class="fas fa-triangle-exclamation"></i> Sin prestador.</div>';
                        $filas[] = sprintf('<div class="mb-2"><strong>%s</strong>%s</div>', $nombre, $cadena);
                        continue;
                    }

                    $visible = $prestador->isVisibleParaCliente();
                    $cadena .= sprintf(
                        '<div class="ms-4 small">└ presta: <strong>%s</strong> %s</div>',
                        htmlspecialchars((string) $prestador->getNombreComercial()),
                        $visible
                            ? '<span class="badge bg-success-subtle text-success-emphasis border">se muestra</span>'
                            : '<span class="badge bg-danger-subtle text-danger-emphasis border" '
                              . 'title="Sin esta bandera el normalizer no inyecta NINGUNA imagen">oculto</span>',
                    );

                    $buzon = $tarifa->getPrestadorServicio();

                    if ($buzon === null) {
                        $cadena .= '<div class="ms-5 small text-muted">└ sin servicio de prestador: '
                            . 'sus fotos tendrían que ir en el propio segmento.</div>';
                    } else {
                        $fotos = $buzon->getImagenes()->count();

                        if ($visible) {
                            $total += $fotos;
                        }

                        $cadena .= sprintf(
                            '<div class="ms-5 small">└ buzón: «%s» %s</div>',
                            htmlspecialchars((string) $buzon->getNombre()),
                            $fotos > 0
                                ? sprintf('<span class="badge bg-light text-dark border">%d fotos</span>', $fotos)
                                : '<span class="badge bg-warning-subtle text-warning-emphasis border">0 fotos</span>',
                        );
                    }

                    $filas[] = sprintf('<div class="mb-2"><strong>%s</strong>%s</div>', $nombre, $cadena);
                }

                $veredicto = $total > 0
                    ? sprintf(
                        '<div class="alert alert-success py-2 mb-2"><i class="fas fa-images"></i> '
                        . 'Saldrá con <strong>%d foto%s</strong> promovida%s del prestador.</div>',
                        $total,
                        $total === 1 ? '' : 's',
                        $total === 1 ? '' : 's',
                    )
                    : '<div class="alert alert-warning py-2 mb-2"><i class="fas fa-triangle-exclamation"></i> '
                      . 'Hoy saldría <strong>sin ninguna foto</strong>.</div>';

                return $veredicto . implode('', $filas);
            })
            ->renderAsHtml()
            ->setColumns(12);

        // 🔥 ESCRITURA (Campo Real)
        yield CollectionField::new('segmentoComponentes', 'Componentes Logísticos Vinculados')
            ->onlyOnForms()
            ->useEntryCrudForm(TravelSegmentoComponenteCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelSegmentoComponente())
            ->setColumns(12);

        // 🔥 NUEVO: LECTURA — Galería con thumbnails (Liip) + modal
        yield TextField::new('virtualGaleria', 'Galería de Fotos')
            ->hideOnForm()
            ->formatValue(fn ($value, $entity) => $this->renderGaleriaThumbnails(
                $entity->getImagenes(),
                $entity,
                $this->uploadPath,
                'galeria-segmento',
            ))
            ->renderAsHtml();

        // ESCRITURA (Campo Real, sin thumbnails, formulario CRUD normal)
        yield CollectionField::new('imagenes', 'Galería de Fotos')
            ->onlyOnForms()
            ->useEntryCrudForm(TravelSegmentoImagenCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelSegmentoImagen())
            ->setColumns(12);

        yield FormField::addPanel('Contenido Introductorio y Notas Específicas')->setIcon('fa fa-book-open');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualNotas', 'Intros y tips')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $notas = $entity->getNotas();
                if ($notas->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin notas vinculadas</span>';

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($notas as $nota) {
                    $nombre = htmlspecialchars((string) $nota);
                    $html .= sprintf('<li class="px-2 py-1 mb-1 bg-light border rounded small text-truncate" title="%s" style="display: block;"><i class="fas fa-sticky-note text-warning" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark">%s</span></li>', $nombre, $nombre);
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield AssociationField::new('notas', 'Intros y tips')
            ->setFormTypeOptions(['by_reference' => false, 'multiple' => true])
            ->setHelp('Selecciona la Historia (Intro) notas, recomendaciones o tips para este segmento.')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);
    }
}