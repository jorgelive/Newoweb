<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\Maestro\MaestroPais;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * MaestroPaisCrudController.
 * Gestión de Países e integraciones técnicas (Machu Picchu, PeruRail, Consettur).
 * Registro de IDs Naturales (ISO2) y lógica de prioridad heredando de BaseCrudController.
 */
class MaestroPaisCrudController extends BaseCrudController
{
    /**
     * Mantenemos el constructor inyectando las dependencias base.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MaestroPais::class;
    }

    /**
     * Configuración de acciones y permisos.
     * Mantiene la visibilidad de detalle y restricciones granulares.
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
            ->setEntityLabelInSingular('País Global')
            ->setEntityLabelInPlural('Países Globales')
            ->setDefaultSort(['prioritario' => 'DESC', 'nombre' => 'ASC']);
    }

    /**
     * Definición de campos del formulario y listados.
     * Nota técnica: El ID es el ISO2. Se permite entrada manual solo en creación.
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Identificación Geográfica')->setIcon('fa fa-globe');

        // El ID es el ISO2. Se ingresa manualmente al crear.
        $id = TextField::new('id', 'Código ISO (ID)')
            ->setHelp('Código de 2 caracteres (Ej: PE, US, ES)')
            ->setFormTypeOption('attr', [
                'maxlength' => 2,
                'style' => 'text-transform:uppercase',
                'placeholder' => 'PE'
            ]);

        // Protegemos el ID natural en edición para no romper relaciones en cascada
        if (Crud::PAGE_EDIT === $pageName) {
            $id->setFormTypeOption('disabled', true);
        }

        yield $id;
        yield TextField::new('nombre', 'Nombre País');

        yield BooleanField::new('prioritario', 'Prioridad en Listas')
            ->setHelp('Aparece al inicio de los selectores (Ej: Perú al inicio).')
            ->renderAsSwitch(true);

        yield FormField::addPanel('Integraciones Externas')->setIcon('fa fa-plug');

        yield IntegerField::new('codigoMc', 'Cód. Cultura (MC)')
            ->setHelp('Identificador para boletos de Machu Picchu.')
            ->hideOnIndex();

        yield IntegerField::new('codigoPeruRail', 'Cód. PeruRail')
            ->hideOnIndex();

        yield IntegerField::new('codigoConsettur', 'Cód. Consettur')
            ->hideOnIndex();

        // Lógica de detalle para el código de ciudad de Consettur (MaestroPais::ISO_PERU)
        yield TextField::new('id', 'Cód. Ciudad (Consettur)')
            ->onlyOnDetail()
            ->formatValue(function ($value, $entity) {
                // 'PE' es el ID Natural para Perú en la arquitectura actual
                return $value === 'PE' ? '1610' : 'N/A';
            })
            ->setHelp('Código enviado automáticamente para pasajeros de este país.');
    }
}