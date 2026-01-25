<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstadoPago;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class PmsEventoEstadoPagoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoEstadoPago::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)   // botón "Ver" en el listado
            ->add(Crud::PAGE_EDIT, Action::DETAIL);   // opcional: link a "Ver" desde edit

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de pago')
            ->setEntityLabelInPlural('Estados de pago')
            ->setDefaultSort([
                'orden' => 'ASC',
                'nombre' => 'ASC',
            ])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('codigo')
            ->add('nombre')
            ->add('color');
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->onlyOnIndex();

        $codigo = TextField::new('codigo', 'Código')
            ->setHelp('Código interno único (ej: sin-pago, pago-parcial, pago-total)');

        $nombre = TextField::new('nombre', 'Nombre');

        $color = TextField::new('color', 'Color (HEX)')
            ->setHelp('Formato: #RRGGBB (ej: #1A2B3C). Puedes pegar también "RRGGBB" y la entidad lo normaliza.')
            ->setFormTypeOption('required', false)
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#RRGGBB',
            ]);

        $orden = IntegerField::new('orden', 'Orden');

        $created = DateTimeField::new('created', 'Creado')
            ->setFormTypeOption('disabled', true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormTypeOption('disabled', true);

        // =======================
        // INDEX
        // =======================
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $codigo,
                $nombre,
                $color,
                $orden,
            ];
        }

        // =======================
        // DETAIL
        // =======================
        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                $id,
                $codigo,
                $nombre,
                $orden,
                $color,
                $created,
                $updated,
            ];
        }

        // =======================
        // NEW / EDIT
        // =======================
        return [
            FormField::addPanel('Definición'),
            $codigo,
            $nombre,
            $orden,

            FormField::addPanel('Visual'),
            $color,

            FormField::addPanel('Auditoría')->renderCollapsed(),
            $created,
            $updated,
        ];
    }
}