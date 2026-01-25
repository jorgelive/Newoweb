<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsTarifaRango;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

final class PmsTarifaRangoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsTarifaRango::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarifa Rango')
            ->setEntityLabelInPlural('Tarifas Rango')
            ->setDefaultSort(['fechaInicio' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50)
            ->overrideTemplate('crud/index', 'panel/pms/pms_tarifa_rango/index.html.twig');;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('unidad')
            ->add('moneda')
            ->add('fechaInicio')
            ->add('fechaFin')
            ->add('minStay')
            ->add('importante')
            ->add('activo')
            ->add('peso')
            ->add('queues');
        // Nota: en EasyAdmin filtrar por "unidad.establecimiento" suele requerir filtro custom;
        // si lo necesitas, lo armamos luego con un Filter personalizado.
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->onlyOnIndex();

        // --- Rango ---
        $unidad = AssociationField::new('unidad', 'Unidad');
        $fechaInicio = DateField::new('fechaInicio', 'Inicio')->setFormat('yyyy/MM/dd');
        $fechaFin    = DateField::new('fechaFin', 'Fin')->setFormat('yyyy/MM/dd');

        // --- Precio ---
        $moneda = AssociationField::new('moneda', 'Moneda');

        // precio es decimal string en DB => NumberField (sin “can’t be converted”)
        $precio = NumberField::new('precio', 'Precio')
            ->setNumDecimals(2);

        $minStay = IntegerField::new('minStay', 'Min. stay')->setRequired(false);

        $importante = BooleanField::new('importante', 'Importante');
        $peso = IntegerField::new('peso', 'Peso')->setRequired(false);
        $activo = BooleanField::new('activo', 'Activo');

        // --- Relaciones (solo lectura) ---
        $queues = AssociationField::new('queues', 'Queue')
            ->setDisabled(true)
            ->onlyOnDetail();

        // --- Auditoría ---
        $created = DateTimeField::new('created', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setDisabled(true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setDisabled(true);

        // ===================== INDEX =====================
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $unidad,
                $moneda,
                $fechaInicio,
                $fechaFin,
                $minStay,
                $precio,
                $importante,
                $peso,
                $activo,
            ];
        }

        // ===================== NEW / EDIT / DETAIL =====================
        $isNew = (Crud::PAGE_NEW === $pageName);

        return [
            FormField::addPanel('Rango')->setIcon('fa fa-calendar'),
            $unidad,
            $fechaInicio,
            $fechaFin,

            FormField::addPanel('Precio')->setIcon('fa fa-money-bill'),
            $moneda,
            $precio,
            $minStay,
            $importante,
            $peso,
            $activo,

            FormField::addPanel('Proceso')->setIcon('fa fa-cogs'),
            $queues,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            ...($isNew ? [] : [$created, $updated]),
        ];
    }
}