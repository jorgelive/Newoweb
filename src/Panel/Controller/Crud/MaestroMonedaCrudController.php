<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\Maestro\MaestroMoneda;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * MaestroMonedaCrudController.
 * Gestión de Monedas Globales (PEN, USD, EUR).
 * Basado en códigos ISO 4217 como identificadores naturales.
 */
class MaestroMonedaCrudController extends BaseCrudController
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
        return MaestroMoneda::class;
    }

    /**
     * Configuración de permisos y acciones.
     * Mantiene la acción de detalle y aplica restricciones según la clase Roles.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Moneda')
            ->setEntityLabelInPlural('Monedas')
            ->setSearchFields(['id', 'nombre'])
            ->setDefaultSort(['id' => 'ASC']);
    }

    /**
     * Definición de campos.
     * El ID es el código ISO (PEN, USD). Se ingresa manualmente al crear.
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Información de Moneda')->setIcon('fa fa-money-bill-wave');

        // El ID es el ISO Natural (USD, PEN).
        $id = TextField::new('id', 'Código ISO (ID)')
            ->setHelp('Código de 3 letras en mayúsculas (ISO 4217).')
            ->setFormTypeOption('attr', [
                'maxlength' => 3,
                'placeholder' => 'USD',
                'style' => 'text-transform:uppercase'
            ]);

        // Protegemos el ID en edición para no romper la integridad de las tarifas.
        if (Crud::PAGE_EDIT === $pageName) {
            $id->setFormTypeOption('disabled', true);
        }

        yield $id;
        yield TextField::new('nombre', 'Nombre de la Moneda')
            ->setHelp('Ejemplo: Dólar Estadounidense');

        yield TextField::new('simbolo', 'Símbolo')
            ->setHelp('Ejemplo: $ o S/');

        // ✅ Auditoría mediante TimestampTrait (createdAt, updatedAt)
        yield FormField::addPanel('Tiempos de Registro')->setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última Modificación')
            ->onlyOnDetail();
    }
}