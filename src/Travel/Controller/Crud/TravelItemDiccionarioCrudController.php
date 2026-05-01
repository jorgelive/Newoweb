<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelItemDiccionario;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class TravelItemDiccionarioCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelItemDiccionario::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Término de Diccionario')
            ->setEntityLabelInPlural('Diccionario Multilingüe')
            ->setSearchFields(['id', 'nombreInterno'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE); // o MAESTROS_DELETE si lo tienes definido
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nombreInterno', 'Nombre Interno')
            ->setColumns(12)
            ->setHelp('Identificador usado en el panel administrativo. Ej: "Boleto DDC Circuito 1"');

        yield FormField::addPanel('Contenido Multilingüe')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')
            ->onlyOnForms()
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')
            ->onlyOnForms()
            ->setColumns(6);

        yield CollectionField::new('titulo', 'Viñeta / Título')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('Ingresa el idioma base (Ej: Español) y el sistema traducirá los demás al guardar.');
    }
}