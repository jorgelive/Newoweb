<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
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
 *
 * @extends BaseCrudController<PmsPagoFinanciero>
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

        // ⚠️ La etiqueta decía «Monto (cobrado al cliente)», que es justo lo que NO es: `monto`
        // es el NETO, lo que abona la deuda. Lo cobrado sale abajo, en `montoTotalCobrado`.
        // Con la etiqueta vieja, un pago con tarjeta se tecleaba con los 105.50 del POS y la
        // reserva quedaba con 5.50 de más abonados.
        yield NumberField::new('monto', 'Monto NETO (abona a la deuda)')
            ->setNumDecimals(2)
            ->setHelp('Sin el recargo del medio de pago. Con tarjeta: si se pasaron 105.50 por el POS al 5.5%, aquí van 100.00.')
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

        // Quien RECIBIÓ el dinero, no quien rellena este formulario (§11.5.1). Se limita a
        // los `ROLE_COBRADOR` con la misma consulta que el panel y el agente; sin el filtro,
        // el desplegable ofrecería la lista entera de usuarios del sistema.
        yield AssociationField::new('cobrador', 'Cobrado por')
            ->setHelp('Quién recibió el dinero de manos del huésped. Vacío en transferencias y en los depósitos automáticos de las OTA.')
            ->setQueryBuilder(
                static fn (QueryBuilder $qb) => $qb
                    ->andWhere('entity.roles LIKE :rolCobrador')
                    ->setParameter('rolCobrador', '%"' . Roles::COBRADOR . '"%')
                    ->orderBy('entity.firstname', 'ASC')
            )
            ->setColumns(4);

        yield TextField::new('referencia', 'Referencia / Nº Operación')
            ->setColumns(4);

        yield TextareaField::new('notas', 'Notas')
            ->hideOnIndex();

        // Faltaba: sin verlo, un depósito automático de OTA parece un pago manual y alguien
        // lo «corrige» sin saber que el sistema lo mantiene cuadrado solo (§12.8). Es de sólo
        // lectura porque lo gestiona PmsPagoOtaAutomaticoService, no el operador.
        yield BooleanField::new('esAutomatico', 'Depósito automático de OTA')
            ->setHelp('Lo genera y mantiene el sistema para Airbnb/VRBO. Se apaga solo si tocas importe, fecha o medio.')
            ->setFormTypeOption('disabled', true)
            ->hideOnIndex();
    }
}
