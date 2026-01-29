<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEstablecimiento;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEstablecimientoCrudController.
 * Gestión de propiedades o casas principales del sistema.
 * Hereda de BaseCrudController y utiliza UUID v7.
 */
final class PmsEstablecimientoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEstablecimiento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Establecimiento')
            ->setEntityLabelInPlural('Establecimientos')
            ->setDefaultSort(['nombreComercial' => 'ASC'])
            ->showEntityActionsInlined();
    }

    /**
     * ✅ Configuración de acciones y seguridad mediante Roles.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('timezone');
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID para visualización técnica
        $id = TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        $nombre = TextField::new('nombreComercial', 'Nombre comercial');
        $direccion = TextField::new('direccionLinea1', 'Dirección');
        $ciudad = TextField::new('ciudad', 'Ciudad');

        // Relación con MaestroPais (ID Natural String 2)
        $pais = AssociationField::new('pais', 'País')
            ->setRequired(true);

        $telefono = TextField::new('telefonoPrincipal', 'Teléfono');
        $email = TextField::new('emailContacto', 'Email de contacto');

        $horaCheckIn = TimeField::new('horaCheckIn', 'Hora Check-in');
        $horaCheckOut = TimeField::new('horaCheckOut', 'Hora Check-out');

        $timezone = TextField::new('timezone', 'Zona Horaria')
            ->setHelp('Ejemplo: America/Lima');

        // ✅ Auditoría mediante TimestampTrait
        $createdAt = DateTimeField::new('createdAt', 'Registrado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Última Modificación')
            ->setFormat('yyyy/MM/dd HH:mm');

        // VISTA INDEX
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $nombre,
                $ciudad,
                $pais,
                $horaCheckIn,
                $horaCheckOut,
                $timezone,
                $createdAt->setLabel('Creado'),
                $updatedAt->setLabel('Actualizado'),
            ];
        }

        // VISTA DETALLE
        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Detalles de la Propiedad')->setIcon('fa fa-building'),
                $id,
                $nombre,
                $direccion,
                $ciudad,
                $pais,
                $telefono,
                $email,

                FormField::addPanel('Configuración Operativa')->setIcon('fa fa-clock'),
                $horaCheckIn,
                $horaCheckOut,
                $timezone,

                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $createdAt,
                $updatedAt,
            ];
        }

        // VISTAS FORMULARIO (NEW / EDIT)
        return [
            FormField::addPanel('Información general')->setIcon('fa fa-building'),
            $nombre,
            $direccion,
            $ciudad,
            $pais,

            FormField::addPanel('Contacto')->setIcon('fa fa-phone'),
            $telefono,
            $email,

            FormField::addPanel('Operación')->setIcon('fa fa-clock'),
            $horaCheckIn,
            $horaCheckOut,
            $timezone,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true),
        ];
    }
}