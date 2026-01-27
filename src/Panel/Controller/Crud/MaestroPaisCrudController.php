<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Oweb\Entity\MaestroPais;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Gestión de Países e integraciones (MC, PR, Consettur).
 */
class MaestroPaisCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MaestroPais::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // 1. Habilitar la acción de detalle (La lupa) que no viene por defecto [cite: 2026-01-14]
            ->add(Crud::PAGE_INDEX, Action::DETAIL)

            // 2. Definir permisos para cada acción usando tus constantes de Roles
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)

            // Si el usuario tiene ROL_WRITE, podrá ver el botón de "Nuevo" y "Editar"
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)

            // El botón de Borrar queda para los que tengan ROL_DELETE
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::RESERVAS_DELETE);
    }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('País Global')
            ->setEntityLabelInPlural('Países Globales')
            ->setDefaultSort(['prioritario' => 'DESC', 'nombre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Base');
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('nombre', 'Nombre País');
        yield TextField::new('iso2', 'ISO (PE, US)');

        yield BooleanField::new('prioritario', 'Prioridad en Listas')
            ->setHelp('Aparece al inicio de los selectores.')
            ->renderAsSwitch(true);

        yield FormField::addPanel('Integraciones Externas');
        yield IntegerField::new('codigomc', 'Cód. Cultura')->hideOnIndex();
        yield IntegerField::new('codigopr', 'Cód. PeruRail')->hideOnIndex();
        yield IntegerField::new('codigocon', 'Cód. Consettur')->hideOnIndex();

        yield TextField::new('iso2', 'Cód. Ciudad (Consettur)')
            ->onlyOnDetail()
            ->formatValue(function ($value, $entity) {
                // Solo mostramos el código 1610 si la entidad es Perú
                return $entity->getIso2() === MaestroPais::ISO_PERU
                    ? MaestroPais::CODIGO_CIUDAD_DEFAULT_COSETTUR_PERU
                    : 'N/A';
            })
            ->setHelp('Código enviado automáticamente para pasajeros de este país.');
    }
}