<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsChannel;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsChannelCrudController.
 * Gestión de Canales de Venta (Airbnb, Booking, Directo).
 * El ID es natural (String) y hereda de BaseCrudController.
 */
final class PmsChannelCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsChannel::class;
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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Canal')
            ->setEntityLabelInPlural('Canales')
            ->setDefaultSort(['orden' => 'ASC', 'nombre' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('nombre')
            ->add('esExterno')
            ->add('esDirecto');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. IDENTIDAD DEL CANAL
        // ============================================================
        yield FormField::addPanel('Identidad del Canal')->setIcon('fa fa-hashtag');

        // ID Natural: Requerido al crear, Bloqueado al editar
        yield TextField::new('id', 'Código (ID)')
            ->setHelp('Slug interno único (ej: booking, airbnb, directo).')
            ->setFormTypeOption('attr', ['placeholder' => 'airbnb', 'maxlength' => 50])
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setDisabled(Crud::PAGE_EDIT === $pageName);

        yield TextField::new('nombre', 'Nombre Comercial')
            ->setColumns(6);

        yield IntegerField::new('orden', 'Prioridad Visual')
            ->setHelp('0 sale primero, números altos salen al final.')
            ->setColumns(6);

        // ============================================================
        // 2. CONFIGURACIÓN TÉCNICA
        // ============================================================
        yield FormField::addPanel('Integración y Configuración')->setIcon('fa fa-cogs');

        yield TextField::new('beds24ChannelId', 'Beds24 Channel ID')
            ->setHelp('ID técnico del canal en la API v2 de Beds24.')
            ->setRequired(false)
            ->hideOnIndex();

        yield BooleanField::new('esExterno', 'Es Externo (OTA)')
            ->setHelp('Define si es un canal de terceros (Booking, Expedia).')
            ->renderAsSwitch(true);

        yield BooleanField::new('esDirecto', 'Es Venta Directa')
            ->setHelp('Define si es motor de reservas propio o recepción.')
            ->renderAsSwitch(true);

        yield TextField::new('color', 'Color Identificador')
            ->setHelp('Formato HEX: #RRGGBB')
            ->setFormTypeOption('attr', ['placeholder' => '#FF0000'])
            ->setRequired(false);

        // ============================================================
        // 3. AUDITORÍA (ESTÁNDAR)
        // ============================================================
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Última Modificación')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}