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
     * Garantiza la coherencia de permisos con el módulo de maestros de Travel.
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
     * Mapea el nombre, la URL externa, los campos JSON traducibles y la galería de imágenes física.
     *
     * @param string $pageName Nombre de la página/contexto actual de EasyAdmin.
     * @return iterable Lista de configuraciones de campos de EasyAdmin.
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
         * CAMPOS JSON (MULTIDIOMA / ESTRUCTURADOS)
         * Se inyectan los FormTypes personalizados para gestionar el formato array JSON de la BD.
         * ==================================================================== */
        yield CollectionField::new('titulo', 'Título Comercial')
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
         * Se delega el renderizado de los campos al ProveedorServicioImagenCrudController
         * aplicando las configuraciones para la correcta sincronización bidireccional de Doctrine.
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