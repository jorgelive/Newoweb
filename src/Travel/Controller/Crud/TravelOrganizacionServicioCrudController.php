<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Controller\Trait\RenderGaleriaTrait;
use App\Panel\Form\Type\TranslationLongTextType;
use App\Panel\Form\Type\TranslationTextType;
use App\Security\Roles;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelOrganizacionServicioImagen;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends BaseCrudController<TravelOrganizacionServicio>
 */
class TravelOrganizacionServicioCrudController extends BaseCrudController
{

    use RenderGaleriaTrait;

    public function __construct(
        #[Autowire('%travel.path.proveedor_servicio_galeria%')]
        private readonly string $uploadPath,
        private readonly CacheManager $imagineCacheManager,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
    ) {
        parent::__construct($this->adminUrlGenerator, $this->requestStack);
    }

    protected function getImagineCacheManager(): CacheManager
    {
        return $this->imagineCacheManager;
    }

    /**
     * Define la entidad administrada por este controlador.
     *
     * @return string Retorna el FQCN de la entidad TravelOrganizacionServicio.
     */
    public static function getEntityFqcn(): string
    {
        return TravelOrganizacionServicio::class;
    }

    /**
     * Configuración general del comportamiento del CRUD para los servicios.
     *
     * @param Crud $crud
     * @return Crud
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Servicio de organización')
            ->setEntityLabelInPlural('Servicios de organizaciones')
            ->setDefaultSort(['nombre' => 'ASC']);
    }

    /**
     * Configuración de acciones, botones globales y permisos de acceso del CRUD.
     *
     * @param Actions $actions
     * @return Actions
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE);
    }

    /**
     * Configuración de los campos visibles y editables en el panel de administración.
     *
     * @param string $pageName
     * @return iterable
     *
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    public function configureFields(string $pageName): iterable
    {
        $isEmbedded = $this->isEmbedded();
        if (!$isEmbedded){
            yield AssociationField::new('organizacion', 'Organización')
                ->autocomplete()
                ->setColumns(12)
                ->setHelp('TravelOrganizacion al que pertenece el servicio.');
        }

        yield TextField::new('nombre', 'Nombre del Servicio')
            ->setHelp('Ejemplo: Habitación Doble Estándar, Tour Guiado Privado, etc.')
            ->setColumns(12);

        yield UrlField::new('url', 'Sitio Web / URL Externa')
            ->setHelp('Enlace directo a las especificaciones técnicas o micrositio del servicio.')
            ->setColumns(12);

        /* ====================================================================
         * CAMPO VIRTUAL: RENDERIZADO OPTIMIZADO PARA LISTADOS (INDEX / DETAIL)
         * Extrae dinámicamente el título en español desde la estructura JSON.
         * ==================================================================== */
        yield TextField::new('virtualTitulo', 'Título Comercial')
            ->setVirtual(true)
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

        /* ====================================================================
         * CAMPOS JSON (MULTIDIOMA / ESTRUCTURADOS) PARA FORMULARIOS
         * ==================================================================== */

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);
        yield CollectionField::new('titulo', 'Título Comercial (Traducciones)')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12)
            // Mismo criterio que el organización, y en cascada: sin título aquí, el servicio no
            // se muestra al cliente aunque su organización sí tenga el suyo.
            ->setHelp(
                'Sin título, este servicio no se le muestra al cliente. Y si el prestador '
                . 'tiene título pero el servicio no, tampoco se muestra el servicio: la '
                . 'ausencia de título es lo que oculta, no hay casilla que marcar.'
            );

        yield CollectionField::new('descripcion', 'Descripción')
            ->setEntryType(TranslationLongTextType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        /* ====================================================================
         * COLECCIÓN ANIDADA DE IMÁGENES DEL SERVICIO
         * ==================================================================== */
        yield TextField::new('virtualGaleria', 'Galería')
            ->onlyOnIndex()
            ->formatValue(fn ($value, $entity) => $this->renderGaleriaThumbnails(
                $entity->getImagenes(),
                $entity,
                $this->uploadPath,
                'galeria-provserv',
            ))
            ->renderAsHtml();

        yield CollectionField::new('imagenes', 'Galería de Imágenes del Servicio')
            ->onlyOnForms()
            ->setColumns(12)
            ->useEntryCrudForm(TravelOrganizacionServicioImagenCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new TravelOrganizacionServicioImagen());
    }
}