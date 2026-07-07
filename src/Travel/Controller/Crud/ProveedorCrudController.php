<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationLongTextType;
use App\Panel\Form\Type\TranslationTextType;
use App\Security\Roles;
use App\Travel\Entity\Proveedor;
use App\Travel\Entity\ProveedorImagen;
use App\Travel\Entity\ProveedorServicio;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class ProveedorCrudController extends BaseCrudController
{
    /**
     * Define la entidad administrada por este controlador.
     *
     * @return string
     */
    public static function getEntityFqcn(): string
    {
        return Proveedor::class;
    }

    /**
     * Configuración general del comportamiento del CRUD.
     *
     * @param Crud $crud
     * @return Crud
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Proveedor')
            ->setEntityLabelInPlural('Proveedores')
            ->setDefaultSort(['nombreComercial' => 'ASC']);
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
     * Configuración de los campos visibles en el panel.
     *
     * @param string $pageName
     * @return iterable
     */
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nombreComercial', 'Nombre Comercial')
            ->setColumns(6);

        yield TextField::new('razonSocial', 'Razón Social')
            ->setColumns(6);

        yield TelephoneField::new('telefono', 'Teléfono')
            ->setColumns(6);

        yield EmailField::new('email', 'Correo Electrónico')
            ->setColumns(6);

        yield UrlField::new('url', 'Sitio Web / URL Externa')
            ->setHelp('Enlace directo corporativo o sitio web del proveedor.')
            ->setColumns(12);

        /* ====================================================================
         * CAMPO VIRTUAL: RENDERIZADO OPTIMIZADO PARA LISTADOS (INDEX / DETAIL)
         * Extrae dinámicamente el título en español desde la estructura JSON.
         * ==================================================================== */
        yield TextField::new('virtualTitulo', 'Título')
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

        yield CollectionField::new('titulo', 'Título')
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
         * COLECCIÓN ANIDADA DE IMÁGENES
         * Se delega el renderizado de los campos al ProveedorImagenCrudController
         * y se configuran las opciones estrictas para la hidratación de Doctrine.
         * ==================================================================== */
        yield CollectionField::new('proveedorImagenes', 'Galería de Imágenes')
            ->onlyOnForms()
            ->setColumns(12)
            ->useEntryCrudForm(ProveedorImagenCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new ProveedorImagen());

        /* ====================================================================
         * COLECCIÓN ANIDADA DE SERVICIOS
         * Se delega el renderizado al ProveedorServicioCrudController para
         * gestionar las habitaciones/servicios directamente desde el proveedor.
         * ==================================================================== */
        yield CollectionField::new('proveedorServicios', 'Servicios / Habitaciones')
            ->onlyOnForms()
            ->setColumns(12)
            ->useEntryCrudForm(ProveedorServicioCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new ProveedorServicio());
    }
}