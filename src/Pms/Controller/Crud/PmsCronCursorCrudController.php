<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsCronCursor;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class PmsCronCursorCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsCronCursor::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cursor de Proceso')
            ->setEntityLabelInPlural('Monitoreo de Crons')
            ->setDefaultSort(['lastRunAt' => 'DESC'])
            ->showEntityActionsInlined()
            ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('jobName', 'Nombre del Proceso')
                // ->setTemplatePath('admin/field/badge.html.twig') // Descomenta si tienes un template de badges
                ->setHelp('Identificador único del trabajo programado.'),

            DateField::new('cursorDate', 'Fecha Puntero (Cursor)')
                ->setFormat('yyyy-MM-dd')
                ->setHelp('Fecha que se procesará en la SIGUIENTE ejecución.'),

            DateTimeField::new('lastRunAt', 'Última Ejecución Exitosa')
                ->setFormat('yyyy-MM-dd HH:mm:ss')
                // ->setWidget('widget')  <-- ELIMINADO: Esto causaba el error
                ->setHelp('Momento exacto en que el comando actualizó este cursor.'),
        ];
    }
}