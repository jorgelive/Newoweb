<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Entity\Maestro\MaestroIdioma;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField; // ✅ Cambiado a Integer
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class MaestroIdiomaCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MaestroIdioma::class;
    }

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
            ->setEntityLabelInSingular('Idioma Global')
            ->setEntityLabelInPlural('Idiomas Globales')
            // ✅ Ahora el orden principal es la prioridad numérica
            ->setDefaultSort(['prioridad' => 'DESC', 'nombre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $id = TextField::new('id', 'ISO (ID)')
            ->setHelp('Código de 2 caracteres (Ej: es, en, pt)')
            ->setFormTypeOption('attr', [
                'maxlength' => 2,
                'placeholder' => 'es',
                'style' => 'text-transform:lowercase'
            ]);

        if (Crud::PAGE_EDIT === $pageName) {
            $id->setFormTypeOption('disabled', true);
        }

        yield $id;

        // Bandera (Emoji) - Útil para el selector que configuramos antes
        yield TextField::new('bandera', 'Bandera')
            ->setHelp('Emoji de la bandera (Win: Win+. / Mac: Ctrl+Cmd+Space)')
            ->setFormTypeOption('attr', ['placeholder' => '🇪🇸']);

        yield TextField::new('nombre', 'Nombre');

        // ✅ REEMPLAZO: De prioritario (bool) a prioridad (int)
        yield IntegerField::new('prioridad', 'Prioridad / Peso')
            ->setHelp('Define el orden en los formularios y si el idioma está activo para traducción (Prioridad > 0).')
            ->setColumns(4);

        yield DateTimeField::new('createdAt', 'Registrado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }
}