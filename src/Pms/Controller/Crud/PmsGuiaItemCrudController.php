<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PmsGuiaItemCrudController extends AbstractCrudController
{
    public function __construct(
        #[Autowire('%pms.path.galeria_images%')]
        private readonly string $uploadPath,
        private readonly CacheManager $imagineCacheManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuiaItem::class;
    }

    // =========================================================================
    // ✅ SINCRONIZACIÓN MANUAL (EASYADMIN STANDARD)
    // =========================================================================

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsGuiaItem) {
            foreach ($entityInstance->getGaleria() as $foto) {
                if ($foto->getItem() === null) {
                    $foto->setItem($entityInstance);
                }
            }
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsGuiaItem) {
            foreach ($entityInstance->getGaleria() as $foto) {
                if ($foto->getItem() === null) {
                    $foto->setItem($entityInstance);
                }
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    // =========================================================================
    // CONFIGURACIÓN UI
    // =========================================================================

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Ítem de Contenido')
            ->setEntityLabelInPlural('Biblioteca de Ítems')
            ->setSearchFields(['id', 'nombreInterno', 'titulo', 'tipo'])
            ->setDefaultSort(['updatedAt' => 'DESC']);
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

    /**
     * Devuelve el texto en español (sin HTML) truncado, o un placeholder si está vacío.
     */
    private function renderTraduccionEs(?iterable $contenido, int $limite, string $vacio): string
    {
        $texto = '';
        if (is_iterable($contenido)) {
            foreach ($contenido as $item) {
                if (($item['language'] ?? null) === 'es') {
                    $texto = trim(strip_tags((string) ($item['content'] ?? '')));
                    break;
                }
            }
        }

        if ($texto === '') {
            return sprintf('<span class="text-muted small"><i class="fas fa-language"></i> %s</span>', htmlspecialchars($vacio));
        }

        $truncado = mb_strlen($texto) > $limite ? mb_substr($texto, 0, $limite) . '…' : $texto;
        return htmlspecialchars($truncado);
    }

    /**
     * Construye la URL del thumbnail vía LiipImagine para una imagen de la galería.
     */
    private function resolveThumbUrl(string $imageName, string $filterSet): string
    {
        $relativePath = ltrim($this->uploadPath, '/') . '/' . $imageName;

        return $this->imagineCacheManager->getBrowserPath($relativePath, $filterSet);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

        yield FormField::addPanel('Configuración')->setIcon('fa fa-cog');

        yield TextField::new('nombreInterno', 'Nombre Interno (Admin)')
            ->setHelp('Identificador para ti. Ej: "Wifi Lobby" o "Manual Cafetera"')
            ->setColumns(8);

        yield ChoiceField::new('tipo', 'Formato Visual')
            ->setChoices([
                '📄 Tarjeta (Texto + Video + Fotos)' => PmsGuiaItem::TIPO_TARJETA,
                '📸 Álbum (Solo Fotos)'              => PmsGuiaItem::TIPO_ALBUM,
                '⚠️ Aviso Importante'                => PmsGuiaItem::TIPO_AVISO,
            ])
            ->setRequired(true)
            ->setColumns(4);

        // Decide en qué de las dos guías aparece el ítem. Es el campo con más
        // consecuencias del formulario, así que va arriba y con el aviso de que
        // "público" significa indexable por cualquiera, sin reserva de por medio.
        yield ChoiceField::new('visibilidad', 'Quién lo ve')
            ->setChoices([
                PmsGuiaVisibilidad::Publico->getLabel()           => PmsGuiaVisibilidad::Publico,
                PmsGuiaVisibilidad::Cliente->getLabel()           => PmsGuiaVisibilidad::Cliente,
                PmsGuiaVisibilidad::ClienteConfirmado->getLabel() => PmsGuiaVisibilidad::ClienteConfirmado,
                PmsGuiaVisibilidad::SoloVentana->getLabel()       => PmsGuiaVisibilidad::SoloVentana,
            ])
            ->renderExpanded(false)
            ->setRequired(true)
            ->setColumns(6)
            ->setHelp(
                'Cuatro niveles, cada uno añade UNA condición al anterior. '
                . '<strong>Público</strong>: sale en el catálogo de la unidad, visible para cualquiera sin reserva '
                . '(es el único que aparece en el catálogo). '
                . '<strong>Cliente</strong>: cualquiera con el localizador, haya pagado o no — normas, cómo llegar. '
                . '<strong>Cliente confirmado</strong>: además, con pago confiable. '
                . '<strong>Solo en ventana</strong>: además, solo desde 24 h antes del check-in y hasta el check-out '
                . '(úsalo para códigos de puerta, caja fuerte y WiFi). '
                . 'Los dos últimos, si no se cumplen, NO desaparecen: el huésped ve el título con un candado '
                . 'y qué le falta para abrirlo.'
            );

        // Va aquí, junto a «Quién lo ve», y no abajo con el icono: es una decisión sobre el
        // ítem —cómo se llega a él—, no un detalle de presentación. Enterrado tras el icono
        // no lo encontraba nadie.
        yield TextareaField::new('agenteTerminos', '🔎 Cómo lo preguntan (para el asistente)')
            ->setHelp(
                'Palabras con las que el huésped pregunta por esto, <strong>separadas por comas</strong> '
                . 'y en los idiomas en que escriban. Sirven para que el asistente encuentre este '
                . 'ítem al responder por chat; no se le muestran a nadie.<br>'
                . 'Ej. para la ducha: <code>ducha, agua caliente, calentador, gas, temperatura, '
                . 'shower, hot water</code>.<br>'
                . 'Si lo dejas vacío se sigue buscando por el título y el texto, pero se encuentra peor.'
            )
            ->setNumOfRows(2)
            ->setColumns(6);

        // Estandariza lo que antes dependía del criterio del modelo: sobre este tema informa
        // pero no concede. La orden viaja con el contenido (ver ConsultarGuiaSkill::detalle()).
        yield BooleanField::new('agenteRequiereHumano', '🔔 Requiere confirmar con una persona')
            ->setHelp(
                'Marca los temas que el asistente puede EXPLICAR pero no CONCEDER: salida tardía, '
                . 'entrada temprana, servicios extra… Al responderlos avisará al equipo y dejará '
                . 'la conversación pendiente, en vez de dar por hecho que se puede.<br>'
                . '<strong>Ojo:</strong> márcalo aunque el texto ya explique las condiciones — es '
                . 'justo cuando la guía dice «sujeto a disponibilidad» cuando hace falta que '
                . 'alguien mire si esa noche está libre.'
            )
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield FormField::addPanel('Contenido Dinámico')
            ->setIcon('fa fa-align-left');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        yield TextField::new('virtualTitulo', 'Título')
            ->hideOnForm()
            ->formatValue(fn ($value, $entity) => $this->renderTraduccionEs($entity->getTitulo(), 60, 'Sin título en español'))
            ->renderAsHtml();

        yield CollectionField::new('titulo', 'Título')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield TextField::new('galleryHelperVisual', false)
            ->setTemplatePath('panel/field/gallery_helper.html.twig')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('data', null)
            ->setFormTypeOption('block_prefix', 'gallery_helper')
            ->setFormTypeOptions(['required' => false, 'attr' => ['class' => 'd-none']])
            ->addCssClass('field-gallery-helper');

        // 🔥 LECTURA — Contenido en ES truncado (index + detalle)
        yield TextField::new('virtualDescripcion', 'Contenido')
            ->hideOnForm()
            ->formatValue(fn ($value, $entity) => $this->renderTraduccionEs($entity->getDescripcion(), 120, 'Sin contenido'))
            ->renderAsHtml();

        // 🔥 ESCRITURA — solo formulario (mantiene el help de variables)
        yield CollectionField::new('descripcion', 'Cuerpo / Instrucciones')
            ->setEntryType(TranslationHtmlType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12)
            ->setHelp(self::ayudaPlaceholders());

        yield TextField::new('icono', 'Icono (FontAwesome)')
            ->setHelp(
                'Ej: fa-wifi, fa-utensils. '
                . '<strong>Ojo:</strong> <code>fa-circle-info</code>, <code>fa-images</code> y '
                . '<code>fa-location-dot</code> se usaron para decidir qué contenido sale al '
                . 'catálogo público (migración Version20260804150000). No los pongas en un ítem '
                . 'que no quieras publicar.'
            )
            ->setColumns(6);

        yield FormField::addPanel('Botón de Acción (Opcional)')->setIcon('fa fa-link');

        // 🔥 LECTURA — Texto del botón en ES (index + detalle)
        yield TextField::new('virtualLabelBoton', 'Texto Botón')
            ->hideOnForm()
            ->formatValue(fn ($value, $entity) => $this->renderTraduccionEs($entity->getLabelBoton(), 40, 'Sin botón'))
            ->renderAsHtml();

        // 🔥 ESCRITURA — solo formulario
        yield CollectionField::new('labelBoton', 'Texto Botón')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(8)
            ->setHelp('Ej: "Ver Mapa", "Abrir WhatsApp"');

        yield TextField::new('urlBoton', 'URL o Acción')
            ->setColumns(4)
            ->setHelp('Web: <code>https://...</code> | Tel: <code>tel:+51...</code> | Wsp: <code>https://wa.me/...</code>');

        yield FormField::addPanel('Galería de Fotos')
            ->setIcon('fa fa-images')
            ->setHelp('Sube fotos aquí para crear un carrusel o grilla.');

        // 🔥 NUEVO: LECTURA — thumbnails con Liip + modal, solo para el índice
        yield TextField::new('virtualGaleria', 'Fotos')
            ->onlyOnIndex()
            ->formatValue(function ($value, $entity) {
                $fotos = $entity->getGaleria();

                if ($fotos->isEmpty()) {
                    return '<span class="text-muted small"><i class="fas fa-images"></i> Sin fotos</span>';
                }

                $modalId = 'galeria-item-' . str_replace('-', '', (string) $entity->getId());

                $thumbsHtml = '<div class="d-flex flex-wrap gap-1" style="max-width: 260px;">';
                $modalItemsHtml = '';

                foreach ($fotos as $i => $foto) {
                    if (!$foto->getImageName()) {
                        continue;
                    }

                    $thumbUrl = $this->resolveThumbUrl($foto->getImageName(), 'pms_thumb_admin');
                    $fullUrl = $this->resolveThumbUrl($foto->getImageName(), 'pms_compress_initial');
                    $alt = htmlspecialchars(sprintf('Foto %d', $i + 1));

                    $thumbsHtml .= sprintf(
                        '<img src="%s" alt="%s" loading="lazy"
                             class="rounded border"
                             style="width: 42px; height: 42px; object-fit: cover; cursor: pointer;"
                             data-bs-toggle="modal" data-bs-target="#%s">',
                        htmlspecialchars($thumbUrl), $alt, $modalId
                    );

                    $modalItemsHtml .= sprintf(
                        '<div class="col-6 col-md-4 mb-3">
                            <img src="%s" alt="%s" class="img-fluid rounded shadow-sm w-100" style="object-fit: cover; max-height: 260px;">
                        </div>',
                        htmlspecialchars($fullUrl), $alt
                    );
                }

                $thumbsHtml .= '</div>';

                $modalHtml = sprintf(
                    '<div class="modal fade" id="%s" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h6 class="modal-title">Galería — %s</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">%s</div>
                                </div>
                            </div>
                        </div>
                    </div>',
                    $modalId,
                    htmlspecialchars((string) $entity),
                    $modalItemsHtml
                );

                return $thumbsHtml . $modalHtml;
            })
            ->renderAsHtml();

        // ESCRITURA (Campo Real, sin thumbnails, formulario CRUD normal)
        yield CollectionField::new('galeria', 'Fotos')
            ->hideOnIndex()
            ->useEntryCrudForm(PmsGuiaItemGaleriaCrudController::class)
            ->setFormTypeOption('prototype_data', new PmsGuiaItemGaleria())
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setColumns(12);

        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
    }

    /**
     * Chuleta de placeholders bajo el cuerpo del ítem.
     *
     * Vive en el formulario a propósito: quien escribe la guía no lee
     * docs/PmsGuiaHuesped.md, y sin esta referencia los placeholders se olvidan
     * o se inventan (y un `{{ codigo_puerta }}` mal escrito no falla: se queda
     * literal en pantalla delante del huésped).
     *
     * Si se añade un placeholder nuevo hay que tocar TRES sitios: el resolutor
     * que corresponda (PmsGuiaContexto o RichContentEngine), esta chuleta, y
     * docs/PmsGuiaHuesped.md §4.
     */
    private static function ayudaPlaceholders(): string
    {
        return <<<'HTML'
            <div class="small text-muted mt-2" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #dee2e6;">

                <strong>Datos — se sustituyen siempre</strong>
                <div style="columns:2; margin:6px 0 12px;">
                    <code>{{ unit_name }}</code> nombre de la unidad<br>
                    <code>{{ hotel_name }}</code> nombre del establecimiento<br>
                    <code>{{ host_name }}</code> anfitrión<br>
                    <code>{{ host_whatsapp }}</code> WhatsApp del anfitrión<br>
                    <code>{{ guest_name }}</code> nombre del huésped<br>
                    <code>{{ booking_ref }}</code> localizador<br>
                    <code>{{ check_in }}</code> / <code>{{ check_out }}</code> horas<br>
                    <code>{{ start_date }}</code> / <code>{{ end_date }}</code> fechas
                </div>

                <strong>Datos protegidos — solo dentro de la ventana</strong>
                <div style="margin:6px 0 12px;">
                    <code>{{ door_code }}</code>
                    <code>{{ safe_code }}</code>
                    <code>{{ keybox_main }}</code>
                    <code>{{ keybox_sec }}</code>
                    <div class="text-muted" style="margin-top:4px;">
                        Fuera de la ventana se sustituyen por «Disponible el …» / «Disponible al
                        confirmar». El valor real no sale del servidor.
                    </div>
                </div>

                <strong>Maquetación</strong>
                <div style="margin:6px 0 12px;">
                    <code>{{ video: URL }}</code> reproductor<br>
                    <code>{{ img: URL }}</code> imagen<br>
                    <code>{{ map: coordenadas }}</code> mapa<br>
                    <code>{{ widget: wifi }}</code> tarjeta de redes WiFi
                </div>

                <strong>Maquetación protegida — solo dentro de la ventana</strong>
                <div style="margin:6px 0 0;">
                    <code>{{ video_ventana: URL }}</code>
                    <code>{{ img_ventana: URL }}</code>
                    <div class="text-muted" style="margin-top:4px;">
                        Fuera de la ventana el huésped ve un marco con la forma del vídeo o la
                        imagen y el texto de disponibilidad en el centro. <strong>La URL no
                        se envía</strong>, así que tampoco se puede sacar del código de la página.
                        Úsalos para el vídeo de cómo abrir la puerta o la foto de la caja de llaves.
                    </div>
                </div>

                <div class="text-muted" style="margin-top:12px; padding-top:8px; border-top:1px solid #dee2e6;">
                    Se admiten espacios: <code>{{clave}}</code> y <code>{{ clave }}</code> valen igual.
                    Un placeholder mal escrito NO da error: se queda literal en la guía.
                </div>
            </div>
            HTML;
    }
}