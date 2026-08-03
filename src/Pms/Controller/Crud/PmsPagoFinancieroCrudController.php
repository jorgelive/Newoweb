<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Pagos efectivamente recibidos, registrados por nosotros (a diferencia de los cargos,
 * que vienen de Beds24). Permite alta/edición manual.
 */
class PmsPagoFinancieroCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsPagoFinanciero::class;
    }

    public function configureActions(Actions $actions): Actions
    {
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
            ->setEntityLabelInSingular('Pago')
            ->setEntityLabelInPlural('Pagos')
            ->setSearchFields(['referencia', 'notas'])
            ->setDefaultSort(['fechaPago' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setMaxLength(40)->onlyOnDetail();

        yield FormField::addPanel('Datos del Pago')->setIcon('fa fa-money-bill-wave');

        yield AssociationField::new('informacionFinanciera', 'Reserva (Info)')
            ->setColumns(6);

        yield ChoiceField::new('medioPago', 'Medio de Pago')
            ->setChoices([
                'Efectivo'                => PmsMedioPago::EFECTIVO,
                'Plin / Yape'             => PmsMedioPago::PLIN_YAPE,
                'Tarjeta de Crédito'      => PmsMedioPago::TARJETA_CREDITO,
                'Western Union'           => PmsMedioPago::WESTERN_UNION,
                'Transferencia Bancaria'  => PmsMedioPago::TRANSFERENCIA_BANCARIA,
                'PayPal'                  => PmsMedioPago::PAYPAL,
            ])
            ->setColumns(6);

        yield NumberField::new('monto', 'Monto (cobrado al cliente)')
            ->setNumDecimals(2)
            ->setColumns(4);

        yield AssociationField::new('moneda', 'Moneda')
            ->setHelp(
                $pageName === Crud::PAGE_EDIT
                    ? 'No editable: una vez registrado el pago, la moneda queda fija (evita romper la coherencia del rollup financiero).'
                    : 'Si se deja vacío se asume USD.'
            )
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT)
            ->setColumns(4);

        yield NumberField::new('tipoCambio', 'Tipo de Cambio (venta)')
            ->setNumDecimals(3)
            ->setHelp('Usado para valorizar pagos en soles.')
            ->setColumns(4);

        yield NumberField::new('comisionPorcentaje', 'Comisión %')
            ->setHelp('Porcentaje de recargo del medio de pago (5.5 en tarjeta de crédito).')
            ->setNumDecimals(2)
            ->setColumns(4);

        // Derivados: el neto es `monto`; esto es lo que se le cobra de verdad al huésped.
        yield NumberField::new('montoComision', 'Importe comisión')
            ->setNumDecimals(2)
            ->onlyOnDetail();

        yield NumberField::new('montoTotalCobrado', 'Total cobrado')
            ->setNumDecimals(2)
            ->onlyOnDetail();

        yield DateField::new('fechaPago', 'Fecha de Pago')
            ->setColumns(4);

        yield TextField::new('referencia', 'Referencia / Nº Operación')
            ->setColumns(4);

        yield TextareaField::new('notas', 'Notas')
            ->hideOnIndex();
    }
}
