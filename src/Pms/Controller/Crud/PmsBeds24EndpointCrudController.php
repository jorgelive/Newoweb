<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsBeds24Endpoint;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Endpoint Beds24')
            ->setEntityLabelInPlural('Endpoints Beds24')
            ->setDefaultSort(['accion' => 'ASC'])
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
            ->add('accion')
            ->add('metodo')
            ->add('activo');
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->hideOnForm();

        $accion = TextField::new('accion', 'Acción lógica')
            ->setHelp('Ej: BOOKING_CREATE');

        $endpoint = TextField::new('endpoint', 'Endpoint')
            ->setHelp('Path o URL');

        $metodo = ChoiceField::new('metodo', 'Método HTTP')->setChoices([
            'POST' => 'POST',
            'GET' => 'GET',
            'DELETE' => 'DELETE',
        ]);

        $descripcion = TextareaField::new('descripcion', 'Descripción')
            ->setNumOfRows(4)
            ->setRequired(false);

        $activo = BooleanField::new('activo', 'Activo');

        $queues = CollectionField::new('queues', 'Colas (queues)')
            ->onlyOnDetail()
            ->setHelp('Solo lectura. Referencia a PmsBookingsPushQueue.');

        $created = DateTimeField::new('created', 'Creado')
            ->onlyOnDetail()
            ->setFormat('yyyy/MM/dd HH:mm');

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->onlyOnDetail()
            ->setFormat('yyyy/MM/dd HH:mm');

        $createdForm = DateTimeField::new('created', 'Creado')
            ->onlyOnForms()
            ->setFormTypeOption('disabled', true)
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedForm = DateTimeField::new('updated', 'Actualizado')
            ->onlyOnForms()
            ->setFormTypeOption('disabled', true)
            ->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $accion,
                $endpoint,
                $metodo,
                $activo,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                $id,

                FormField::addPanel('Definición')->setIcon('fa fa-link'),
                $accion,
                $endpoint,
                $metodo,
                $descripcion,

                FormField::addPanel('Estado')->setIcon('fa fa-toggle-on'),
                $activo,

                FormField::addPanel('Relaciones')->setIcon('fa fa-sitemap')->renderCollapsed(),
                $queues,

                FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed(),
                $created,
                $updated,
            ];
        }

        // new/edit
        return [
            FormField::addPanel('Definición')->setIcon('fa fa-link'),
            $accion,
            $endpoint,
            $metodo,
            $descripcion,

            FormField::addPanel('Estado')->setIcon('fa fa-toggle-on')->collapsible(),
            $activo,

            FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed(),
            $createdForm,
            $updatedForm,
        ];
    }
}