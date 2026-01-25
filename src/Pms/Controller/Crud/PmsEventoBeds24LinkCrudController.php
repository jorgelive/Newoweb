<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoBeds24Link;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

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

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Beds24 Link')
            ->setEntityLabelInPlural('Beds24 Links')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields([
                'id',
                'beds24BookId',
                'status',
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id');

        $evento = AssociationField::new('evento', 'Evento')
            ->setRequired(true)
            ->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW);

        /**
         * ✅ Reserva (SIN __toString, SIN virtual fake property)
         * Usamos property path real: evento.reserva
         * y formateamos: "id: XXXX, master: YYYYY"
         */
        $reservaTxt = TextField::new('evento.reserva', 'Reserva')
            ->setSortable(false)
            ->formatValue(static function ($value) {
                // $value aquí es la Reserva (o null) gracias a "evento.reserva"
                if ($value === null) {
                    return null;
                }

                $rid = method_exists($value, 'getId') ? $value->getId() : null;
                $master = method_exists($value, 'getBeds24MasterId') ? $value->getBeds24MasterId() : null;

                $ridTxt = $rid !== null ? (string) $rid : '?';
                $masterTxt = ($master !== null && $master !== '') ? (string) $master : '-';

                return sprintf('id: %s, master: %s', $ridTxt, $masterTxt);
            });

        /**
         * ✅ Cliente (SIN __toString)
         * También usamos "evento.reserva" (property path real) y devolvemos texto:
         * "Nombre Apellido [ref]"
         */
        $clienteTxt = TextField::new('evento.reserva', 'Cliente')
            ->setSortable(false)
            ->formatValue(static function ($value) {
                if ($value === null) {
                    return null;
                }

                $nombreApellido = method_exists($value, 'getNombreApellido') ? $value->getNombreApellido() : null;
                $nombreApellido = $nombreApellido !== null ? trim((string) $nombreApellido) : '';

                $ref = method_exists($value, 'getReferenciaCanal') ? $value->getReferenciaCanal() : null;

                if ($ref) {
                    return $nombreApellido !== '' ? sprintf('%s [%s]', $nombreApellido, $ref) : sprintf('[%s]', $ref);
                }

                return $nombreApellido !== '' ? $nombreApellido : null;
            });

        $unidadBeds24Map = AssociationField::new('unidadBeds24Map', 'Map (Beds24)')
            ->setRequired(true)
            ->setFormTypeOption('disabled', $pageName !== Crud::PAGE_NEW);

        $beds24BookId = TextField::new('beds24BookId', 'Beds24 bookId')
            ->setHelp('Identificador técnico único de Beds24');

        $originLink = AssociationField::new('originLink', 'Origin Link (mirror)')
            ->setRequired(false);

        $status = ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Active' => PmsEventoBeds24Link::STATUS_ACTIVE,
                'Detached' => PmsEventoBeds24Link::STATUS_DETACHED,
                'Pending delete' => PmsEventoBeds24Link::STATUS_PENDING_DELETE,
                'Pending move' => PmsEventoBeds24Link::STATUS_PENDING_MOVE,
                'Synced deleted' => PmsEventoBeds24Link::STATUS_SYNCED_DELETED,
            ])
            ->renderExpanded(false)
            ->renderAsBadges([
                PmsEventoBeds24Link::STATUS_ACTIVE => 'success',
                PmsEventoBeds24Link::STATUS_DETACHED => 'secondary',
                PmsEventoBeds24Link::STATUS_PENDING_DELETE => 'warning',
                PmsEventoBeds24Link::STATUS_PENDING_MOVE => 'warning',
                PmsEventoBeds24Link::STATUS_SYNCED_DELETED => 'danger',
            ]);

        $lastSeenAt = DateTimeField::new('lastSeenAt', 'Last seen at')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $deactivatedAt = DateTimeField::new('deactivatedAt', 'Deactivated at')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $created = DateTimeField::new('created', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $evento,
                $reservaTxt,
                $clienteTxt,
                $unidadBeds24Map,
                $beds24BookId,
                $originLink,
                $status,
                $lastSeenAt,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Principal')->setIcon('fa fa-link'),
                $id,
                $evento,
                $reservaTxt,
                $clienteTxt,
                $unidadBeds24Map,
                $beds24BookId,
                $originLink,
                $status,

                FormField::addPanel('Proceso')->setIcon('fa fa-cogs'),
                $lastSeenAt,
                $deactivatedAt,

                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $created,
                $updated,
            ];
        }

        // FORM (new/edit) -> NO metemos Reserva/Cliente aquí, porque no son campos editables del Link
        return [
            FormField::addPanel('Principal')->setIcon('fa fa-link'),
            $evento,
            $unidadBeds24Map,
            $beds24BookId,
            $originLink,
            $status,

            FormField::addPanel('Proceso')->setIcon('fa fa-cogs')->renderCollapsed(),
            $lastSeenAt,
            $deactivatedAt,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $created,
            $updated,
        ];
    }
}