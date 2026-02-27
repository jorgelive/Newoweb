<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsUnidadBeds24MapCrudController.
 */
final class PmsUnidadBeds24MapCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidadBeds24Map::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mapeo Beds24')
            ->setEntityLabelInPlural('Mapeos Beds24')
            ->setDefaultSort(['pmsUnidad' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
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

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('beds24PropertyId')
            ->add('pmsUnidad')
            ->add('virtualEstablecimiento')
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('activo')
            ->add('createdAt')
            ->add('updatedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. RELACIÓN TÉCNICA
        // ============================================================
        yield FormField::addPanel('Relación Técnica')->setIcon('fa fa-link');

        // ID Corto para el Index
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        // ID Completo para el Detalle
        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield AssociationField::new('pmsUnidad', 'Unidad PMS')
            ->setRequired(true);

        yield AssociationField::new('virtualEstablecimiento', 'Establecimiento Virtual')
            ->setHelp('Define la identidad comercial (Ej: Saphy, Inti) y si es principal.')
            ->setRequired(false);

        // ============================================================
        // 2. IDENTIFICADORES BEDS24
        // ============================================================
        yield IntegerField::new('beds24PropertyId', 'Beds24 Property ID')
            ->setRequired(false)
            ->setHelp('PropertyId real de Beds24 asociado a este mapeo.');

        yield IntegerField::new('beds24RoomId', 'Beds24 Room ID')
            ->setRequired(true);

        yield IntegerField::new('beds24UnitId', 'Beds24 Unit ID (Opcional)')
            ->setRequired(false)
            ->setHelp('Solo si Beds24 usa unidades físicas específicas.');

        yield TextField::new('channelPropId', 'Channel Prop ID (Virtual)')
            ->onlyOnDetail() // Solo ver en detalle
            ->setFormTypeOption('mapped', false)
            ->setHelp('Heredado del Establecimiento Virtual.');

        yield TextField::new('nota', 'Observaciones Técnicas')
            ->setRequired(false)
            ->hideOnIndex(); // Ocultar en la tabla para no saturar

        // ============================================================
        // 3. CONFIGURACIÓN Y ESTADO
        // ============================================================
        yield FormField::addPanel('Configuración de Sincronización')->setIcon('fa fa-flag');

        yield BooleanField::new('activo', 'Mapeo Activo')
            ->renderAsSwitch(true);

        yield BooleanField::new('esPrincipal', 'Es Principal (Virtual)')
            ->hideOnForm() // No editable manualmente, viene de lógica interna
            ->setHelp('Determinado por el Establecimiento Virtual asignado.')
            ->renderAsSwitch(false)
            ->setFormTypeOption('mapped', false);

        // ---------------------------------------------------------------------
        // PANEL: AUDITORÍA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true); // Visible pero readonly en form

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}