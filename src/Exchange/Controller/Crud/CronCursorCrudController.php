<?php

declare(strict_types=1);

namespace App\Exchange\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Exchange\Entity\ExchangeCronCursor;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * CronCursorCrudController.
 * Monitoreo de punteros temporales para procesos en segundo plano.
 * Hereda de BaseCrudController y es de solo lectura.
 *
 * @extends BaseCrudController<ExchangeCronCursor>
 */
class CronCursorCrudController extends BaseCrudController
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
        return ExchangeCronCursor::class;
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
        $reiniciar = Action::new('reiniciarCursor', 'Reiniciar', 'fa fa-rotate-left')
            ->linkToCrudAction('reiniciarCursorAction')
            ->setCssClass('btn btn-warning text-dark')
            // Si ya está en el arranque (o aún no tiene fecha), el botón no aporta nada.
            // La comparación se hace explícita en vez de fiarse de cómo compara PHP null
            // contra un objeto DateTime.
            ->displayIf(static function (ExchangeCronCursor $c): bool {
                $fecha = $c->getCursorDate();

                return $fecha !== null && $fecha > new DateTimeImmutable('yesterday');
            });

        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $reiniciar)
            ->add(Crud::PAGE_DETAIL, $reiniciar);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            // Reiniciar SÍ escribe: no basta con permiso de lectura.
            ->setPermission('reiniciarCursor', Roles::RESERVAS_WRITE);
    }

    /**
     * Devuelve el cursor al arranque (ayer) para forzar un barrido completo desde el principio.
     *
     * Para qué sirve en la práctica: si se cargan tarifas o reservas de fechas ya superadas por
     * el cursor, ese tramo no se vuelve a barrer hasta que el ciclo dé la vuelta entera. Este
     * botón corta la espera.
     *
     * No hace falta usarlo cuando el cursor se pasa del horizonte de datos: eso lo detecta y
     * corrige solo `TimelineEnqueuerService` en la siguiente ejecución (§11.2.1). Este botón es
     * para el caso contrario — adelantar un barrido que aún no tocaba.
     * @param AdminContext<ExchangeCronCursor> $context
     */
    public function reiniciarCursorAction(AdminContext $context, EntityManagerInterface $em): Response
    {
        /** @var ExchangeCronCursor $cursor */
        $cursor = $context->getEntity()->getInstance();

        $anterior = $cursor->getCursorDate()?->format('Y-m-d') ?? '—';
        $nueva = new DateTimeImmutable('yesterday');

        $cursor->setCursorDate($nueva);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Cursor de «%s» reiniciado: %s → %s. El siguiente barrido empezará desde ahí.',
            $cursor->getJobName(),
            $anterior,
            $nueva->format('Y-m-d')
        ));

        return $this->redirect(
            $context->getReferrer()
            ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
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