<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Pms\Entity\PmsGuia;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;

/**
 * Controlador CRUD para la gestión de Guías de Unidades.
 * Integra el control global de traducciones y la seguridad por roles del sistema.
 */
class PmsGuiaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsGuia::class;
    }

    /**
     * Configuración de la seguridad granular.
     * Implementa las restricciones de visualización, edición y borrado solicitadas.
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // Configuración de visualización (LECTURA)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)

            // Configuración de gestión (ESCRITURA) [cite: 2026-01-14]
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)

            // Configuración de eliminación (BORRADO) [cite: 2026-01-14]
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Guía')
            ->setEntityLabelInPlural('Guías')
            ->setSearchFields(['id', 'titulo', 'unidad.nombre'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // --- SECCIÓN DE CONFIGURACIÓN ---
        yield FormField::addPanel('Configuración General');

        yield IdField::new('id')->hideOnForm();

        yield AssociationField::new('unidad', 'Unidad / Departamento')
            ->setRequired(true)
            ->setHelp('Seleccione la unidad a la que pertenece esta guía.');

        yield BooleanField::new('activo', 'Guía Publicada')
            ->renderAsSwitch(true);

        // --- SECCIÓN DE TRADUCCIÓN (Trait Global) ---
        yield FormField::addPanel('Contenido y Traducción');

        yield BooleanField::new('ejecutarTraduccion', '¿Traducir campos automáticamente?')
            ->setHelp('Si se marca, al guardar se enviará el texto en español a Google Translate.')
            ->onlyOnForms()
            ->hideOnIndex()
            // Solo quienes pueden escribir pueden disparar la traducción [cite: 2026-01-14]
            ->setPermission(Roles::RESERVAS_WRITE);

        yield CollectionField::new('titulo', 'Títulos por Idioma')
            ->setHelp('Use el código de idioma como llave (ej: es, en, pt).')
            ->allowAdd()
            ->allowDelete();

        // --- SECCIÓN DE SECCIONES (Relación intermedia) ---
        yield FormField::addPanel('Estructura de la Guía');

        //
        yield CollectionField::new('guiaHasSecciones', 'Secciones Vinculadas')
            ->useEntryCrudForm() // Requisito para gestionar el 'orden' y 'activo' de la relación intermedia
            ->setHelp('Gestione las secciones y su orden específico para esta guía.');

        // --- SECCIÓN DE AUDITORÍA ---
        yield FormField::addPanel('Auditoría')->onlyOnDetail();

        yield DateTimeField::new('creado', 'Fecha de Creación')
            ->onlyOnDetail();

        yield DateTimeField::new('modificado', 'Última Modificación')
            ->onlyOnDetail();
    }
}