<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsUnidad;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PmsUnidadCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidad::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Unidad')
            ->setEntityLabelInPlural('Unidades')
            ->setDefaultSort(['nombre' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
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
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo')
            ->add('tarifaBaseActiva')
            ->add('tarifaBasePrecio')
            ->add('tarifaBaseMinStay')
            ->add('tarifaBaseMoneda')
            ->add('beds24Maps')
            ->add('tarifaQueues')
            ->add('pullQueueJobs');
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = (Crud::PAGE_NEW === $pageName);

        // --- Campos base ---
        $id = IdField::new('id')->onlyOnIndex();

        $establecimiento = AssociationField::new('establecimiento', 'Establecimiento');
        $nombre = TextField::new('nombre', 'Nombre');
        $codigoInterno = TextField::new('codigoInterno', 'Código interno')->setRequired(false);
        $capacidad = IntegerField::new('capacidad', 'Capacidad')->setRequired(false);

        $activo = BooleanField::new('activo', 'Activo');

        // --- Tarifa base (NO pueden estar en blanco) ---
        $tarifaBaseActiva = BooleanField::new('tarifaBaseActiva', 'Tarifa base activa');

        $tarifaBasePrecio = NumberField::new('tarifaBasePrecio', 'Precio base')
            ->setNumDecimals(2)
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        $tarifaBaseMinStay = IntegerField::new('tarifaBaseMinStay', 'Min. stay base')
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        $tarifaBaseMoneda = AssociationField::new('tarifaBaseMoneda', 'Moneda base')
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()])
            ->setFormTypeOption('placeholder', '')
            ->setFormTypeOption('attr', [
                'data-ea-widget' => 'ea-autocomplete',
                'data-ea-autocomplete-allow-clear' => '0',
            ]);
        ;

        // --- Relaciones / Proceso ---
        $beds24Maps = AssociationField::new('beds24Maps', 'Beds24 Maps')
            ->setRequired(false);

        $tarifaQueues = AssociationField::new('tarifaQueues', 'Tarifa Queues')
            ->setDisabled(true)
            ->onlyOnDetail();

        $pullQueueJobs = AssociationField::new('pullQueueJobs', 'Pull Queue Jobs')
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
                $nombre,
                $establecimiento,
                $codigoInterno,
                $capacidad,
                $tarifaBaseActiva,
                $tarifaBasePrecio,
                $tarifaBaseMinStay,
                $tarifaBaseMoneda,
                $activo,
                $beds24Maps,
            ];
        }

        // ===================== DETAIL =====================
        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('General')->setIcon('fa fa-home'),
                $establecimiento,
                $nombre,
                $codigoInterno,
                $capacidad,

                FormField::addPanel('Estado')->setIcon('fa fa-toggle-on'),
                $activo,

                FormField::addPanel('Tarifa base')->setIcon('fa fa-money-bill'),
                $tarifaBaseActiva,
                $tarifaBasePrecio,
                $tarifaBaseMinStay,
                $tarifaBaseMoneda,

                FormField::addPanel('Beds24')->setIcon('fa fa-link'),
                $beds24Maps,

                FormField::addPanel('Proceso')->setIcon('fa fa-cogs'),
                $tarifaQueues,
                $pullQueueJobs,

                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $created,
                $updated,
            ];
        }

        // ===================== NEW / EDIT =====================
        return [
            FormField::addPanel('General')->setIcon('fa fa-home'),
            $establecimiento,
            $nombre,
            $codigoInterno,
            $capacidad,

            FormField::addPanel('Estado')->setIcon('fa fa-toggle-on'),
            $activo,

            FormField::addPanel('Tarifa base')->setIcon('fa fa-money-bill'),
            $tarifaBaseActiva,
            $tarifaBasePrecio,
            $tarifaBaseMinStay,
            $tarifaBaseMoneda,

            FormField::addPanel('Beds24')->setIcon('fa fa-link'),
            $beds24Maps,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            ...($isNew ? [] : [$created, $updated]),
        ];
    }
}