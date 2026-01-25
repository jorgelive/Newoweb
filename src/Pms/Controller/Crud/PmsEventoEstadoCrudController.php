<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstado;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class PmsEventoEstadoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoEstado::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de evento')
            ->setEntityLabelInPlural('Estados de evento')
            ->setDefaultSort(['orden' => 'ASC', 'codigo' => 'ASC'])
            ->showEntityActionsInlined()
            ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)   // botón "Ver" en el listado
            ->add(Crud::PAGE_EDIT, Action::DETAIL);   // opcional: link a "Ver" desde edit

        return parent::configureActions($actions);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('codigo')
            ->add('nombre')
            ->add('codigoBeds24')
            ->add('colorOverride')
            ;
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->onlyOnIndex();

        $codigo = TextField::new('codigo', 'Código interno')
            ->setHelp('Identificador lógico del estado (único). Ej: confirmed, cancelled');

        $nombre = TextField::new('nombre', 'Nombre visible');

        $codigoBeds24 = TextField::new('codigoBeds24', 'Código Beds24')
            ->setHelp('Ej: confirmed, cancelled, pending, noshow')
            ->setRequired(false);

        $color = TextField::new('color', 'Color (HEX)')
            ->setHelp('Formato #RRGGBB. También se acepta RRGGBB sin #')
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#FFB300',
            ])
            ->setRequired(false);

        $colorOverride = BooleanField::new('colorOverride', 'Forzar color del estado')
            ->setHelp('Si está activo, el color del estado prevalece sobre el estado de pago');

        $orden = IntegerField::new('orden', 'Orden')
            ->setHelp('Orden visual en listados / calendario')
            ->setRequired(false);

        $created = DateTimeField::new('created', 'Creado')
            ->setFormTypeOption('disabled', true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormTypeOption('disabled', true);

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $codigo,
                $nombre,
                $codigoBeds24,
                $color,
                $colorOverride,
                $orden,
                $created,
                $updated,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                IdField::new('id'),
                $codigo,
                $nombre,
                $codigoBeds24,
                $color,
                $colorOverride,
                $orden,
            ];
        }

        // NEW / EDIT
        return [
            FormField::addPanel('Definición')->setIcon('fa fa-tag'),
            $codigo,
            $nombre,
            $codigoBeds24,

            FormField::addPanel('Visual')->setIcon('fa fa-palette'),
            $color,
            $colorOverride,
            $orden,

            FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed(),
            $created,
            $updated,
        ];
    }
}