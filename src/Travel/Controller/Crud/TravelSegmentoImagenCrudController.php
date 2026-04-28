<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelSegmentoImagen;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class TravelSegmentoImagenCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmentoImagen::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Imagen')
            ->setEntityLabelInPlural('Imágenes de segmento');
    }
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('imageFile', 'Subir Imagen')
            ->setFormType(VichImageType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield ImageField::new('imageName', 'Previsualización')
            ->setBasePath('/uploads/cotizacion/segmentos') // Verifica que coincida con tu config de Vich
            ->onlyOnIndex();
    }
}