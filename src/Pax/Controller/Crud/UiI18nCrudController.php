<?php

declare(strict_types=1);

namespace App\Pax\Controller\Crud;

use App\Panel\Form\Type\TranslationTextType;
use App\Pax\Entity\UiI18n;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class UiI18nCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UiI18n::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Traducción UI')
            ->setEntityLabelInPlural('Traducciones UI')
            ->setSearchFields(['id', 'scope'])
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // El ID es la Natural Key. Lo permitimos al crear, pero lo bloqueamos al editar.
        yield IdField::new('id', 'Clave (ID)')
            ->setHelp('Ejemplo: res_checkin, gui_hola')
            ->setDisabled($pageName !== Crud::PAGE_NEW);

        yield ChoiceField::new('scope', 'Ámbito / Scope')
            ->setChoices([
                'Reserva' => 'reserva',
                'Guía' => 'guía',
                'WiFi' => 'wifi',
                'Común' => 'comun',
            ])
            ->renderAsBadges();
        yield BooleanField::new('ejecutarTraduccion', 'Traducir automáticamente')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir traducciones')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6)
            ->setHelp('⚠️ Reemplazará textos existentes.');

        /**
         * Para el campo 'contenido', usamos CollectionField.
         * Cada item debe ser un objeto con 'language' y 'content'.
         */
        yield CollectionField::new('contenido', 'Traducciones')
            ->allowAdd()
            ->allowDelete()
            ->setEntryType(TranslationTextType::class) // Necesitarás un FormType simple para esto
            ->setHelp('Solo necesitas agregar "es" (Español), el sistema traducirá el resto al guardar.');
    }
}