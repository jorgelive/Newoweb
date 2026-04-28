<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Cotizacion\Entity\CotizacionFile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Controlador para ver los expedientes raíz de las cotizaciones.
 * La creación profunda se debe hacer desde la interfaz de Vue.
 */
class CotizacionFileCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return CotizacionFile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Expediente (File)')
            ->setEntityLabelInPlural('Expedientes de Cotización')
            ->setSearchFields(['correlativo', 'nombreGrupo', 'pasajeroPrincipal'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Protegemos la integridad: No dejamos crear o editar la logística desde aquí
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('correlativo', 'N° File')
            ->setColumns(3);

        yield TextField::new('nombreGrupo', 'Nombre del Grupo / Cliente')
            ->setColumns(5);

        yield TextField::new('pasajeroPrincipal', 'Pasajero Principal')
            ->setColumns(4);

        yield ChoiceField::new('estado', 'Estado')
            ->setChoices([
                'Abierto' => 'abierto',
                'Confirmado' => 'confirmado',
                'Cancelado' => 'cancelado'
            ])
            ->setColumns(3);

        yield CollectionField::new('cotizaciones', 'Versiones de Cotización')
            ->onlyOnDetail()
            ->setTemplatePath('panel/cotizacion/field/cotizacion_versions_helper.html.twig'); // Opcional: Para darle formato bonito
    }
}