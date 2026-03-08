<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\Maestro\MaestroPais;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField; // <-- IMPORTANTE: Añadida la importación
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * MaestroPaisCrudController.
 * Gestión de Países Globales con soporte para Banderas (Emojis).
 */
class MaestroPaisCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MaestroPais::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('País Global')
            ->setEntityLabelInPlural('Países Globales')
            ->setDefaultSort(['prioritario' => 'DESC', 'nombre' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        // --- PANEL 1: IDENTIFICACIÓN ---
        yield FormField::addPanel('Identificación Geográfica')->setIcon('fa fa-globe');

        // 1. ISO ID (PE, ES, US)
        $id = TextField::new('id', 'Código ISO (ID)')
            ->setHelp('Código de 2 caracteres (Ej: PE, US, ES)')
            ->setFormTypeOption('attr', [
                'maxlength' => 2,
                'style' => 'text-transform:uppercase',
                'placeholder' => 'PE'
            ])
            ->setColumns(2); // Ocupa poco espacio

        if (Crud::PAGE_EDIT === $pageName) {
            $id->setFormTypeOption('disabled', true);
        }

        yield $id;

        // 2. BANDERA (Emoji)
        yield TextField::new('bandera', 'Bandera')
            ->setHelp('Emoji (Win: Win+. / Mac: Ctrl+Cmd+Space)')
            ->setFormTypeOption('attr', [
                'maxlength' => 10,
                'placeholder' => '🇵🇪'
            ])
            ->setColumns(2);

        // 3. NOMBRE
        yield TextField::new('nombre', 'Nombre País')
            ->setColumns(8);

        // 4. IDIOMA DEFAULT (NUEVO CAMPO) 🔥
        yield AssociationField::new('idiomaDefault', 'Idioma por Defecto')
            ->setHelp('Se inferirá automáticamente si la OTA no envía el idioma del huésped.')
            ->setColumns(12)
            ->setQueryBuilder(fn($qb) => $qb->orderBy('entity.prioridad', 'DESC')->addOrderBy('entity.nombre', 'ASC'));

        // 5. PRIORIDAD
        yield BooleanField::new('prioritario', 'Prioridad')
            ->setHelp('Mostrar al inicio de los selectores.')
            ->renderAsSwitch(true)
            ->setColumns(12);

        // --- PANEL 2: INTEGRACIONES ---
        yield FormField::addPanel('Integraciones Externas')->setIcon('fa fa-plug');

        yield IntegerField::new('codigoMc', 'Cód. Cultura (MC)')
            ->setHelp('ID para boletos Machu Picchu.')
            ->hideOnIndex()
            ->setColumns(4);

        yield IntegerField::new('codigoPeruRail', 'Cód. PeruRail')
            ->hideOnIndex()
            ->setColumns(4);

        yield IntegerField::new('codigoConsettur', 'Cód. Consettur')
            ->hideOnIndex()
            ->setColumns(4);

        // Campo virtual solo para detalle (Lógica legacy Consettur)
        yield TextField::new('id', 'Cód. Ciudad (Consettur)')
            ->onlyOnDetail()
            ->formatValue(function ($value, $entity) {
                return $value === 'PE' ? '1610' : 'N/A';
            })
            ->setHelp('Código calculado automáticamente.');
    }
}