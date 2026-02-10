<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Factory\PmsTarifaRangoFactory;
use App\Pms\Form\Type\GeneradorTarifaMasivaType;
use App\Pms\Service\Tarifa\Dto\GeneradorTarifaMasivaDto;
use App\Pms\Service\Tarifa\Generator\GeneradorTarifaMasivaService;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;

/**
 * PmsTarifaRangoCrudController.
 * Gestión de rangos de precios y estancias mínimas por unidad.
 * Hereda de BaseCrudController y aplica seguridad por Roles prioritarios.
 */
final class PmsTarifaRangoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly PmsTarifaRangoFactory $tarifaRangoFactory,
        // ✅ INYECCIÓN DEL SERVICIO DE GENERACIÓN MASIVA
        private readonly GeneradorTarifaMasivaService $masivaService,
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsTarifaRango::class;
    }

    /**
     * ✅ Se mantiene el uso de la Factory para la creación de la entidad.
     */
    public function createEntity(string $entityFqcn): PmsTarifaRango
    {
        return $this->tarifaRangoFactory->create();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarifa Rango')
            ->setEntityLabelInPlural('Tarifas Rango')
            ->setDefaultSort(['fechaInicio' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50)
            ->overrideTemplate('crud/index', 'panel/pms/pms_tarifa_rango/index.html.twig');
    }

    /**
     * ✅ Configuración de acciones y permisos.
     * Los permisos se aplican DESPUÉS del parent para garantizar prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        // 1. Definimos la acción personalizada
        $generarMasivo = Action::new('generarMasivo', 'Generar Masivo')
            ->linkToCrudAction('generarMasivo')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-primary')
            ->setIcon('fa fa-magic');

        // 2. Acciones estándar
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        // Obtenemos configuración global del panel base
        $actions = parent::configureActions($actions);

        return $actions
            // Agregamos el botón custom
            ->add(Crud::PAGE_INDEX, $generarMasivo)

            // Permisos
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)

            // Permiso para la nueva acción (asumimos que requiere permisos de escritura)
            ->setPermission('generarMasivo', Roles::RESERVAS_WRITE);
    }

    public function generarMasivo(Request $request): Response
    {
        $dto = new GeneradorTarifaMasivaDto();

        // Creamos el form sin 'action' para que haga submit a esta misma URL
        $form = $this->createForm(GeneradorTarifaMasivaType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Delegamos la lógica al servicio inyectado
            $count = $this->masivaService->procesar($dto);

            $this->addFlash('success', sprintf('Proceso finalizado con éxito: %d tarifas generadas.', $count));

            // Redirección limpia al índice del CRUD
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl());
        }

        // Renderizamos la plantilla que extiende de @EasyAdmin/page/content.html.twig
        return $this->render('panel/pms/pms_tarifa_rango/tool_masiva.html.twig', [
            'form' => $form->createView()
        ]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('unidad')
            ->add('moneda')
            ->add('fechaInicio')
            ->add('fechaFin')
            ->add('minStay')
            ->add('importante')
            ->add('activo')
            ->add('prioridad')
            ->add('queues');
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID (IdTrait)
        $id = TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        $idFull = TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        // --- Rango ---
        $unidad = AssociationField::new('unidad', 'Unidad PMS')
            ->setRequired(true);

        $fechaInicio = DateField::new('fechaInicio', 'Inicio')
            ->setFormat('yyyy/MM/dd');

        $fechaFin = DateField::new('fechaFin', 'Fin')
            ->setFormat('yyyy/MM/dd');

        // --- Precio ---
        $moneda = AssociationField::new('moneda', 'Moneda')
            ->setRequired(true);

        $precio = NumberField::new('precio', 'Precio')
            ->setNumDecimals(2)
            ->setHelp('Decimal con punto. Ej: 125.50');

        $minStay = IntegerField::new('minStay', 'Estancia Mín. (Noches)')
            ->setRequired(false);

        $importante = BooleanField::new('importante', 'Tarifa Prioritaria')
            ->renderAsSwitch(true);

        $prioridad = IntegerField::new('prioridad', 'Prioridad')
            ->setHelp('Mayor prioridad sobre otros rangos solapados.')
            ->setRequired(false);

        $activo = BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        // --- Relaciones (solo lectura) ---
        $queues = AssociationField::new('queues', 'Procesos de Envío (Queue)')
            ->setDisabled(true)
            ->onlyOnDetail();

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $createdAt = DateTimeField::new('createdAt', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm');

        // ===================== INDEX =====================
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $unidad,
                $moneda,
                $fechaInicio,
                $fechaFin,
                $minStay,
                $precio,
                $importante,
                $prioridad,
                $activo,
            ];
        }

        // ===================== NEW / EDIT / DETAIL =====================
        $isDetail = (Crud::PAGE_DETAIL === $pageName);

        return [
            FormField::addPanel('Vigencia del Rango')->setIcon('fa fa-calendar'),
            $unidad,
            $fechaInicio,
            $fechaFin,

            FormField::addPanel('Configuración Económica')->setIcon('fa fa-money-bill'),
            $moneda,
            $precio,
            $minStay,
            $importante,
            $prioridad,
            $activo,

            FormField::addPanel('Cola de Sincronización')->setIcon('fa fa-cogs')->onlyOnDetail(),
            $queues,

            FormField::addPanel('Auditoría Técnica')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $idFull->onlyOnDetail(),
            $createdAt->onlyOnDetail(),
            $updatedAt->onlyOnDetail(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true)->hideWhenCreating(),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true)->hideWhenCreating(),
        ];
    }
}