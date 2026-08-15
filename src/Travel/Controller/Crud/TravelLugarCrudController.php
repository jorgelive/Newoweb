<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use App\Travel\Entity\TravelLugar;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelLugarCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelLugar::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Lugar / Centro de operación')
            ->setEntityLabelInPlural('Lugares / Centros')
            ->setSearchFields(['nombre'])
            ->setDefaultSort(['orden' => 'ASC', 'nombre' => 'ASC'])
            ->setHelp(
                'index',
                'Vocabulario plano para etiquetar componentes. Es multivaluado: un servicio de '
                . 'Ica que se despacha desde Lima lleva «Lima» y «Ica». No hay jerarquía a '
                . 'propósito — Valle Sagrado no está dentro de la ciudad de Cusco.'
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
            // MAESTROS_DELETE y no WRITE como en el resto del módulo: borrar un lugar arrastra
            // en cascada todo su etiquetado y no hay forma de recuperarlo.
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nombre', 'Nombre')
            ->setColumns(6)
            ->setHelp('Único. Ej: «Lima», «Valle Sagrado». Evita duplicar con distinta grafía.');

        yield IntegerField::new('orden', 'Orden')
            ->setColumns(3)
            ->setHelp('Posición en la fila de chips del cuadro de tráfico. Menor primero.');

        yield BooleanField::new('activo', 'Activo')
            ->setColumns(3)
            ->setHelp('Desactivar lo retira de los filtros SIN borrar el etiquetado. Prefiere esto a eliminar.');

        yield TextField::new('virtualComponentesCount', 'Uso')
            ->onlyOnIndex()
            ->renderAsHtml();

        // Etiquetado masivo: abrir «Lima» y marcar 40 componentes de un tirón es el camino
        // rápido. Hacerlo uno a uno desde cada componente es media jornada.
        // `by_reference: false` es OBLIGATORIO por ser el lado inverso: sin él el formulario
        // guarda sin error y no persiste nada.
        yield FormField::addPanel('Componentes etiquetados aquí')->setIcon('fa fa-map-marker-alt');

        yield AssociationField::new('componentes', 'Componentes')
            ->onlyOnForms()
            ->autocomplete()
            ->setFormTypeOption('by_reference', false)
            ->setColumns(12)
            ->setHelp('Selección múltiple. Es la vía rápida para etiquetar en lote.');
    }
}
