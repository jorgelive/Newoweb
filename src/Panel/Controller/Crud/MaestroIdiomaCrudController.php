<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Entity\MaestroIdioma;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Gestión de Idiomas Maestros para el núcleo de traducción.
 */
class MaestroIdiomaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MaestroIdioma::class;
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
            ->setEntityLabelInSingular('Idioma Global')
            ->setEntityLabelInPlural('Idiomas Globales')
            ->setDefaultSort(['prioritario' => 'DESC', 'nombre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('nombre', 'Nombre');
        yield TextField::new('codigo', 'ISO (es, en, pt)');

        yield BooleanField::new('prioritario', '¿Traducir Automáticamente?')
            ->setHelp('Activa el motor de Google Translate para este idioma.')
            ->renderAsSwitch(true);

        yield DateTimeField::new('creado', 'Registrado')->onlyOnDetail();
        yield DateTimeField::new('modificado', 'Actualizado')->onlyOnDetail();
    }
}