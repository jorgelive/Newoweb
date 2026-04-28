<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelSegmento;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelSegmentoCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Segmento')
            ->setEntityLabelInPlural('Segmentos de itinerario');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Pieza')->setIcon('fa fa-puzzle-piece');

        // 🔥 ACTUALIZADO: Select2 Múltiple para permitir reciclar en múltiples pools
        yield AssociationField::new('servicios', 'Pools de Servicios (Contenedores)')
            ->setFormTypeOptions([
                'by_reference' => false,
                'multiple' => true,
            ])
            ->setHelp('Selecciona en qué servicios (tours) estará disponible este bloque narrativo.')
            ->setColumns(6);

        yield TextField::new('nombreInterno', 'Nombre Administrativo (ID)')
            ->setHelp('Ej: "MM - Visita Ciudadela Circuito 2"')
            ->setColumns(6);

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield FormField::addPanel('Contenido Narrativo')->setIcon('fa fa-pen-fancy');

        yield CollectionField::new('titulo', 'Título del Segmento')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield CollectionField::new('contenido', 'Cuerpo del Relato')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12);

        yield FormField::addPanel('Logística y Multimedia')->setIcon('fa fa-cogs');

        yield CollectionField::new('segmentoComponentes', 'Componentes Logísticos Vinculados')
            ->useEntryCrudForm(TravelSegmentoComponenteCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->setColumns(12)
            ->setHelp('Define qué servicios (entradas, trenes, etc) se disparan en este párrafo y si están incluidos. Ahora puedes definir condiciones por Tour.');

        yield CollectionField::new('imagenes', 'Galería de Fotos')
            ->useEntryCrudForm(TravelSegmentoImagenCrudController::class)
            ->setColumns(12);
    }
}