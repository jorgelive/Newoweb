<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBeds24Endpoint;
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
 * Restaurados nombres técnicos 'endpoint' y 'metodo' para compatibilidad con la lógica de sincronización.
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
        return PmsBeds24Endpoint::class;
    }

    /**
     * Configuración de Acciones y Permisos.
     * Los permisos de Roles se aplican DESPUÉS del parent para prioridad absoluta.
     */
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
        // ✅ Manejo de UUID para visualización técnica
        $id = TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        $nombre = TextField::new('nombre', 'Nombre descriptivo');

        $accion = TextField::new('accion', 'Acción lógica (Slug)')
            ->setHelp('Código único usado por los Processors. Ej: GET_BOOKINGS');

        // ✅ Propiedad restaurada a 'endpoint'
        $endpoint = TextField::new('endpoint', 'Endpoint / Path')
            ->setHelp('Ruta de la API. Ej: /bookings o /v2/bookings');

        // ✅ Propiedad restaurada a 'metodo'
        $metodo = ChoiceField::new('metodo', 'Método HTTP')->setChoices([
            'POST' => 'POST',
            'GET' => 'GET',
            'PUT' => 'PUT',
            'DELETE' => 'DELETE',
        ]);

        $version = ChoiceField::new('version', 'Versión API')->setChoices([
            'v1' => 'v1',
            'v2' => 'v2',
        ]);

        $descripcion = TextareaField::new('descripcion', 'Descripción técnica');
        $activo = BooleanField::new('activo', 'Activo')->renderAsSwitch(true);

        // Relaciones inversas (Colecciones de colas de proceso)
        $ratesQueues = CollectionField::new('ratesQueues', 'Colas de Tarifas')->onlyOnDetail();
        $bookingsPushQueues = CollectionField::new('bookingsPushQueues', 'Colas de Reservas (Push)')->onlyOnDetail();
        $pullQueueJobs = CollectionField::new('pullQueueJobs', 'Jobs de Pull')->onlyOnDetail();

        // Auditoría mediante TimestampTrait
        $createdAt = DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();

        if (Crud::PAGE_INDEX === $pageName) {
            return [$accion, $nombre, $endpoint, $metodo, $activo];
        }

        return [
            FormField::addPanel('Definición Técnica')->setIcon('fa fa-code'),
            $id,
            $nombre,
            $accion,
            $endpoint,
            $metodo,
            $version,
            $descripcion,

            FormField::addPanel('Estado Operativo')->setIcon('fa fa-toggle-on'),
            $activo,

            FormField::addPanel('Relaciones de Sincronización')->setIcon('fa fa-sitemap')->onlyOnDetail(),
            $ratesQueues,
            $bookingsPushQueues,
            $pullQueueJobs,

            FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed(),
            $createdAt,
            $updatedAt,
        ];
    }
}