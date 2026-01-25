<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\Beds24Config;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;


class Beds24ConfigCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string { return Beds24Config::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Config Beds24')
            ->setEntityLabelInPlural('Configs Beds24')
            ->setDefaultSort(['id' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions);
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
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('nombre', 'Nombre interno');

        // ✅ AGREGADO: Base URL para control total desde el panel
        yield UrlField::new('baseUrl', 'API Base URL')
            ->setHelp('Por defecto: https://api.beds24.com/v2')
            ->hideOnIndex();

        yield TextField::new('refreshToken', 'Refresh Token')
            ->setHelp('API v2 Credential. Usar periódicamente para evitar expiración.')
            ->hideOnIndex();

        yield TextField::new('authToken', 'Auth Token (Cache)')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();

        yield DateTimeField::new('authTokenExpiresAt', 'Expira')
            ->setFormat('yyyy/MM/dd HH:mm');

        yield TextField::new('webhookToken', 'Webhook Token')
            ->setHelp('Token secreto para validación de llamadas entrantes.');

        yield BooleanField::new('activo', 'Activo');

        yield CollectionField::new('unidadMaps', 'Mapeos (Maps)')
            ->onlyOnDetail();

        // Paneles de Auditoría
        yield FormField::addPanel('Tiempos')->setIcon('fa fa-clock')->renderCollapsed();
        yield DateTimeField::new('created', 'Creado')->setFormTypeOption('disabled', true)->onlyOnDetail();
        yield DateTimeField::new('updated', 'Actualizado')->setFormTypeOption('disabled', true)->onlyOnDetail();
    }
}