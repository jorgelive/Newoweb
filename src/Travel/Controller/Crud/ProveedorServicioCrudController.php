<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationLongTextType;
use App\Panel\Form\Type\TranslationTextType;
use App\Security\Roles;
use App\Travel\Entity\ProveedorServicio;
use App\Travel\Entity\ProveedorServicioImagen;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class ProveedorServicioCrudController extends BaseCrudController
{
    /**
     * Define la entidad administrada por este controlador.
     *
     * @return string Retorna el FQCN de la entidad ProveedorServicio.
     */
    public static function getEntityFqcn(): string
    {
        return ProveedorServicio::class;
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
            ->setEntityLabelInSingular('Servicio de Proveedor')
            ->setEntityLabelInPlural('Servicios de Proveedores')
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
     */
    public function configureFields(string $pageName): iterable
    {
        $isEmbedded = $this->isEmbedded();
        if (!$isEmbedded){
            yield AssociationField::new('proveedor', 'Proveedor')
                ->autocomplete()
                ->setColumns(12)
                ->setHelp('Proveedor al que pertenece el servicio.');
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
            ->setColumns(12);

        yield CollectionField::new('descripcion', 'Descripción')
            ->setEntryType(TranslationLongTextType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        /* ====================================================================
         * COLECCIÓN ANIDADA DE IMÁGENES DEL SERVICIO
         * ==================================================================== */
        yield CollectionField::new('proveedorServicioImagenes', 'Galería de Imágenes del Servicio')
            ->onlyOnForms()
            ->setColumns(12)
            ->useEntryCrudForm(ProveedorServicioImagenCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new ProveedorServicioImagen());
    }
}