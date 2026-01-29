<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\Maestro\MaestroTipocambio;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * MaestroTipocambioCrudController.
 * Gestión del histórico de tasas de cambio (Compra/Venta).
 * Hereda de BaseCrudController y utiliza UUID v7 vía IdTrait.
 */
class MaestroTipocambioCrudController extends BaseCrudController
{
    /**
     * Mantenemos el constructor inyectando dependencias base.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MaestroTipocambio::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // ✅ Permisos específicos vinculados a Roles
        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tasa de Cambio')
            ->setEntityLabelInPlural('Historial de Cambios')
            ->setDefaultSort(['fecha' => 'DESC'])
            ->setSearchFields(['fecha', 'moneda.id']);
    }

    /**
     * Definición de campos.
     * Implementa lógica de BCMath para la visualización del promedio.
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Información de la Tasa')->setIcon('fa fa-exchange-alt');

        // ✅ UUID para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield DateField::new('fecha', 'Fecha de la Tasa')
            ->setColumns(6)
            ->setHelp('Fecha a la que corresponde la cotización.');

        yield AssociationField::new('moneda', 'Moneda Destino')
            ->setColumns(6)
            ->setRequired(true)
            ->setHelp('Generalmente USD para registros en Perú.');

        yield FormField::addPanel('Cotización Financiera')->setIcon('fa fa-calculator');

        yield NumberField::new('compra', 'Precio Compra')
            ->setNumDecimals(3)
            ->setColumns(4)
            ->setHelp('Ej: 3.750');

        yield NumberField::new('venta', 'Precio Venta')
            ->setNumDecimals(3)
            ->setColumns(4)
            ->setHelp('Ej: 3.780');

        /**
         * Lógica de cálculo (BCMath).
         * Estos métodos getPromedio() y getPromedioredondeado() están definidos en la entidad.
         */
        yield TextField::new('promedio', 'Promedio (Sistema)')
            ->onlyOnIndex()
            ->setHelp('Calculado mediante getPromedio() con BCMath.');

        yield TextField::new('promedioredondeado', 'Promedio Redondeado')
            ->onlyOnDetail()
            ->setHelp('Redondeo financiero a 2 decimales.');

        // ✅ Auditoría mediante TimestampTrait
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última actualización')
            ->onlyOnDetail();
    }
}