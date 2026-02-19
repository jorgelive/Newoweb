<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\Beds24Endpoint;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsBeds24EndpointCrudController.
 * Gestión de los puntos de acceso técnicos para la API de Beds24.
 */
class PmsBeds24EndpointCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Beds24Endpoint::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Endpoint Beds24')
            ->setEntityLabelInPlural('Endpoints Beds24')
            ->setDefaultSort(['accion' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('accion')
            ->add('metodo')
            ->add('version')
            ->add('activo');
    }

    public function configureFields(string $pageName): iterable
    {
        // ============================================================
        // 1. DEFINICIÓN TÉCNICA
        // ============================================================
        yield FormField::addPanel('Definición Técnica')->setIcon('fa fa-code');

        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield TextField::new('accion', 'Acción Lógica (Slug)')
            ->setHelp('Código único usado por los Processors. Ej: GET_BOOKINGS')
            ->setRequired(true);

        yield TextField::new('nombre', 'Nombre Descriptivo');

        yield TextField::new('endpoint', 'Endpoint / Path')
            ->setHelp('Ruta de la API. Ej: /bookings o /v2/bookings')
            ->setRequired(true);

        yield ChoiceField::new('metodo', 'Método HTTP')
            ->setChoices([
                'POST' => 'POST',
                'GET' => 'GET',
                'PUT' => 'PUT',
                'DELETE' => 'DELETE',
            ])
            ->setRequired(true);

        yield ChoiceField::new('version', 'Versión API')
            ->setChoices([
                'v1' => 'v1',
                'v2' => 'v2',
            ])
            ->setRequired(true);

        yield TextareaField::new('descripcion', 'Descripción Técnica')
            ->hideOnIndex();

        // ============================================================
        // 2. ESTADO OPERATIVO
        // ============================================================
        yield FormField::addPanel('Estado Operativo')->setIcon('fa fa-toggle-on');

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        // ============================================================
        // 3. RELACIONES (Solo Detalle)
        // ============================================================
        yield FormField::addPanel('Relaciones de Sincronización')
            ->setIcon('fa fa-sitemap')
            ->onlyOnDetail();

        yield CollectionField::new('ratesPushQueues', 'Colas de Tarifas')
            ->onlyOnDetail();

        yield CollectionField::new('bookingsPushQueues', 'Colas de Reservas (Push)')
            ->onlyOnDetail();

        yield CollectionField::new('bookingsPullQueues', 'Jobs de Pull')
            ->onlyOnDetail();

        // ============================================================
        // 4. AUDITORÍA (ESTÁNDAR)
        // ============================================================
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}