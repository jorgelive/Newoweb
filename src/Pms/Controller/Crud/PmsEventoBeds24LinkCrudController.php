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
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoBeds24LinkCrudController.
 * Gestiona los vínculos técnicos entre eventos del sistema y reservas en Beds24.
 * Hereda de BaseCrudController y utiliza UUID v7 con prioridad de Roles.
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

    /**
     * ✅ Configuración de acciones y seguridad.
     * Los permisos se aplican después del parent para garantizar prioridad absoluta.
     */
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
        // ✅ Manejo de UUID (IdTrait)
        $id = TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        $evento = AssociationField::new('evento', 'Evento')
            ->setRequired(true)
            ->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW);

        /**
         * ✅ Reserva (Formato personalizado mediante Property Path)
         */
        $reservaTxt = TextField::new('evento.reserva', 'Reserva')
            ->setSortable(false)
            ->formatValue(static function ($value) {
                if ($value === null) return null;

                $rid = method_exists($value, 'getId') ? $value->getId() : null;
                $master = method_exists($value, 'getBeds24MasterId') ? $value->getBeds24MasterId() : null;

                $ridTxt = $rid !== null ? (string) $rid : '?';
                $masterTxt = ($master !== null && $master !== '') ? (string) $master : '-';

                return sprintf('ID: %s | Master: %s', $ridTxt, $masterTxt);
            });

        /**
         * ✅ Cliente (Formato personalizado mediante Property Path)
         */
        $clienteTxt = TextField::new('evento.reserva', 'Cliente')
            ->setSortable(false)
            ->formatValue(static function ($value) {
                if ($value === null) return null;

                $nombreApellido = method_exists($value, 'getNombreApellido') ? $value->getNombreApellido() : '';
                $ref = method_exists($value, 'getReferenciaCanal') ? $value->getReferenciaCanal() : null;

                if ($ref) {
                    return $nombreApellido !== '' ? sprintf('%s [%s]', $nombreApellido, $ref) : sprintf('[%s]', $ref);
                }

                return $nombreApellido !== '' ? $nombreApellido : null;
            });

        $unidadBeds24Map = AssociationField::new('unidadBeds24Map', 'Mapeo Beds24')
            ->setRequired(true)
            ->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW);

        $beds24BookId = TextField::new('beds24BookId', 'Beds24 bookId')
            ->setHelp('Identificador técnico en la API de Beds24.');

        $originLink = AssociationField::new('originLink', 'Vínculo Origen (Mirror)')
            ->setRequired(false);

        $status = ChoiceField::new('status', 'Estado de Sincronización')
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

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $lastSeenAt = DateTimeField::new('lastSeenAt', 'Última Detección');
        $deactivatedAt = DateTimeField::new('deactivatedAt', 'Desactivado en');
        $createdAt = DateTimeField::new('createdAt', 'Creado');
        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado');

        // --- Lógica por Vista ---

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                TextField::new('id', 'UUID')->formatValue(fn($v) => substr((string)$v, 0, 8) . '...'),
                $evento,
                $reservaTxt,
                $clienteTxt,
                $beds24BookId,
                $status,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Relación de Identidad')->setIcon('fa fa-link'),
                $id,
                $evento,
                $reservaTxt,
                $clienteTxt,
                $unidadBeds24Map,
                $beds24BookId,
                $originLink,
                $status,

                FormField::addPanel('Trazabilidad de Proceso')->setIcon('fa fa-sync'),
                $lastSeenAt,
                $deactivatedAt,

                FormField::addPanel('Auditoría del Sistema')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $createdAt,
                $updatedAt,
            ];
        }

        return [
            FormField::addPanel('Principal')->setIcon('fa fa-link'),
            $evento,
            $unidadBeds24Map,
            $beds24BookId,
            $originLink,
            $status,

            FormField::addPanel('Control Temporal')->setIcon('fa fa-clock')->renderCollapsed(),
            $lastSeenAt->setFormTypeOption('disabled', true),
            $deactivatedAt->setFormTypeOption('disabled', true),

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true),
        ];
    }
}