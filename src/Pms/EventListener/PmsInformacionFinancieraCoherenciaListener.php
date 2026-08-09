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
use App\Pms\Service\Reserva\PmsExtensionEstanciaService;
use App\Pms\Service\Finance\PmsEstadoPagoEventosService;
use App\Pms\Service\Finance\PmsPagoOtaAutomaticoService;
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

    /**
     * Estancias cuya casilla de horario extra (entrada temprana / salida tardía)
     * cambió en este flush, indexadas por `spl_object_id`.
     *
     * Se guarda la ENTIDAD y no su id, a diferencia de `eventosParaCargos`: allí
     * los eventos acaban de insertarse y `find()` los resuelve del identity map,
     * pero aquí son filas ya existentes y `find()` con el uuid en STRING revienta
     * al bindear el parámetro («Invalid UUID»: el tipo espera el objeto `Uuid`).
     * Con la entidad en mano no hay que volver a buscar nada.
     *
     * @var array<int,PmsEventoCalendario>
     */
    private array $eventosHorarioExtra = [];

    private bool $isFlushing = false;

    public function __construct(
        private readonly PmsInformacionFinancieraRecalculoService $recalculoService,
        private readonly MonedaResolver $monedaResolver,
        private readonly PmsCargosAutomaticosService $cargosAutomaticos,
        private readonly PmsExtensionEstanciaService $extensiones,
        private readonly MonedaBaseRebaseContext $rebaseContext,
        private readonly PmsPagoOtaAutomaticoService $pagoOta,
        private readonly PmsEstadoPagoEventosService $estadoPagoService,
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

                if ($entity instanceof PmsPagoFinanciero) {
                    $this->assertPagoAutomaticoNoEditable($entity, $changeSet);
                }
            }
        }

        // 2. BORRADO DE CARGOS — sólo los manuales.
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof PmsCargoFinanciero) {
                $this->assertCargoBorrable($entity);
            }
            // Borrar el depósito automático no sirve de nada: el siguiente recálculo lo
            // recrearía. Se bloquea para que el operador no pelee contra el sistema.
            //
            // El MOTIVO lo decide la entidad (`getMotivoNoBorrable()`), no este listener:
            // la SPA necesita la misma regla para no pintar un basurero que sólo puede
            // fallar, y con la condición escondida aquí no tenía forma de conocerla.
            if ($entity instanceof PmsPagoFinanciero && !$this->pagoOta->estaSincronizando()) {
                $motivo = $entity->getMotivoNoBorrable();

                if ($motivo !== null) {
                    throw new DomainException($motivo);
                }
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

        // 5.b HORARIO EXTRA (entrada temprana / salida tardía) — las casillas se
        //     marcan EDITANDO una estancia que ya existe, así que este caso no lo
        //     cubre el barrido de inserciones de arriba. Se anota y se resuelve en
        //     postFlush, en los dos sentidos: al marcar nace el cargo, al desmarcar
        //     se retira.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof PmsEventoCalendario) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entity);

            // También al cambiar el ESTADO: cancelar una estancia tiene que llevarse
            // su extensión —una estancia cancelada no ocupa nada—, y reactivarla, si
            // la casilla sigue marcada, la devuelve. Mirando sólo las casillas, la
            // noche bloqueada sobrevivía a la cancelación.
            if (array_key_exists('salidaTardia', $cambios)
                || array_key_exists('entradaTemprana', $cambios)
                || array_key_exists('estado', $cambios)
            ) {
                $this->eventosHorarioExtra[spl_object_id($entity)] = $entity;
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
        if ($this->isFlushing
            || ($this->informacionIds === [] && $this->eventosParaCargos === [] && $this->eventosHorarioExtra === [])
        ) {
            return;
        }

        $ids = array_keys($this->informacionIds);
        $eventoIds = array_keys($this->eventosParaCargos);
        $horarioExtraEventos = array_values($this->eventosHorarioExtra);
        $this->informacionIds = [];
        $this->eventosParaCargos = [];
        $this->eventosHorarioExtra = [];

        $this->isFlushing = true;
        try {
            /** @var EntityManagerInterface $em */
            $em = $args->getObjectManager();

            // Los cargos automáticos van ANTES del recálculo, para que el saldo ya los incluya.
            $ids = array_unique([
                ...$ids,
                ...$this->generarCargosAutomaticos($eventoIds, $em),
                ...$this->sincronizarHorariosExtra($horarioExtraEventos, $em),
            ]);

            $this->recalculoService->recalcular($ids, $em);

            // El depósito de las OTA de pago total va DESPUÉS del recálculo: su importe es el
            // total de cargos, que hasta aquí no se conoce. Si toca algo hay que recalcular
            // otra vez, porque acaba de cambiar `total_pagos` (§12.8).
            if ($this->sincronizarPagosOta($ids, $em)) {
                $this->recalculoService->recalcular($ids, $em);
            }

            // Y con los totales ya definitivos, el estado de pago de cada estancia.
            // Va el ÚLTIMO a propósito: lee `total_cargos`/`total_pagos`, así que
            // cualquier paso que los mueva tiene que haber terminado antes (§12.9).
            $this->estadoPagoService->sincronizar($ids, $em);
        } finally {
            $this->isFlushing = false;
        }
    }

    /**
     * Cuadra el depósito automático de las reservas de Airbnb/VRBO.
     *
     * @param string[] $ids
     * @return bool true si alguna cabecera cambió y hay que rehacer el rollup
     */
    private function sincronizarPagosOta(array $ids, EntityManagerInterface $em): bool
    {
        if ($ids === []) {
            return false;
        }

        $tocado = false;

        foreach ($ids as $id) {
            $info = $em->getRepository(PmsInformacionFinanciera::class)->find($id);
            if (!$info instanceof PmsInformacionFinanciera) {
                continue;
            }

            // El rollup es SQL crudo: la entidad en memoria puede traer totales viejos.
            $em->refresh($info);

            if ($this->pagoOta->sincronizar($info)) {
                $tocado = true;
            }
        }

        if ($tocado) {
            $em->flush();
        }

        return $tocado;
    }

    /**
     * El depósito automático de una OTA de pago total NO se edita a mano (§12.8).
     *
     * No es un dato propio: es el reflejo de los cargos, y el sistema lo mantiene cuadrado en
     * cada recálculo. Permitir editarlo daría una falsa sensación de control —el siguiente
     * cargo que llegara del canal lo devolvería a su sitio— así que se bloquea con un mensaje
     * que dice dónde está la verdad.
     *
     * El propio servicio sí lo mueve, y lo declara con `estaSincronizando()`.
     */
    private function assertPagoAutomaticoNoEditable(PmsPagoFinanciero $pago, array $changeSet): void
    {
        if (!$pago->isEsAutomatico() || $this->pagoOta->estaSincronizando()) {
            return;
        }

        $camposBloqueados = ['monto', 'fechaPago', 'medioPago', 'comisionPorcentaje', 'moneda'];
        if (array_intersect($camposBloqueados, array_keys($changeSet)) === []) {
            return; // referencia/notas sí se pueden anotar
        }

        throw new DomainException(
            'Este pago es el depósito automático del canal (Airbnb/VRBO cobran y depositan) y '
            . 'se mantiene cuadrado con los cargos. Si el importe no coincide con lo que entró '
            . 'en la cuenta, corrige los CARGOS de la reserva: el depósito los sigue.'
        );
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
     * Crea o retira los cargos de horario extra de las estancias cuya casilla cambió.
     *
     * @param PmsEventoCalendario[] $eventos
     * @return string[] IDs de las cabeceras tocadas, para incluirlas en el recálculo.
     */
    private function sincronizarHorariosExtra(array $eventos, EntityManagerInterface $em): array
    {
        if ($eventos === []) {
            return [];
        }

        $cabeceras = [];

        foreach ($eventos as $evento) {
            $reserva = $evento->getReserva();
            if (!$reserva) {
                continue;
            }

            $info = $em->getRepository(PmsInformacionFinanciera::class)->findOneBy(['reserva' => $reserva]);
            if (!$info instanceof PmsInformacionFinanciera) {
                continue;
            }

            // Las dos caras del horario extra: la noche bloqueada (un evento
            // hermano invisible) y su cargo en 0.00.
            $this->extensiones->sincronizar($evento);
            $this->cargosAutomaticos->sincronizarExtras($evento, $info);
            $cabeceras[] = (string) $info->getId();
        }

        if ($cabeceras !== []) {
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
