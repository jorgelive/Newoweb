<?php

declare(strict_types=1);

namespace App\Exchange\Controller\Crud;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Panel\Controller\Crud\BaseCrudController;
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
 * ExchangeEndpointCrudController.
 * Gestión de los puntos de acceso técnicos para las APIs externas (Beds24, Gupshup, etc).
 */
class ExchangeEndpointCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return ExchangeEndpoint::class;
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
            // ✅ Textos genéricos porque ya no es solo Beds24
            ->setEntityLabelInSingular('Endpoint de Integración')
            ->setEntityLabelInPlural('Endpoints de Integración')
            // ✅ Ahora ordenamos primero por proveedor y luego por acción
            ->setDefaultSort(['provider' => 'ASC', 'accion' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('provider') // ✅ Filtro por nuevo Enum
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

        // ✅ NUEVO: Selector de Proveedor (Mapea el Enum para EasyAdmin)
        yield ChoiceField::new('provider', 'Proveedor')
            ->setChoices([
                'Beds24'           => ConnectivityProvider::BEDS24,
                'WhatsApp Gupshup' => ConnectivityProvider::GUPSHUP,
            ])
            ->setRequired(true)
            ->setHelp('Selecciona la plataforma a la que pertenece este endpoint.');

        yield TextField::new('accion', 'Acción Lógica (Slug)')
            ->setHelp('Código único para este proveedor. Ej: GET_BOOKINGS, SEND_MESSAGE')
            ->setRequired(true);

        yield TextField::new('nombre', 'Nombre Descriptivo');

        yield TextField::new('endpoint', 'Endpoint / Path')
            ->setHelp('Ruta de la API. Ej: /bookings o /wa/api/v1/msg')
            ->setRequired(true);

        yield ChoiceField::new('metodo', 'Método HTTP')
            ->setChoices([
                'POST'   => 'POST',
                'GET'    => 'GET',
                'PUT'    => 'PUT',
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

        yield CollectionField::new('ratesPushQueues', 'Colas de Tarifas (Beds24)')
            ->onlyOnDetail();

        yield CollectionField::new('bookingsPushQueues', 'Colas de Reservas Push (Beds24)')
            ->onlyOnDetail();

        yield CollectionField::new('bookingsPullQueues', 'Jobs de Pull (Beds24)')
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