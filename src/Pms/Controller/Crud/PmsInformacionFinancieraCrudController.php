<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

/**
 * Cabecera financiera de una reserva. De sólo lectura: se crea/actualiza por la
 * sincronización de Beds24 (Camino D), no a mano.
 *
 * @extends BaseCrudController<PmsInformacionFinanciera>
 */
class PmsInformacionFinancieraCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsInformacionFinanciera::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Información Financiera')
            ->setEntityLabelInPlural('Información Financiera')
            ->setDefaultSort(['lastSyncedAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setMaxLength(40)->onlyOnDetail();

        yield AssociationField::new('reserva', 'Reserva');
        yield AssociationField::new('moneda', 'Moneda');

        yield NumberField::new('totalCargos', 'Total Cargos')->setNumDecimals(2);
        yield NumberField::new('totalPagos', 'Total Pagos')->setNumDecimals(2);
        yield NumberField::new('saldo', 'Saldo Pendiente')
            ->setHelp('Cargos - Pagos, en la moneda de esta cabecera.')
            ->setNumDecimals(2)
            ->onlyOnDetail();

        yield DateTimeField::new('lastSyncedAt', 'Última Sincronización')
            ->setFormat('yyyy/MM/dd HH:mm');

        yield AssociationField::new('cargos', 'Cargos')->onlyOnDetail();
        yield AssociationField::new('pagos', 'Pagos')->onlyOnDetail();
    }
}
