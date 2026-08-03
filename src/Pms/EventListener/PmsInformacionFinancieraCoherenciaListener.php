<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\MonedaBaseRebaseContext;
use App\Pms\Service\Finance\PmsCargosAutomaticosService;
use App\Pms\Service\Finance\PmsInformacionFinancieraRecalculoService;
use App\Pms\Service\Finance\MonedaResolver;
use DomainException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Listener PmsInformacionFinancieraCoherenciaListener.
 *
 * Espejo de PmsReservaRecalculoListener, aplicado a la cabecera financiera:
 *
 * 1. AUTO-PROVISIÓN: al crear una PmsReserva se crea también su cabecera financiera.
 *    Sin ella no hay dónde colgar cargos ni pagos, y las reservas DIRECTAS nunca la
 *    reciben (sólo llega por la sincronización de invoiceItems de Beds24, §11). Se hace
 *    para toda reserva, no sólo las directas: es idempotente (PmsInformacionFinanciera
 *    es 1:1 con la reserva y el persister de Beds24 hace find-or-create) y además
 *    desbloquea registrar un pago en una reserva OTA cuyas facturas aún no han llegado.
 * 2. CANDADO DE MONEDA: una vez que un cargo o pago YA EXISTE en BD (es un UPDATE, no un
 *    INSERT), no se permite cambiar su moneda. Cambiarla rompería la coherencia del rollup
 *    (el importe fue capturado en esa moneda con SU tipo de cambio; mutar la moneda sin
 *    remontar monto/TC dejaría el registro inconsistente). El primer seteo (backfill desde
 *    null) SÍ se permite: no es "cambiar" una moneda ya establecida.
 * 3. CARGOS DE BEDS24 NO BORRABLES: sólo se pueden eliminar los cargos manuales. Borrar uno
 *    sincronizado no serviría (el siguiente pull lo recrearía) y dejaría el saldo desfasado
 *    mientras tanto.
 * 4. ANULACIÓN POR CANCELACIÓN: cuando TODAS las estancias de una reserva pasan a canceladas,
 *    la cabecera se marca `activa = false` y sus cargos dejan de sumar (sólo cuenta la
 *    PENALIZACIÓN). Actúa sólo en la transición, para que el operador pueda reactivarla
 *    —caso del huésped que cancela en la OTA y se pasa a directa— sin que la siguiente
 *    sincronización le pise la decisión (§12.7).
 * 5. RECÁLCULO: cualquier alta/edición/baja de un cargo o pago, o un cambio de moneda en la
 *    propia cabecera, dispara el recálculo de `total_cargos`/`total_pagos` (en la moneda de
 *    la cabecera) vía PmsInformacionFinancieraRecalculoService.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: -1000)]
final class PmsInformacionFinancieraCoherenciaListener
{
    /** @var array<string, true> IDs (string) de cabeceras a recalcular tras el flush. */
    private array $informacionIds = [];

    /** @var array<string, true> IDs de estancias directas nuevas a las que generar cargos. */
    private array $eventosParaCargos = [];

    private bool $isFlushing = false;

