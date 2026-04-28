<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Cotizacion\Entity\Cotizacion;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Controlador de solo lectura para auditar las versiones de las cotizaciones emitidas.
 */
class CotizacionCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return Cotizacion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Versión de Cotización')
            ->setEntityLabelInPlural('Versiones Generadas')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }


    public function configureActions(Actions $actions): Actions
    {
        // 100% Solo Lectura
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('file', 'Expediente Padre')->setColumns(4);

        yield IntegerField::new('version', 'V°')->setColumns(2);

        yield DateTimeField::new('fechaExpiracion', 'Válido Hasta')->setColumns(3);

        yield TextField::new('monedaGlobal', 'Moneda')->setColumns(3);

        yield NumberField::new('totalCosto', 'Costo Neto')
            ->setNumDecimals(2)
            ->setColumns(4)
            ->setPermission('ROLE_ADMIN'); // Solo admins ven el costo real

        yield NumberField::new('totalVenta', 'Total Venta')
            ->setNumDecimals(2)
            ->setColumns(4);
    }
}