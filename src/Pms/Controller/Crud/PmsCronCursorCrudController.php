<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsCronCursor;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsCronCursorCrudController.
 * Monitoreo de punteros temporales para procesos en segundo plano.
 * Hereda de BaseCrudController y es de solo lectura.
 */
class PmsCronCursorCrudController extends BaseCrudController
{
    /**
     * Mantenemos el constructor inyectando dependencias base.
     */
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
            ->showEntityActionsInlined();
    }

    /**
     * Configuración de acciones y permisos.
     * ✅ Deshabilitamos acciones de escritura para proteger la lógica de los workers.
     * ✅ Aplicamos roles de visualización.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW);
    }

    /**
     * Definición de campos.
     * Nota: jobName es el ID de la entidad.
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Estado del Proceso')->setIcon('fa fa-terminal');

        yield TextField::new('jobName', 'Nombre del Proceso')
            ->setHelp('Identificador técnico del comando/job.');

        yield DateField::new('cursorDate', 'Fecha Puntero (Cursor)')
            ->setFormat('yyyy-MM-dd')
            ->setHelp('Define la fecha desde la cual el proceso retomará su lógica en la siguiente ejecución.');

        yield DateTimeField::new('lastRunAt', 'Última Sincronización')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->setHelp('Marca de tiempo del último registro exitoso en la base de datos.');

        // Esta entidad no usa TimestampTrait (createdAt/updatedAt) según el código enviado,
        // por lo que nos ceñimos a las propiedades declaradas.
    }
}