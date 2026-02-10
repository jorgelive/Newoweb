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
            ->add('beds24Config')
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
        $id = TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        $idFull = TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        $beds24Config = AssociationField::new('beds24Config', 'Configuración Beds24')
            ->setRequired(true);

        $pmsUnidad = AssociationField::new('pmsUnidad', 'Unidad PMS')
            ->setRequired(true);

        // ✅ Aquí seleccionas "Saphy" o "Inti" (que ahora son globales)
        $virtualEstablecimiento = AssociationField::new('virtualEstablecimiento', 'Establecimiento Virtual (Agrupación)')
            ->setHelp('Define la identidad comercial (Ej: Saphy, Inti) y si es principal.')
            ->setRequired(false);

        $beds24PropertyId = IntegerField::new('beds24PropertyId', 'Beds24 Property ID')
            ->setRequired(false)
            ->setHelp('PropertyId real de Beds24 asociado a este mapeo.');

        $beds24RoomId = IntegerField::new('beds24RoomId', 'Beds24 Room ID')
            ->setRequired(true);

        $beds24UnitId = IntegerField::new('beds24UnitId', 'Beds24 Unit ID (opcional)')
            ->setRequired(false)
            ->setHelp('Solo si Beds24 usa unidades físicas específicas.');

        $channelPropId = TextField::new('channelPropId', 'Channel Prop ID (Virtual)')
            ->setRequired(false)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('disabled', true)
            ->setHelp('Heredado del Establecimiento Virtual.')
            ->hideOnForm();

        $nota = TextField::new('nota', 'Observaciones Técnicas')->setRequired(false);

        $activo = BooleanField::new('activo', 'Mapeo Activo')
            ->renderAsSwitch(true);

        $esPrincipal = BooleanField::new('esPrincipal', 'Es Principal (Virtual)')
            ->setHelp('Determinado por el Establecimiento Virtual asignado.')
            ->renderAsSwitch(false)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('disabled', true)
            ->hideOnForm();

        $createdAt = DateTimeField::new('createdAt', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $pmsUnidad,
                $virtualEstablecimiento,
                $beds24Config,
                $esPrincipal,
                $beds24PropertyId,
                $beds24RoomId,
                $beds24UnitId,
                $activo,
            ];
        }

        return [
            FormField::addPanel('Relación Técnica')->setIcon('fa fa-link'),
            $idFull->onlyOnDetail(),
            $beds24Config,
            $pmsUnidad,
            $virtualEstablecimiento,
            $beds24PropertyId,
            $beds24RoomId,
            $beds24UnitId,

            $channelPropId->onlyOnDetail(),
            $nota,

            FormField::addPanel('Configuración de Sincronización')->setIcon('fa fa-flag'),
            $activo,
            $esPrincipal->onlyOnDetail(),

            FormField::addPanel('Auditoría de Registro')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->hideWhenCreating(),
            $updatedAt->hideWhenCreating(),
        ];
    }
}