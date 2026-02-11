<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoEstadoPagoCrudController.
 * Maestro para la gestión de estados de pago.
 */
class PmsEventoEstadoPagoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoEstadoPago::class;
    }

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
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de Pago')
            ->setEntityLabelInPlural('Estados de Pago')
            ->setDefaultSort([
                'orden' => 'ASC',
                'nombre' => 'ASC',
            ])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('nombre')
            ->add('color');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. DEFINICIÓN
        // ============================================================
        yield FormField::addPanel('Definición')->setIcon('fa fa-tag');

        // ID: Solo editable al crear, deshabilitado al editar
        yield TextField::new('id', 'Código (ID)')
            ->setHelp('ID único del sistema (Natural Key). Ej: pagado, pago-parcial')
            ->setFormTypeOption('attr', ['placeholder' => 'Ej: confirmada', 'maxlength' => 50])
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName) // Deshabilitado en Edición
            ->setRequired(Crud::PAGE_NEW === $pageName) // Requerido en Creación
            ->hideOnIndex(); // Generalmente los IDs técnicos no se muestran en el listado si hay nombre

        yield TextField::new('nombre', 'Nombre');

        yield IntegerField::new('orden', 'Orden');

        // ============================================================
        // 2. VISUALIZACIÓN
        // ============================================================
        yield FormField::addPanel('Visualización')->setIcon('fa fa-palette');

        yield TextField::new('color', 'Color (HEX)')
            ->setHelp('Formato: #RRGGBB. Se usa si el Estado de Evento no fuerza su propio color.')
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#1A2B3C',
            ]);

        // ============================================================
        // 3. AUDITORÍA (ESTÁNDAR)
        // ============================================================
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}