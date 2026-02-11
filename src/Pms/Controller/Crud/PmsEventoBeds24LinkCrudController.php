<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoBeds24LinkCrudController.
 * Gestiona los vínculos técnicos (Flat Structure: Principal vs Mirror).
 */
class PmsEventoBeds24LinkCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoBeds24Link::class;
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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Vínculo Beds24')
            ->setEntityLabelInPlural('Vínculos Beds24')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['id', 'beds24BookId', 'status']);
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. IDENTIDAD DEL VÍNCULO
        // ============================================================
        yield FormField::addPanel('Identidad del Vínculo')->setIcon('fa fa-link');

        // UUID: Corto en Index, Completo en Detalle
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(static fn($value) => substr((string)$value, 0, 8) . '...');

        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        yield TextField::new('beds24BookId', 'Beds24 Book ID')
            ->setHelp('Identificador técnico en la API de Beds24.');

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Active' => PmsEventoBeds24Link::STATUS_ACTIVE,
                'Detached' => PmsEventoBeds24Link::STATUS_DETACHED,
                'Pending delete' => PmsEventoBeds24Link::STATUS_PENDING_DELETE,
                'Pending move' => PmsEventoBeds24Link::STATUS_PENDING_MOVE,
                'Synced deleted' => PmsEventoBeds24Link::STATUS_SYNCED_DELETED,
            ])
            ->renderAsBadges([
                PmsEventoBeds24Link::STATUS_ACTIVE => 'success',
                PmsEventoBeds24Link::STATUS_DETACHED => 'secondary',
                PmsEventoBeds24Link::STATUS_PENDING_DELETE => 'warning',
                PmsEventoBeds24Link::STATUS_PENDING_MOVE => 'warning',
                PmsEventoBeds24Link::STATUS_SYNCED_DELETED => 'danger',
            ]);

        // ============================================================
        // 2. RELACIÓN (EVENTO Y MAPA)
        // ============================================================
        yield FormField::addPanel('Configuración de Enlace')->setIcon('fa fa-cogs');

        yield AssociationField::new('evento', 'Evento')
            ->setRequired(true)
            // Solo editable al crear, luego se bloquea para integridad
            ->setDisabled($pageName !== Crud::PAGE_NEW);

        // Campos informativos (Solo Detalle) calculados desde el Evento
        yield TextField::new('evento.reserva', 'Info Reserva')
            ->onlyOnDetail()
            ->formatValue(static function ($value) {
                if ($value === null) return null;
                $rid = method_exists($value, 'getId') ? $value->getId() : '?';
                $master = method_exists($value, 'getBeds24MasterId') ? $value->getBeds24MasterId() : '-';
                return sprintf('ID: %s | Master: %s', $rid, $master);
            });

        yield TextField::new('evento.reserva', 'Info Cliente')
            ->onlyOnDetail()
            ->formatValue(static function ($value) {
                if ($value === null) return null;
                $nombre = method_exists($value, 'getNombreApellido') ? $value->getNombreApellido() : '';
                return $nombre ?: 'Sin nombre';
            });

        yield AssociationField::new('unidadBeds24Map', 'Mapeo Beds24')
            ->setRequired(true)
            ->setDisabled($pageName !== Crud::PAGE_NEW);

        yield BooleanField::new('esPrincipal', 'Es Root?')
            ->setHelp('Si activo: Propietario del ID en Beds24. Si inactivo: Espejo (Mirror).')
            ->renderAsSwitch(false); // Switch en form, check en index

        // ============================================================
        // 3. TRAZABILIDAD
        // ============================================================
        yield FormField::addPanel('Trazabilidad')
            ->setIcon('fa fa-clock')
            ->renderCollapsed();

        yield DateTimeField::new('lastSeenAt', 'Visto')
            ->setDisabled(true);

        yield DateTimeField::new('deactivatedAt', 'Desactivado')
            ->setDisabled(true);

        // ============================================================
        // 4. AUDITORÍA (ESTÁNDAR)
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