<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaPerfilEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class TravelTarifaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelTarifa::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Tarifa')
            ->setEntityLabelInPlural('Tarifario Maestro')
            ->setSearchFields(['id', 'nombreInterno', 'monto'])
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
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Identificación y Costo')->setIcon('fa fa-tag');

        yield AssociationField::new('componente', 'Componente Logístico')->setColumns(6);
        yield TextField::new('nombreInterno', 'Referencia Interna')->setColumns(6);

        yield AssociationField::new('moneda', 'Moneda')
            ->setColumns(4)
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true]);
        yield NumberField::new('monto', 'Costo Neto')
            ->setNumDecimals(2)
            ->setColumns(4);

        yield FormField::addPanel('Restricciones de Venta (Constraints)')->setIcon('fa fa-filter')
            ->setHelp('Si dejas estos campos vacíos, la tarifa funcionará como "Comodín" y aplicará para cualquier pasajero o modalidad.');

        yield ChoiceField::new('modalidad', 'Modalidad')
            ->setChoices(array_reduce(TarifaModalidadEnum::cases(), fn ($c, $e) => $c + [$e->name => $e], []))
            ->setRequired(false) // <--- ESTO PERMITE QUE QUEDE VACÍO
            ->setColumns(6);

        yield ChoiceField::new('procedencia', 'Mercado (Procedencia)')
            ->setChoices(array_reduce(TarifaProcedenciaEnum::cases(), fn ($c, $e) => $c + [$e->name => $e], []))
            ->setRequired(false) // <--- ESTO PERMITE QUE QUEDE VACÍO
            ->setColumns(6);

        yield IntegerField::new('edadMinima', 'Edad Mín.')->setRequired(false)->setColumns(3);
        yield IntegerField::new('edadMaxima', 'Edad Máx.')->setRequired(false)->setColumns(3);
        yield IntegerField::new('capacidadMinima', 'Cap. Mínima')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield IntegerField::new('capacidadMaxima', 'Cap. Máxima')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield FormField::addPanel('Traducciones del Costo (Opcional)')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título Visible al Cliente')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)
            ->setColumns(12);
    }
}