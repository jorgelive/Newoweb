<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Enum\ComponenteItemModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('diccionario', 'Término / Viñeta')
            ->setHelp('Lo que leerá el cliente. Ej: "Desayuno Buffet" o "Seguro Aventura".')
            ->setColumns(4)
            ->setRequired(true);

        yield ChoiceField::new('modo', 'Condición Base')
            ->setChoices(array_reduce(ComponenteItemModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteItemModoEnum ? $value->value : $value)
            ->setHelp('¿Viene incluido, es opcional, o no está incluido?')
            ->setColumns(4)
            ->setRequired(true);

        yield AssociationField::new('componenteAdicionalVinculado', 'Upsell Vinculado (Costo Extra)')
            ->setHelp('SOLO SI ES OPCIONAL: Selecciona el componente logístico que se agregará a los costos si el cliente elige esta opción.')
            ->setColumns(4)
            ->setRequired(false);

        yield IntegerField::new('orden', 'Orden de lista')
            ->setColumns(12)
            ->hideOnIndex();
    }
}