<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\Beds24Config;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Beds24ConfigCrudController.
 * Gestión de la configuración de conexión con Beds24 API v2.
 * Hereda de BaseCrudController y utiliza seguridad por Roles.
 */
class Beds24ConfigCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Beds24Config::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Config Beds24')
            ->setEntityLabelInPlural('Configs Beds24')
            ->setDefaultSort(['id' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Configuración de Acciones y Permisos.
     * ✅ Se integra la jerarquía de BaseCrudController y las constantes de Roles.
     */
    public function configureActions(Actions $actions): Actions
    {
        // 1. Añadimos acciones específicas de esta entidad
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        // 2. Ejecutamos la lógica de la base y encadenamos los permisos de seguridad
        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('nombre')
            ->add('webhookToken')
            ->add('activo');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Conexión API v2')->setIcon('fa fa-plug');

        // ✅ UUID para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield TextField::new('nombre', 'Nombre interno');

        yield UrlField::new('baseUrl', 'API Base URL')
            ->setHelp('Por defecto: https://api.beds24.com/v2')
            ->hideOnIndex();

        yield TextField::new('refreshToken', 'Refresh Token')
            ->setHelp('API v2 Credential.')
            ->hideOnIndex();

        yield FormField::addPanel('Estado del Token Temporal')->setIcon('fa fa-key');

        yield TextField::new('authToken', 'Auth Token (Cache)')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();

        yield DateTimeField::new('authTokenExpiresAt', 'Expira')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield FormField::addPanel('Seguridad Webhook')->setIcon('fa fa-shield');

        yield TextField::new('webhookToken', 'Webhook Token')
            ->setHelp('Token secreto para validación de llamadas entrantes.');

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        yield FormField::addPanel('Relaciones Técnicas')->setIcon('fa fa-link')->onlyOnDetail();

        yield CollectionField::new('unidadMaps', 'Mapeos (Maps)')
            ->onlyOnDetail();

        // ✅ Paneles de Auditoría utilizando TimestampTrait (createdAt / updatedAt)
        yield FormField::addPanel('Tiempos').setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
    }
}