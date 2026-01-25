<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEstablecimiento;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

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
            ->add('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('timezone');
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->onlyOnDetail();

        $nombre = TextField::new('nombreComercial', 'Nombre comercial');
        $direccion = TextField::new('direccionLinea1', 'Dirección');
        $ciudad = TextField::new('ciudad', 'Ciudad');

        $pais = AssociationField::new('pais', 'País');

        $telefono = TextField::new('telefonoPrincipal', 'Teléfono');
        $email = TextField::new('emailContacto', 'Email de contacto');

        $horaCheckIn = TimeField::new('horaCheckIn', 'Hora Check-in');
        $horaCheckOut = TimeField::new('horaCheckOut', 'Hora Check-out');

        $timezone = TextField::new('timezone', 'Timezone')
            ->setHelp('Ejemplo: America/Lima');

        $created = DateTimeField::new('created', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true)
            ->onlyWhenUpdating();

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true)
            ->onlyWhenUpdating();

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $nombre,
                $ciudad,
                $pais,
                $horaCheckIn,
                $horaCheckOut,
                $timezone,
                DateTimeField::new('created', 'Creado')->setFormat('yyyy/MM/dd HH:mm'),
                DateTimeField::new('updated', 'Actualizado')->setFormat('yyyy/MM/dd HH:mm'),
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                $id,
                $nombre,
                $direccion,
                $ciudad,
                $pais,
                $telefono,
                $email,
                $horaCheckIn,
                $horaCheckOut,
                $timezone,
            ];
        }

        // FORM (new / edit)
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
            $created,
            $updated,
        ];
    }
}