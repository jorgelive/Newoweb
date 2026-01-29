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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventAssignmentActivityCrudController.
 * Gestiona el catálogo de actividades usando IDs naturales (Códigos).
 */
class PmsEventAssignmentActivityCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventAssignmentActivity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Actividad de Asignación')
            ->setEntityLabelInPlural('Catálogo de Actividades')
            ->setSearchFields(['id', 'nombre', 'rol'])
            // ✅ Priorizamos el nuevo campo 'orden' para la visualización
            ->setDefaultSort(['orden' => 'ASC', 'nombre' => 'ASC'])
            ->setEntityPermission(Roles::MAESTROS_SHOW);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Definición de Actividad')->setIcon('fa fa-tasks');

        /**
         * ✅ ID NATURAL (Código)
         * Al ser Natural Key (strategy: NONE), el ID debe ser ingresado manualmente en NEW
         * pero bloqueado en EDIT para no romper la integridad referencial.
         */
        yield TextField::new('id', 'Código Técnico (ID)')
            ->setHelp('Ej: CLEAN_OUT, MAINTENANCE. No se puede cambiar después de creado.')
            ->setColumns(6)
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT)
            // En index lo mostramos como un badge técnico
            ->formatValue(fn($value) => sprintf('<code class="text-primary">%s</code>', $value));

        yield TextField::new('nombre', 'Nombre de la Actividad')
            ->setHelp('Ej: Limpieza de Salida, Inspección de Seguridad.')
            ->setColumns(6);

        /**
         * ✅ NUEVO: Campo de Orden
         */
        yield IntegerField::new('orden', 'Prioridad/Orden')
            ->setHelp('Define la posición en las listas (Menor número = primero).')
            ->setColumns(3);

        /**
         * ✅ Asignación de Rol
         */
        yield ChoiceField::new('rol', 'Rol Responsable')
            ->setChoices(Roles::getChoices('CAMPO'))
            ->renderAsBadges()
            ->setRequired(true)
            ->setHelp('¿Qué perfil de usuario realiza esta tarea?')
            ->setColumns(9);

        // ✅ Auditoría (CreatedAt / UpdatedAt del TimestampTrait)
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última actualización')
            ->onlyOnDetail();
    }
}