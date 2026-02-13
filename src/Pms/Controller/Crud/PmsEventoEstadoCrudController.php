<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstado;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoEstadoCrudController.
 * Gestión de los estados lógicos de una reserva/evento.
 */
class PmsEventoEstadoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }


    public static function getEntityFqcn(): string
    {
        return PmsEventoEstado::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de Evento')
            ->setEntityLabelInPlural('Estados de Evento')
            ->setDefaultSort(['orden' => 'ASC', 'id' => 'ASC'])
            ->showEntityActionsInlined();
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

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('nombre')
            ->add('codigoBeds24')
            ->add('colorOverride');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. DEFINICIÓN
        // ============================================================
        yield FormField::addPanel('Definición')->setIcon('fa fa-tag');

        // ID Visual (Index) - Truncado
        yield TextField::new('id', 'ID')
            ->onlyOnIndex()
            ->formatValue(fn($v) => substr((string)$v, 0, 8) . '...');

        // ID Técnico (Forms/Detail)
        yield TextField::new('id', 'Código (ID)')
            ->hideOnIndex()
            ->setHelp('ID único del sistema (Natural Key). Ej: confirmada, bloqueo.')
            ->setFormTypeOption('attr', ['placeholder' => 'Ej: confirmada', 'maxlength' => 50])
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName) // Bloqueado en Edición
            ->setRequired(Crud::PAGE_NEW === $pageName); // Requerido en Creación

        yield TextField::new('nombre', 'Nombre Visible');

        yield TextField::new('codigoBeds24', 'Código Beds24')
            ->setHelp('Mapeo técnico para la API de Beds24.')
            ->setRequired(false);

        yield IntegerField::new('orden', 'Orden Visual')
            ->setHelp('Posicionamiento en listas y selectores.')
            ->setRequired(false);

        // ============================================================
        // 2. CONFIGURACIÓN VISUAL
        // ============================================================
        yield FormField::addPanel('Configuración Visual')->setIcon('fa fa-palette');

        yield TextField::new('color', 'Color (HEX)')
            ->setHelp('Ejemplo: #FFB300. Se usa para el renderizado del calendario.')
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#FFB300',
            ])
            ->setRequired(false);

        yield BooleanField::new('colorOverride', 'Prioridad de Color')
            ->setHelp('Si se activa, este color prevalece sobre el color del estado de pago.')
            ->renderAsSwitch(true);

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