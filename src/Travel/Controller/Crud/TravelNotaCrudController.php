<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelNota;
use App\Travel\Enum\NotaTipoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelNotaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelNota::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Nota')
            ->setEntityLabelInPlural('Notas de itinerario');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Nota')->setIcon('fa fa-info-circle');

        yield TextField::new('nombreInterno', 'Nombre Administrativo (ID)')
            ->setHelp('Ej: "Política de Cancelación Inca Rail" o "Historia Balcones de Cusco"')
            ->setColumns(6);

        yield ChoiceField::new('tipo', 'Tipo de Nota / Categoría')
            ->setChoices(array_reduce(NotaTipoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof NotaTipoEnum ? $value->value : $value)
            ->setHelp('Define si es una Historia, Tip, Alerta o Política.')
            ->setColumns(6);

        yield FormField::addPanel('Contenido Multilingüe')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título Visible al Cliente')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield CollectionField::new('contenido', 'Cuerpo del Texto')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12);
    }
}