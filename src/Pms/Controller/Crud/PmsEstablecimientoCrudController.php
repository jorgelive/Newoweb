<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEstablecimiento;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField; // Usar IdField para UUID es mejor que TextField
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEstablecimientoCrudController.
 * Gestión de propiedades o casas principales del sistema.
 */
final class PmsEstablecimientoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEstablecimiento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Establecimiento')
            ->setEntityLabelInPlural('Establecimientos')
            ->setDefaultSort(['nombreComercial' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('beds24Config')
            ->add('timezone');
    }

    public function configureFields(string $pageName): iterable
    {
        // 1. ID (Solo visible en detalle e índice si es necesario)
        yield IdField::new('id', 'UUID')
            ->setMaxLength(40)
            ->onlyOnDetail();

        // ============================================================
        // 🏨 INFORMACIÓN GENERAL
        // ============================================================
        yield FormField::addPanel('Información General')->setIcon('fa fa-building');

        yield TextField::new('nombreComercial', 'Nombre Comercial')
            ->setColumns(8);

        yield AssociationField::new('pais', 'País')
            ->setRequired(true)
            ->setColumns(4);

        yield TextField::new('direccionLinea1', 'Dirección')
            ->hideOnIndex()
            ->setColumns(8);

        yield TextField::new('ciudad', 'Ciudad')
            ->setColumns(4);

        // ============================================================
        // 🔐 CÓDIGOS DE ACCESO (NUEVO PANEL)
        // ============================================================
        yield FormField::addPanel('Seguridad y Accesos (Edificio)')
            ->setIcon('fa fa-key')
            ->setHelp('Códigos generales para entrar al establecimiento (Portón, Recepción, Almacén de Llaves).');

        yield TextField::new('codigoCajaPrincipal', 'Caja Fuerte / Portón (Principal)')
            ->setColumns(6)
            ->hideOnIndex()
            ->setHelp('Variable para guías: <b>{caja_principal}</b>');

        yield TextField::new('codigoCajaSecundaria', 'Caja Secundaria / Almacén')
            ->setColumns(6)
            ->hideOnIndex()
            ->setHelp('Variable para guías: <b>{caja_secundaria}</b>');

        // ============================================================
        // 📞 CONTACTO
        // ============================================================
        yield FormField::addPanel('Contacto')
            ->setIcon('fa fa-phone')
            ->renderCollapsed();

        yield TextField::new('telefonoPrincipal', 'Teléfono')
            ->hideOnIndex()
            ->setColumns(6);

        yield TextField::new('emailContacto', 'Email')
            ->hideOnIndex()
            ->setColumns(6);

        // ============================================================
        // 🕒 OPERACIÓN
        // ============================================================
        yield FormField::addPanel('Configuración Operativa')
            ->setIcon('fa fa-clock')
            ->renderCollapsed();

        yield AssociationField::new('beds24Config', 'Configuración Beds24')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true]);

        yield AssociationField::new('metaConfig', 'Configuración Beds24')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true]);

        yield TimeField::new('horaCheckIn', 'Check-in')
            ->setColumns(4);

        yield TimeField::new('horaCheckOut', 'Check-out')
            ->setColumns(4);

        yield TextField::new('timezone', 'Zona Horaria')
            ->setHelp('Ej: America/Lima')
            ->setColumns(4);

        // ============================================================
        // 🛡️ AUDITORÍA
        // ============================================================
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true); // Visible pero readonly en form

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}