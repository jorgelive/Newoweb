<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Enum\ItemModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\TextAlign;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Controlador auxiliar (Embedded) para gestionar los ítems descriptivos y upsells
 * dentro del CRUD de TravelComponente. No se muestra en el menú principal.
 */
class TravelComponenteItemCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelComponenteItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined();
    }

    /**
     * Define los campos visibles y editable para la entidad TravelComponenteItem.
     *
     * @param string $pageName
     * @return iterable
     */
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('diccionario', 'Término / Viñeta')
            ->setHelp('Lo que leerá el cliente. Ej: "Desayuno Buffet" o "Seguro Aventura".')
            ->setColumns(4)
            ->setRequired(true);

        yield ChoiceField::new('modo', 'Condición Base')
            ->setChoices(array_reduce(ItemModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ItemModoEnum ? $value->value : $value)
            ->setHelp('¿Viene incluido, es opcional, o no está incluido?')
            ->setColumns(4)
            ->setRequired(true);

        yield AssociationField::new('componenteAdicionalVinculado', 'Upsell Vinculado (Costo Extra)')
            ->setHelp('SOLO SI ES OPCIONAL: Selecciona el componente logístico que se agregará a los costos si el cliente elige esta opción.')
            ->setColumns(4)
            ->setRequired(false);

        /* ====================================================================
         * VISIBILIDAD DE TARIFAS (CONFIGURACIÓN PÚBLICA)
         * ==================================================================== */
        yield BooleanField::new('tituloTarifaVisible', 'Ver Título Tarifa')
            ->setHelp('Muestra u oculta el título de la tarifa asociada al cliente final.')
            ->renderAsSwitch(true)
            ->setTextAlign(TextAlign::CENTER)
            ->setColumns(4);

        yield BooleanField::new('categoriaTarifaVisible', 'Ver Cat. Tarifa')
            ->setHelp('Muestra u oculta la categoría de la tarifa asociada al cliente final.')
            ->renderAsSwitch(true)
            ->setTextAlign(TextAlign::CENTER)
            ->setColumns(4);

        yield BooleanField::new('modalidadTarifaVisible', 'Ver Mod. Tarifa')
            ->setHelp('Muestra u oculta la modalidad de la tarifa asociada al cliente final.')
            ->renderAsSwitch(true)
            ->setTextAlign(TextAlign::CENTER)
            ->setColumns(4);

        yield IntegerField::new('orden', 'Orden de lista')
            ->setColumns(12)
            ->hideOnIndex();
    }
}