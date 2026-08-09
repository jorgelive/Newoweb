<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Enum\PmsTipoCargo;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Conceptos financieros (invoiceItems) importados de Beds24. De sólo lectura: son verdad
 * histórica del canal, se crean/actualizan por la sincronización (Camino D).
 */
class PmsCargoFinancieroCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsCargoFinanciero::class;
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
            ->setEntityLabelInSingular('Cargo Financiero')
            ->setEntityLabelInPlural('Cargos Financieros')
            ->setSearchFields(['descripcion', 'beds24ItemId', 'beds24BookingId'])
            ->setDefaultSort(['fechaCreacionBeds24' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setMaxLength(40)->onlyOnDetail();

        yield AssociationField::new('informacionFinanciera', 'Reserva (Info)')->onlyOnDetail();

        yield ChoiceField::new('tipoCargo', 'Tipo')
            ->setChoices([
                'Alojamiento' => PmsTipoCargo::ALOJAMIENTO,
                'Limpieza'    => PmsTipoCargo::LIMPIEZA,
                'Servicio'    => PmsTipoCargo::SERVICIO,
                'Otro'        => PmsTipoCargo::OTRO,
            ])
            ->renderAsBadges([
                PmsTipoCargo::ALOJAMIENTO->value => 'primary',
                PmsTipoCargo::LIMPIEZA->value    => 'info',
                PmsTipoCargo::SERVICIO->value    => 'warning',
                PmsTipoCargo::OTRO->value        => 'secondary',
            ]);

        yield TextField::new('descripcion', 'Descripción')
            ->setHelp('Interna. La rellena Beds24 con lo que venga del canal; el huésped NO la ve.');

        // Lo que sí ve el huésped. Se escribe en español y el traductor automático rellena
        // los demás idiomas (AutoTranslate sobre `descripcionCliente`).
        yield TextareaField::new('descripcionClienteEs', 'Descripción para el huésped')
            ->setNumOfRows(2)
            ->setRequired(false)
            ->setHelp(
                'Opcional, y así debe quedarse: la mayoría de cargos se explican con su tipo. '
                . 'Rellénala solo cuando el importe necesite una explicación —un ajuste de cuadre, '
                . 'un extra pactado—. Aparece en el estado de cuenta del huésped y en el que le '
                . 'manda el asistente por WhatsApp. Se traduce sola a los demás idiomas.'
            );
        yield TextField::new('tipo', 'Tipo Beds24')->onlyOnDetail();
        yield IntegerField::new('subTipo', 'subType Beds24')->onlyOnDetail();

        yield NumberField::new('monto', 'Monto')->setNumDecimals(2);
        yield NumberField::new('totalLinea', 'Total Línea')->setNumDecimals(2);
        yield AssociationField::new('moneda', 'Moneda');
        yield NumberField::new('tipoCambio', 'Tipo de Cambio')->setNumDecimals(3)->onlyOnDetail();

        yield DateTimeField::new('fechaCreacionBeds24', 'Creado (Beds24)')
            ->setFormat('yyyy/MM/dd HH:mm');

        yield TextField::new('beds24ItemId', 'ID Item Beds24')->onlyOnDetail();
        yield TextField::new('beds24BookingId', 'ID Booking Beds24')->onlyOnDetail();
        yield TextField::new('beds24InvoiceId', 'ID Factura Beds24')->onlyOnDetail();
    }
}
