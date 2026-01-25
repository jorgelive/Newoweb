<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

class PmsEventoCalendarioCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private PmsEventoCalendarioFactory $eventoFactory
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoCalendario::class;
    }

    public function createEntity(string $entityFqcn): PmsEventoCalendario
    {
        $entity = $this->eventoFactory->crearInstanciaPorDefecto();
        $esBloqueo = $this->requestStack->getCurrentRequest()?->query->get('es_bloqueo');

        if ($esBloqueo) {
            $estadoBloqueo = $this->entityManager->getRepository(PmsEventoEstado::class)->findOneBy(['codigo' => PmsEventoEstado::CODIGO_BLOQUEO]);
            if ($estadoBloqueo) $entity->setEstado($estadoBloqueo);

            $estadoNoPagado = $this->entityManager->getRepository(PmsEventoEstadoPago::class)->findOneBy(['codigo' => 'no-pagado']);
            if ($estadoNoPagado) $entity->setEstadoPago($estadoNoPagado);
        }
        return $entity;
    }

    public function configureActions(Actions $actions): Actions
    {
        // 1. Deshabilitar borrado masivo por seguridad
        $actions->disable(Action::BATCH_DELETE);

        $actions->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        // 2. Lógica de visualización del botón BORRAR
        // Delegamos el 100% de la decisión a la entidad.
        // - Si es Local (Confirmada o no) -> TRUE (Permite corregir errores).
        // - Si es Beds24 Activa -> FALSE (Obliga a cancelar primero).
        // - Si es Beds24 Cancelada + Sync -> TRUE (Permite limpiar).
        // - Si es OTA -> FALSE.
        $checkBorrado = function (Action $action) {
            return $action->displayIf(static function (PmsEventoCalendario $evento) {
                return $evento->isSafeToDelete();
            });
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        [$isNewOrEdit, $isBloqueo, $isOta] = $this->resolveContext($pageName);
        $f = $this->buildFields();

        // Aplicar reglas de negocio según contexto
        if ($isNewOrEdit && $isBloqueo) $this->applyBloqueoRules($f);
        if ($isOta) $this->applyOtaRules($f);

        // --- Renderizado de Campos ---

        yield IdField::new('id')->hideOnForm();
        yield $this->buildSyncStatusBadgeField()->hideOnForm(); // Badge visual de sync
        yield $f['descripcion'];

        yield FormField::addPanel('Datos del evento')->setIcon('fa fa-pen');
        yield $f['reserva'];
        yield $f['pmsUnidad'];
        yield $f['estado'];
        yield $f['estadoPago'];

        yield FormField::addRow();
        yield $this->decorateInicioField($f['inicio']);
        yield $this->decorateFinField($f['fin']);

        yield $f['adultos']->setRequired(true)->setColumns(6);
        yield $f['ninos']->setRequired(true)->setColumns(6);
        yield $f['monto']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);
        yield $f['comision']->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);

        yield FormField::addPanel('Proceso (Beds24)')->setIcon('fa fa-info-circle')->renderCollapsed();
        yield $f['isOta']->setDisabled(true);
        yield TextField::new('estadoBeds24', 'Beds24 Status')->setDisabled(true);
        yield AssociationField::new('beds24Links', 'Beds24 Links')->setDisabled(true);
    }

    // --- Helpers Privados ---

    private function resolveContext(string $pageName): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $entityInstance = $this->getContext()?->getEntity()->getInstance();

        $isNewOrEdit = \in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true);

        // Detectamos si es un bloqueo por query param O por el estado de la entidad
        $isBloqueo = (bool)$request?->query->get('es_bloqueo') ||
            ($entityInstance instanceof PmsEventoCalendario &&
                $entityInstance->getEstado()?->getCodigo() === PmsEventoEstado::CODIGO_BLOQUEO &&
                !$entityInstance->getReserva());

        $isOta = $entityInstance instanceof PmsEventoCalendario && $entityInstance->isOta();

        return [$isNewOrEdit, $isBloqueo, $isOta];
    }

    private function buildFields(): array
    {
        // Configuración para TomSelect: Evita opción vacía y oculta la "X" de borrado
        $tomSelectNoClear = [
            'placeholder' => false, // Symfony Form option (Nivel raíz)
            'attr' => [
                'required' => 'required', // HTML attribute para JS de TomSelect
            ],
        ];

        return [
            'descripcion' => TextField::new('descripcion', 'Descripción'),

            'pmsUnidad'   => AssociationField::new('pmsUnidad', 'Unidad')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),

            'estado'      => AssociationField::new('estado', 'Estado')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),

            'estadoPago'  => AssociationField::new('estadoPago', 'Estado de pago')
                ->setRequired(true)
                ->setFormTypeOptions($tomSelectNoClear),

            'reserva'     => AssociationField::new('reserva', 'Reserva Padre')->setDisabled(true),
            'inicio'      => DateTimeField::new('inicio', 'Fecha Inicio'),
            'fin'         => DateTimeField::new('fin', 'Fecha Fin'),
            'adultos'     => IntegerField::new('cantidadAdultos', 'Adultos'),
            'ninos'       => IntegerField::new('cantidadNinos', 'Niños'),
            'monto'       => MoneyField::new('monto', 'Monto (unidad)'),
            'comision'    => MoneyField::new('comision', 'Comisión'),
            'isOta'       => BooleanField::new('isOta', 'Es Reserva OTA'),
        ];
    }

    private function applyBloqueoRules(array $f): void
    {
        // Un bloqueo debe tener motivo obligatorio y oculta campos financieros/estados
        $f['descripcion']->setLabel('Motivo del Bloqueo')->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank(['message' => 'El motivo es obligatorio.'])]);

        foreach ([$f['estado'], $f['estadoPago'], $f['reserva'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision'], $f['isOta']] as $field) {
            $field->hideOnForm();
        }
    }

    private function applyOtaRules(array $f): void
    {
        // Una OTA no permite editar datos core
        foreach ([$f['pmsUnidad'], $f['reserva'], $f['inicio'], $f['fin'], $f['adultos'], $f['ninos'], $f['monto'], $f['comision'], $f['estado'], $f['estadoPago']] as $field) {
            $field->setDisabled(true);
        }
    }

    private function decorateInicioField(DateTimeField $inicio): DateTimeField
    {
        return $inicio->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'step' => 60,
                    'data-controller' => 'panel--pms-reserva--form-evento-fechas',
                    'data-action' => 'change->panel--pms-reserva--form-evento-fechas#updateEnd'
                ]
            ]);
    }

    private function decorateFinField(DateTimeField $fin): DateTimeField
    {
        return $fin->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                'attr' => ['step' => 60]
            ]);
    }

    private function buildSyncStatusBadgeField(): TextField
    {
        return TextField::new('syncStatus', 'Beds24 Sync')
            ->setVirtual(true)
            ->formatValue(function ($statusValue) {
                return match ($statusValue) {
                    'synced'  => '<span class="badge badge-success"><i class="fa fa-check"></i> OK</span>',
                    'error'   => '<span class="badge badge-danger"><i class="fa fa-exclamation-triangle"></i> Error</span>',
                    'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Wait</span>',
                    default   => '<span class="badge badge-secondary"><i class="fa fa-home"></i> Local</span>',
                };
            })
            ->renderAsHtml();
    }
}