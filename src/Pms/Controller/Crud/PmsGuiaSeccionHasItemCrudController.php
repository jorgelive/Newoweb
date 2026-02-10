<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Pms\Entity\PmsGuiaSeccionHasItem;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class PmsGuiaSeccionHasItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsGuiaSeccionHasItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Ítem de Sección')
            ->setEntityLabelInPlural('Ítems de Sección');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        // 1. El Ítem (Con buscador autocomplete por si tienes muchos)
        yield AssociationField::new('item', 'Contenido')
            ->setRequired(true)
            ->setColumns(8)
            ->autocomplete(); // 👈 Recomendado para buscar por Nombre Interno

        // 2. Orden
        yield IntegerField::new('orden', 'Orden')
            ->setColumns(2);

        // 3. Activo/Visible
        yield BooleanField::new('activo', 'Visible')
            ->setColumns(2)
            ->renderAsSwitch(false); // Switch false para que ocupe menos espacio en línea
    }
}