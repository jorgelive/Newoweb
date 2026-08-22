<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use App\Travel\Entity\TravelPunto;
use App\Travel\Enum\PuntoTipoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelPuntoCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelPunto::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Punto de recojo / entrega')
            ->setEntityLabelInPlural('Puntos de recojo y entrega')
            ->setSearchFields(['nombre', 'direccion', 'referencia'])
            ->setDefaultSort(['nombre' => 'ASC'])
            ->setHelp(
                'index',
                'Sitios concretos donde se recoge o se deja al pasajero. No confundir con '
                . 'Lugares / Centros: aquél es vocabulario de cobertura («Cusco») y sirve para '
                . 'filtrar; éste es una dirección a la que va un conductor. Un punto lleva un '
                . 'lugar para poder agruparlos, y nada más.'
            );
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
            // Como en Lugares: borrar un punto deja mudos todos los segmentos que lo usaban, y
            // eso no se nota hasta que sale una orden sin sitio de recojo.
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    /**
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nombre', 'Nombre')
            ->setColumns(6)
            ->setHelp('Único y reconocible. Ej: «Estación de Ollantaytambo», «Aeropuerto Cusco».');

        yield ChoiceField::new('tipo', 'Tipo')
            ->setChoices(array_reduce(PuntoTipoEnum::cases(), static fn ($c, $e) => $c + [$e->etiqueta() => $e], []))
            ->formatValue(static fn ($value) => $value instanceof PuntoTipoEnum ? $value->etiqueta() : $value)
            ->setColumns(3);

        yield AssociationField::new('lugar', 'Lugar / Centro')
            ->autocomplete()
            ->setColumns(3)
            ->setHelp('Sólo para agrupar y buscar. No decide nada.');

        yield FormField::addPanel('Lo que se le manda al proveedor')->setIcon('fa fa-truck');

        yield TextField::new('direccion', 'Dirección')
            ->setColumns(8)
            ->setHelp(
                'Puede quedar vacía si abajo eliges un proveedor: entonces se usa la de su ficha. '
                . 'Escríbela sólo cuando la del proveedor NO sirva (puerta de servicio, otra sede).'
            );

        yield AssociationField::new('organizacion', 'Es este proveedor')
            ->autocomplete()
            ->setColumns(4)
            ->setHelp('Rellénalo cuando el punto sea un hotel del catálogo. Hereda su dirección.');

        yield TextareaField::new('referencia', 'Referencia')
            ->setColumns(12)
            ->hideOnIndex()
            ->setHelp('La coletilla que evita la llamada: «puerta lateral», «el bus no entra, se caminan 50 m».');

        yield BooleanField::new('activo', 'Activo')
            ->setColumns(3)
            ->setHelp('Desactivar lo retira del desplegable sin romper los segmentos que ya lo usan.');
    }
}