    public function __construct(
        private readonly PmsInformacionFinancieraRecalculoService $recalculoService,
        private readonly MonedaResolver $monedaResolver,
        private readonly PmsCargosAutomaticosService $cargosAutomaticos,
        private readonly MonedaBaseRebaseContext $rebaseContext,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->isFlushing) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // 1. CANDADO DE MONEDA — sólo aplica a filas que YA EXISTÍAN (updates).
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsCargoFinanciero || $entity instanceof PmsPagoFinanciero) {
                $changeSet = $uow->getEntityChangeSet($entity);
                $this->assertMonedaNoBloqueada($entity, $changeSet);
                $this->assertTipoCambioNoBloqueado($entity, $changeSet);
            }
        }

        // 2. BORRADO DE CARGOS — sólo los manuales.
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof PmsCargoFinanciero) {
                $this->assertCargoBorrable($entity);
            }
        }

        // 3. AUTO-PROVISIÓN — toda reserva nueva estrena su cabecera financiera.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsReserva) {
                $this->crearCabeceraPara($entity, $em);
            }
        }

        // 4. CANCELACIÓN — al pasar una estancia a cancelada, se anula su cabecera.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsEventoCalendario) {
                $this->aplicarCancelacion($entity, $uow->getEntityChangeSet($entity), $em);
            }
        }

        // 5. CARGOS AUTOMÁTICOS — se anotan las estancias directas nuevas; los cargos se
        //    crean en postFlush, no aquí: calcularlos exige consultar el tarifario, y hacer
        //    esa lectura en mitad del flush es justo lo que Doctrine desaconseja.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsEventoCalendario && $this->cargosAutomaticos->aplica($entity)) {
                $this->eventosParaCargos[(string) $entity->getId()] = true;
            }
        }

        // 6. DETECCIÓN — qué cabeceras necesitan recálculo.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->collectInformacionId($entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->collectInformacionId($entity);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->collectInformacionId($entity);
        }
    }

    /**
     * Crea la cabecera financiera de una reserva recién insertada.
     *
     * Al estar dentro de onFlush hay que avisar al UnitOfWork con computeChangeSet()
     * para que la entidad nueva entre en ESTE mismo flush (mismo patrón que
     * Beds24BookingsPushQueueCreator).
     */
    private function crearCabeceraPara(PmsReserva $reserva, EntityManagerInterface $em): void
    {
        $info = new PmsInformacionFinanciera();
        $info->setReserva($reserva);
        // La moneda base del PMS (USD salvo configuración distinta): los cargos y pagos
        // que lleguen después se convierten a ella (§12.2).
        $info->setMoneda($this->monedaResolver->resolve(null));

        $em->persist($info);
        $em->getUnitOfWork()->computeChangeSet(
            $em->getClassMetadata(PmsInformacionFinanciera::class),
            $info
        );
    }

    /**
     * Anula la cabecera cuando una estancia PASA a cancelada.
     *
     * Se dispara sólo en la TRANSICIÓN (el changeSet trae `estado`), nunca ante un webhook
     * que repite el mismo estado cancelado. Eso es lo que permite que el operador vuelva a
     * marcar la reserva como activa —el caso del huésped que cancela en la OTA para pasarse
     * a directa— sin que la siguiente sincronización le pise la decisión (§12.7).
     *
     * Sólo se anula si TODAS las estancias de la reserva están canceladas: en un grupo, que
     * caiga una casita no anula el cobro de las demás.
     */
    private function aplicarCancelacion(PmsEventoCalendario $evento, array $changeSet, EntityManagerInterface $em): void
    {
        if (!array_key_exists('estado', $changeSet)) {
            return;
        }

        [$old, $new] = $changeSet['estado'];
        $esCancelada = static fn ($e): bool => $e?->getId() === PmsEventoEstado::CODIGO_CANCELADA;

        // Sólo la transición hacia cancelada.
        if ($esCancelada($old) || !$esCancelada($new)) {
            return;
        }

        $reserva = $evento->getReserva();
        if (!$reserva) {
            return;
        }

        foreach ($reserva->getEventosCalendario() as $otro) {
            // El evento en curso ya sabemos que queda cancelado; miramos si alguno sobrevive.
            $estado = $otro === $evento ? $new : $otro->getEstado();
            if (!$esCancelada($estado)) {
                return;
            }
        }

        $info = $em->getRepository(PmsInformacionFinanciera::class)->findOneBy(['reserva' => $reserva]);
        if (!$info instanceof PmsInformacionFinanciera || !$info->isActiva()) {
            return;
        }

        $info->setActiva(false);
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $em->getClassMetadata(PmsInformacionFinanciera::class),
            $info
        );
    }

    /**
     * Un cargo sincronizado desde Beds24 no se puede borrar a mano: el siguiente pull
     * lo recrearía y, mientras tanto, el saldo quedaría desfasado. Los manuales sí.
     */
    private function assertCargoBorrable(PmsCargoFinanciero $cargo): void
    {
        if ($cargo->isManual()) {
            return;
        }

        throw new DomainException(sprintf(
            'No se puede eliminar un cargo sincronizado desde Beds24 (id=%s). '
            . 'Sólo se pueden eliminar los cargos creados manualmente.',
            (string) $cargo->getId()
        ));
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->isFlushing || ($this->informacionIds === [] && $this->eventosParaCargos === [])) {
            return;
        }

        $ids = array_keys($this->informacionIds);
        $eventoIds = array_keys($this->eventosParaCargos);
        $this->informacionIds = [];
        $this->eventosParaCargos = [];

        $this->isFlushing = true;
        try {
            /** @var EntityManagerInterface $em */
            $em = $args->getObjectManager();

            // Los cargos automáticos van ANTES del recálculo, para que el saldo ya los incluya.
            $ids = array_unique([...$ids, ...$this->generarCargosAutomaticos($eventoIds, $em)]);

            $this->recalculoService->recalcular($ids, $em);
        } finally {
            $this->isFlushing = false;
        }
    }

    /**
     * Crea los cargos de las estancias directas recién insertadas.
     *
     * @param string[] $eventoIds
     * @return string[] IDs de las cabeceras tocadas, para incluirlas en el recálculo.
     */
    private function generarCargosAutomaticos(array $eventoIds, EntityManagerInterface $em): array
    {
        if ($eventoIds === []) {
            return [];
        }

        $cabeceras = [];

        foreach ($eventoIds as $eventoId) {
            $evento = $em->find(PmsEventoCalendario::class, $eventoId);
            $reserva = $evento?->getReserva();
            if (!$evento || !$reserva) {
                continue;
            }

            $info = $em->getRepository(PmsInformacionFinanciera::class)->findOneBy(['reserva' => $reserva]);
            if (!$info instanceof PmsInformacionFinanciera) {
                continue;
            }

            $this->cargosAutomaticos->generarParaEvento($evento, $info);
            $cabeceras[] = (string) $info->getId();
        }

        if ($cabeceras !== []) {
            // Este flush vuelve a disparar onFlush, pero `isFlushing` lo corta: por eso el
            // recálculo de estas cabeceras se hace explícito al volver.
            $em->flush();
        }

        return $cabeceras;
    }

    /**
     * Bloquea el cambio de moneda de un cargo/pago ya persistido. `$old === null` significa que
     * la moneda se está estableciendo por primera vez (backfill de filas antiguas sin moneda) y
     * SÍ se permite: no hay conversión previa que romper.
     */
    private function assertMonedaNoBloqueada(PmsCargoFinanciero|PmsPagoFinanciero $entity, array $changeSet): void
    {
        // Cambiar la moneda base reescribe los cargos a propósito (§12.4.4). Es la única
        // excepción, y quien la ejerce lo declara entrando en el contexto de rebase.
        if ($this->rebaseContext->estaRebasando()) {
            return;
        }

        if (!array_key_exists('moneda', $changeSet)) {
            return;
        }

        [$old, $new] = $changeSet['moneda'];

        if ($old === null || $old === $new) {
            return;
        }

        $tipo = $entity instanceof PmsCargoFinanciero ? 'cargo' : 'pago';
        throw new DomainException(sprintf(
            'No se puede cambiar la moneda de un %s financiero ya procesado (id=%s). '
            . 'La moneda queda fija al momento de registrar el importe y su tipo de cambio.',
            $tipo,
            (string) $entity->getId()
        ));
    }

    /**
     * Mismo candado para el tipo de cambio, con UNA excepción deliberada: rellenar el que está
     * vacío sí se permite.
     *
     * Un registro sin TC en moneda distinta a la cabecera aporta **0** al saldo (§12.2). Si el
     * candado fuera total, la única forma de arreglarlo sería borrarlo y rehacerlo — y en un
     * cargo sincronizado desde Beds24 eso ni siquiera es posible.
     */
    private function assertTipoCambioNoBloqueado(PmsCargoFinanciero|PmsPagoFinanciero $entity, array $changeSet): void
    {
        if ($this->rebaseContext->estaRebasando()) {
            return;
        }

        if (!array_key_exists('tipoCambio', $changeSet)) {
            return;
        }

        [$old, $new] = $changeSet['tipoCambio'];

        // null → X es la reparación; X → X es un no-cambio.
        if ($old === null || $old === '' || (float) $old === (float) $new) {
            return;
        }

        $tipo = $entity instanceof PmsCargoFinanciero ? 'cargo' : 'pago';
        throw new DomainException(sprintf(
            'No se puede cambiar el tipo de cambio de un %s financiero que ya lo tenía (id=%s, %s → %s). '
            . 'Es la foto del día en que se registró el importe; corregirlo falsearía el histórico.',
            $tipo,
            (string) $entity->getId(),
            (string) $old,
            (string) $new
        ));
    }

    private function collectInformacionId(object $entity): void
    {
        if ($entity instanceof PmsInformacionFinanciera) {
            $this->informacionIds[(string) $entity->getId()] = true;
            return;
        }

        if ($entity instanceof PmsCargoFinanciero) {
            $info = $entity->getInformacionFinanciera();
            if ($info) {
                $this->informacionIds[(string) $info->getId()] = true;
            }
            return;
        }

        if ($entity instanceof PmsPagoFinanciero) {
            $info = $entity->getInformacionFinanciera();
            if ($info) {
                $this->informacionIds[(string) $info->getId()] = true;
            }
        }
    }
}
