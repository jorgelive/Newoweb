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
            ->hideOnForm();

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

        yield CollectionField::new('titulo', 'Título')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield TextField::new('galleryHelperVisual', false)
            ->setTemplatePath('panel/field/gallery_helper.html.twig')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('data', null)
            ->setFormTypeOption('block_prefix', 'gallery_helper')
            ->setFormTypeOptions(['required' => false, 'attr' => ['class' => 'd-none']])
            ->addCssClass('field-gallery-helper');

        yield CollectionField::new('descripcion', 'Cuerpo / Instrucciones')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12)
            ->setHelp('
                <div class="small text-muted mt-2" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #dee2e6;">
                    <strong class="text-primary"><i class="fas fa-magic"></i> Variables Dinámicas:</strong>
                    <p class="mb-2" style="font-size: 0.85em;">Copia y pega estos códigos. El sistema los reemplazará por los datos reales de la reserva.</p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-family: monospace; font-size: 1.1em;">
                        <div>
                            <div class="text-dark fw-bold mb-1">📅 Reserva & Info</div>
                            <div><code>{{ guest_name }}</code> : Nombre Huésped</div>
                            <div><code>{{ booking_ref }}</code> : Localizador</div>
                            <div><code>{{ unit_name }}</code> : Nombre Casita/Hab</div>
                            <div><code>{{ hotel_name }}</code> : Nombre Hotel</div>
                            <div class="mt-1"><code>{{ check_in }}</code> : Hora Entrada</div>
                            <div><code>{{ check_out }}</code> : Hora Salida</div>
                        </div>
                        <div>
                            <div class="text-dark fw-bold mb-1">🔐 Acceso & Seguridad</div>
                            <div><code>{{ door_code }}</code> : Código Puerta</div>
                            <div><code>{{ wifi_ssid }}</code> : Red WiFi</div>
                            <div><code>{{ wifi_pass }}</code> : Clave WiFi</div>
                        </div>
                    </div>
                </div>
            ');

        yield FormField::addPanel('Botón de Acción (Opcional)')->setIcon('fa fa-link');

        yield CollectionField::new('labelBoton', 'Texto Botón')
            ->setEntryType(TranslationTextType::class)
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