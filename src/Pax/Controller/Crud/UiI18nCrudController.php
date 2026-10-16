<?php

declare(strict_types=1);

namespace App\Pax\Controller\Crud;

use App\Panel\Field\CamposDeTraduccion;
use App\Panel\Form\Type\TranslationTextType;
use App\Pax\Entity\UiI18n;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<UiI18n>
 */
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
            ->setDefaultSort(['id' => 'ASC'])
            ->showEntityActionsInlined();

    }

    public function configureFields(string $pageName): iterable
    {
        // El ID es la Natural Key. Lo permitimos al crear, pero lo bloqueamos al editar.
        yield IdField::new('id', 'Clave (ID)')
            ->setHelp('Ejemplo: res_checkin, gui_hola')
            ->setDisabled($pageName !== Crud::PAGE_NEW)
            ->setMaxLength(40);

        yield ChoiceField::new('scope', 'Ámbito / Scope')
            // ⚠️ Los valores son los que HAY en la base (16/10/2026). Faltaban `cotizacion` y
            // `catalogo`, y «Guía» guardaba `guía` con tilde frente a `guia` en los datos: el
            // selector salía vacío y, al ser obligatorio, 199 textos no se podían guardar.
            ->setChoices([
                'Reserva' => 'reserva',
                'Guía' => 'guia',
                'Cotización' => 'cotizacion',
                'Catálogo' => 'catalogo',
                'WiFi' => 'wifi',
                'Común' => 'comun',
            ])
            ->renderAsBadges();
        // Maestro de rótulos: al editar, la casilla de traducir sale desmarcada. Ver CamposDeTraduccion.
        yield from CamposDeTraduccion::deMaestroDeRotulos($pageName, Roles::RESERVAS_WRITE);

        /**
         * Para el campo 'contenido', usamos CollectionField.
         * Cada item debe ser un objeto con 'language' y 'content'.
         */
        yield CollectionField::new('contenido', 'Traducciones')
            ->allowAdd()
            ->allowDelete()
            ->setEntryType(TranslationTextType::class) // Necesitarás un FormType simple para esto
            ->setHelp('Al crear basta con el español: el resto se traduce al guardar. Al editar, lo revisado no se toca salvo que marques «Traducir automáticamente».');
    }
}