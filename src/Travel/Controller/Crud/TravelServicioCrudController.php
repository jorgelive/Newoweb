<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelServicio;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelServicioCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelServicio::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Servicio')
            ->setEntityLabelInPlural('Servicios');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Datos Generales')->setIcon('fa fa-info-circle');

        yield TextField::new('codigo', 'Código (SKU)')
            ->setColumns(4)
            ->setHelp('Ej: VINI-1D');

        yield TextField::new('nombreInterno', 'Nombre Operativo')
            ->setColumns(8);

        yield FormField::addPanel('Contenido Comercial')->setIcon('fa fa-bullhorn');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título de Venta')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield FormField::addPanel('Pool Logístico (La Bolsa)')->setIcon('fa fa-cubes');

        yield AssociationField::new('componentes', 'Componentes Disponibles')
            ->setFormTypeOptions([
                'by_reference' => false,
                'multiple' => true,
            ])
            ->setHelp('Añade aquí todos los componentes que este tour podría utilizar.')
            ->setColumns(12);
    }
}