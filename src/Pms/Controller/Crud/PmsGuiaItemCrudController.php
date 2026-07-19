<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
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
            ->setHelp('
            <div class="small text-muted mt-2" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #dee2e6;">
                ... (tu bloque de variables dinámicas igual) ...
            </div>
        ');

        yield TextField::new('icono', 'Icono (FontAwesome)')
            ->setHelp('Ej: fa-wifi, fa-utensils.')
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
}