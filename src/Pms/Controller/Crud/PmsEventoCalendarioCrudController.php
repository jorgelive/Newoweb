<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PmsEventoCalendarioCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator                 $adminUrlGenerator,
        protected RequestStack                      $requestStack,
        private readonly EntityManagerInterface     $entityManager,
        private readonly PmsEventoCalendarioFactory $eventoFactory
    )
    {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoCalendario::class;
    }

    public function createEntity(string $entityFqcn): PmsEventoCalendario
    {
        $entity = $this->eventoFactory->createForUi();
        $esBloqueo = (bool)$this->requestStack->getCurrentRequest()?->query->get('es_bloqueo');

        if ($esBloqueo) {
            $estadoBloqueo = $this->entityManager->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_BLOQUEO);
            if ($estadoBloqueo) $entity->setEstado($estadoBloqueo);

            $estadoNoPagado = $this->entityManager->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO);
            if ($estadoNoPagado) $entity->setEstadoPago($estadoNoPagado);
        }

        return $entity;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsEventoCalendario) {
            $this->eventoFactory->hydrateLinksForUi($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PmsEventoCalendario) {
            if ($entityInstance->isOta()) {
                parent::updateEntity($entityManager, $entityInstance);
                return;
            }

            $uow = $entityManager->getUnitOfWork();
            $uow->computeChangeSet($entityManager->getClassMetadata(PmsEventoCalendario::class), $entityInstance);

            if (array_key_exists('pmsUnidad', $uow->getEntityChangeSet($entityInstance))) {
                $this->eventoFactory->hydrateLinksForUi($entityInstance);
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::BATCH_DELETE);
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DELETE);

        $checkBorrado = function (Action $action) {
            return $action->displayIf(fn(PmsEventoCalendario $e) => $e->isSafeToDelete());
        };

        $actions->update(Crud::PAGE_INDEX, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_DETAIL, Action::DELETE, $checkBorrado);
        $actions->update(Crud::PAGE_EDIT, Action::DELETE, $checkBorrado);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('referenciaCanal');
    }

    public function configureFields(string $pageName): iterable
    {
        $entity = $this->getContext()?->getEntity()->getInstance();
        $isEmbedded = $this->isEmbedded();

        $isBloqueo = (bool)$this->requestStack->getCurrentRequest()?->query->get('es_bloqueo') ||
            ($entity instanceof PmsEventoCalendario &&
                $entity->getEstado()?->getId() === PmsEventoEstado::CODIGO_BLOQUEO &&
                !$entity->getReserva());
        $isOta = $entity instanceof PmsEventoCalendario && $entity->isOta();

        $tomSelectNoClear = ['placeholder' => false, 'attr' => ['required' => 'required']];

        // ---------------------------------------------------------------------
        // 1. IDENTIFICADORES Y ESTADO
        // ---------------------------------------------------------------------
        yield TextField::new('id', 'UUID')->onlyOnDetail();

        // 🔥 SALVAVIDAS ANTI-DELETE PARA COLECCIONES
        if ($isEmbedded) {
            yield TextField::new('id')
                ->setFormTypeOption('mapped', false)
                ->onlyOnForms()
                ->addCssClass('d-none'); // Lo oculta visualmente en el layout de EasyAdmin
        } else {
            yield TextField::new('localizador', 'Localizador')
                ->setFormTypeOption('disabled', true)
                ->setColumns(6)
                ->formatValue(fn($v) => $v ? sprintf('<span class="badge badge-secondary">%s</span>', $v) : '');
        }

        yield TextField::new('syncStatus', 'Estado Sincro')
            ->setVirtual(true)
            ->hideOnForm()
            ->formatValue(fn($s) => match ($s) {
                'synced' => '<span class="badge badge-success"><i class="fa fa-check"></i> Sync</span>',
                'error' => '<span class="badge badge-danger"><i class="fa fa-exclamation"></i> Error</span>',
                'pending' => '<span class="badge badge-warning"><i class="fa fa-sync fa-spin"></i> Pend.</span>',
                default => '<span class="badge badge-secondary">Local</span>',
            })->renderAsHtml();

        // ---------------------------------------------------------------------
        // 2. DESCRIPCIÓN Y COMENTARIOS (SIEMPRE HABILITADOS)
        // ---------------------------------------------------------------------
        $fDescripcion = TextField::new('descripcion', $isBloqueo ? 'Motivo del Bloqueo' : 'Notas Internas (Hotel)');
        if ($isBloqueo) {
            $fDescripcion->setRequired(true)->setFormTypeOption('constraints', [new NotBlank(['message' => 'El motivo es obligatorio.'])]);
        }
        yield $fDescripcion;

        yield TextField::new('comentariosHuesped', 'Comentarios del Huésped')
            ->setHelp('Notas dejadas por el huésped o información adicional de la reserva.');

        // ---------------------------------------------------------------------
        // 3. DETALLES DEL EVENTO
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Detalles del Evento')->setIcon('fa fa-calendar-check');

        $fReserva = AssociationField::new('reserva', 'Reserva Padre')->setDisabled(true);
        if ($isEmbedded) {
            $fReserva->setFormTypeOption('row_attr', ['class' => 'd-none'])->setLabel(false);
        } elseif ($isBloqueo) {
            $fReserva->hideOnForm();
        }
        yield $fReserva;

        $fUnidad = AssociationField::new('pmsUnidad', 'Unidad')
            ->setRequired(true)
            ->setFormTypeOptions($tomSelectNoClear);
        if ($isOta) $fUnidad->setDisabled(true);
        yield $fUnidad;

        yield AssociationField::new('channel', 'Canal')
            ->setColumns(6)
            ->setFormTypeOption('disabled', true)
            ->setQueryBuilder(fn($qb) => $qb->orderBy('entity.orden', 'ASC'));

        $fEstado = AssociationField::new('estado', 'Estado')
            ->setRequired(true)
            ->setFormTypeOptions(array_merge($tomSelectNoClear, [
                'query_builder' => function ($repo) use ($isBloqueo, $isOta) {
                    $qb = $repo->createQueryBuilder('e');
                    if ($isBloqueo) {
                        $qb->andWhere('e.id IN (:estados)')->setParameter('estados', [PmsEventoEstado::CODIGO_BLOQUEO, PmsEventoEstado::CODIGO_CANCELADA]);
                    } elseif ($isOta) {
                        $qb->andWhere('e.id NOT IN (:restringidos)')->setParameter('restringidos', PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES);
                    } else {
                        $qb->andWhere('e.id != :bloqueo')->setParameter('bloqueo', PmsEventoEstado::CODIGO_BLOQUEO);
                    }
                    return $qb->orderBy('e.orden', 'ASC')->addOrderBy('e.nombre', 'ASC');
                },
            ]));

        // Bloqueo total de estado si es OTA cancelada (u otro estado restringido)
        if ($isOta && in_array($entity?->getEstado()?->getId(), PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true)) {
            $fEstado->setDisabled(true);
        } elseif ($isBloqueo) {
            $fEstado->hideOnForm();
        }
        yield $fEstado;

        $fEstadoPago = AssociationField::new('estadoPago', 'Estado de Pago')
            ->setRequired(true)
            ->setFormTypeOptions($tomSelectNoClear);
        if ($isBloqueo) $fEstadoPago->hideOnForm();
        yield $fEstadoPago;

        yield FormField::addRow();

        $fInicio = DateTimeField::new('inicio', 'Llegada (Check-in)')
            ->setRequired(true)
            ->setFormTypeOptions([
                'widget' => 'single_text', 'html5' => true,
                'attr' => ['step' => 60, 'data-controller' => 'panel--pms-reserva--form-evento-fechas', 'data-action' => 'change->panel--pms-reserva--form-evento-fechas#updateEnd']
            ]);
        if ($isOta) $fInicio->setDisabled(true);
        yield $fInicio;

        $fFin = DateTimeField::new('fin', 'Salida (Check-out)')
            ->setRequired(true)
            ->setFormTypeOptions(['widget' => 'single_text', 'html5' => true, 'attr' => ['step' => 60]]);
        if ($isOta) $fFin->setDisabled(true);
        yield $fFin;

        // ---------------------------------------------------------------------
        // 4. DATOS ECONÓMICOS Y PAX
        // ---------------------------------------------------------------------
        $fAdultos = IntegerField::new('cantidadAdultos', 'Nº Adultos')->setRequired(true)->setColumns(6);
        $fNinos = IntegerField::new('cantidadNinos', 'Nº Niños')->setRequired(true)->setColumns(6);
        $fMonto = MoneyField::new('monto', 'Precio Total')->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);
        $fComision = MoneyField::new('comision', 'Comisión Canal')->setCurrency('USD')->setStoredAsCents(false)->setColumns(6);

        if ($isBloqueo) {
            $fAdultos->hideOnForm();
            $fNinos->hideOnForm();
            $fMonto->hideOnForm();
            $fComision->hideOnForm();
        } elseif ($isOta) {
            // Se usa readonly para que los datos viajen en el POST y eviten el borrado de colección
            $fAdultos->setFormTypeOption('attr', ['readonly' => true]);
            $fNinos->setFormTypeOption('attr', ['readonly' => true]);
            $fMonto->setFormTypeOption('attr', ['readonly' => true]);
            $fComision->setFormTypeOption('attr', ['readonly' => true]);
        }

        yield $fAdultos;
        yield $fNinos;
        yield $fMonto;
        yield $fComision;

        // ---------------------------------------------------------------------
        // 5. INTEGRACIÓN (OTA & BEDS24)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Integración de Canal (OTA)')->setIcon('fa fa-sync')->renderCollapsed();

        $fIsOta = BooleanField::new('isOta', 'Origen OTA')->setDisabled(true);
        if ($isBloqueo) $fIsOta->hideOnForm();
        yield $fIsOta;

        if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_NEW || !empty($entity?->getReferenciaCanal())) {
            yield TextField::new('referenciaCanal', 'Ref. OTA')->setFormTypeOption('disabled', true);
        }

        yield TextField::new('horaLlegadaCanal', 'Hora Llegada OTA')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield DateTimeField::new('fechaReservaCanal', 'Fecha Reserva OTA')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield DateTimeField::new('fechaModificacionCanal', 'Fecha Modif. OTA')->setFormTypeOption('disabled', true)->hideOnIndex();

        yield TextField::new('estadoBeds24', 'Estado en Beds24')->setDisabled(true);
        yield AssociationField::new('beds24Links', 'Vínculos Técnicos')->setDisabled(true)->onlyOnDetail();

        // ---------------------------------------------------------------------
        // 6. AUDITORÍA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();
        yield DateTimeField::new('createdAt', 'Creado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('updatedAt', 'Actualizado')->hideOnIndex()->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
    }
}