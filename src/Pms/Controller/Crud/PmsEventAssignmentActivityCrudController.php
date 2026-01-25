<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventAssignmentActivity;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Maestro de Actividades para el Calendario del PMS.
 * Define qué tareas se pueden asignar y qué rol de campo es el responsable.
 */
class PmsEventAssignmentActivityCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsEventAssignmentActivity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Actividad de Asignación')
            ->setEntityLabelInPlural('Maestro: Actividades de Campo')
            ->setSearchFields(['nombre', 'codigo', 'rol'])
            ->setDefaultSort(['nombre' => 'ASC'])
            // Aseguramos que solo los que tienen permiso de Maestros puedan entrar
            ->setEntityPermission(Roles::MAESTROS_SHOW);
    }

    /**
     * Configuración de acciones y permisos por fila.
     */
    public function configureActions(Actions $actions): Actions
    {
        // El borrado de maestros es delicado, lo limitamos al rol DELETE
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // ID: Solo visible en el detalle o índice
        yield IdField::new('id')->hideOnForm();

        // NOMBRE: Nombre descriptivo de la tarea
        yield TextField::new('nombre', 'Nombre de la Actividad')
            ->setHelp('Ej: Limpieza de Salida, Check-in Presencial, Mantenimiento de Termas.')
            ->setColumns(6);

        /**
         * CÓDIGO: Identificador técnico (slug).
         * Se genera automáticamente del nombre pero se puede editar manualmente.
         */
        yield SlugField::new('codigo', 'Código Técnico')
            ->setTargetFieldName('nombre')
            ->setHelp('Se usa internamente para procesos automáticos. Evitar cambiar si ya existen tareas creadas.')
            ->setColumns(6);

        /**
         * ROL: Responsable de ejecutar esta actividad.
         * Filtramos para mostrar solo los roles de "CAMPO" (Limpieza, Conductor, Guía, etc.)
         */
        yield ChoiceField::new('rol', 'Personal Responsable')
            ->setChoices(Roles::getChoices('CAMPO'))
            ->renderAsBadges()
            ->setRequired(true)
            ->setHelp('¿Quién debe realizar esta tarea por defecto?')
            ->setColumns(6);
    }
}