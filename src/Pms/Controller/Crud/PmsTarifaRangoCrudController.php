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

/**
 * PmsTarifaRangoCrudController.
 * Gestión de rangos de precios y estancias mínimas por unidad.
 */
final class PmsTarifaRangoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly PmsTarifaRangoFactory $tarifaRangoFactory,
        private readonly GeneradorTarifaMasivaService $masivaService,
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsTarifaRango::class;
    }

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

    public function configureActions(Actions $actions): Actions
    {
        // 1. Acción personalizada: Generar Masivo
        $generarMasivo = Action::new('generarMasivo', 'Generar Masivo')
            ->linkToCrudAction('generarMasivo')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-primary')
            ->setIcon('fa fa-magic');

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->add(Crud::PAGE_INDEX, $generarMasivo)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission('generarMasivo', Roles::RESERVAS_WRITE);
    }

    public function generarMasivo(Request $request): Response
    {
        $dto = new GeneradorTarifaMasivaDto();
        $form = $this->createForm(GeneradorTarifaMasivaType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $count = $this->masivaService->procesar($dto);
            $this->addFlash('success', sprintf('Proceso finalizado con éxito: %d tarifas generadas.', $count));

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl());
        }

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
        // ============================================================
        // 1. VIGENCIA DEL RANGO
        // ============================================================
        yield FormField::addPanel('Vigencia del Rango')->setIcon('fa fa-calendar');

        // IDs
        yield TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        yield TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        // Campos Principales
        yield AssociationField::new('unidad', 'Unidad PMS')
            ->setRequired(true);

        yield DateField::new('fechaInicio', 'Inicio')
            ->setFormat('yyyy/MM/dd');

        yield DateField::new('fechaFin', 'Fin')
            ->setFormat('yyyy/MM/dd');

        // ============================================================
        // 2. CONFIGURACIÓN ECONÓMICA
        // ============================================================
        yield FormField::addPanel('Configuración Económica')->setIcon('fa fa-money-bill');

        yield AssociationField::new('moneda', 'Moneda')
            ->setRequired(true);

        yield NumberField::new('precio', 'Precio')
            ->setNumDecimals(2)
            ->setHelp('Decimal con punto. Ej: 125.50');

        yield IntegerField::new('minStay', 'Estancia Mín. (Noches)')
            ->setRequired(false);

        yield BooleanField::new('importante', 'Tarifa Prioritaria')
            ->renderAsSwitch(true);

        yield IntegerField::new('prioridad', 'Prioridad')
            ->setHelp('Mayor prioridad sobre otros rangos solapados.')
            ->setRequired(false)
            ->hideOnIndex();

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        // ============================================================
        // 3. COLAS (Solo Detalle)
        // ============================================================
        yield FormField::addPanel('Cola de Sincronización')
            ->setIcon('fa fa-cogs')
            ->onlyOnDetail();

        yield AssociationField::new('queues', 'Procesos de Envío (Queue)')
            ->setDisabled(true)
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