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

    /**
     * Cargos manuales que hay que llevarse porque su estancia se está borrando.
     *
     * Se anotan en `onFlush` y se retiran en `postFlush`, como todo lo demás en este listener:
     * localizarlos exige una consulta, y hacerla en mitad del flush es lo que Doctrine
     * desaconseja. Hay que anotarlos ANTES de que el borrado llegue a la base, porque el FK
     * `evento_id` es `ON DELETE SET NULL` y después ya no hay forma de saber de quién eran.
     *
     * @var array<string, true> id del cargo => true
     */
    private array $cargosDeEstanciasBorradas = [];

    /**
     * Estancias cuya casilla de horario extra (entrada temprana / salida tardía)
     * cambió en este flush, indexadas por `spl_object_id`.
     *
     * Se guarda la ENTIDAD y no su id: son filas ya existentes y `find()` con el
     * uuid en STRING revienta al bindear el parámetro («Invalid UUID»: el tipo
     * espera el objeto `Uuid`). Con la entidad en mano no hay que volver a buscar
     * nada.
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

            // 🧹 UNA ESTANCIA QUE SE VA SE LLEVA SUS CARGOS.
            //
            // El FK `evento_id` es `ON DELETE SET NULL`, así que hasta ahora los cargos del
            // tarifario de una ampliación retirada sobrevivían sin dueño y seguían sumando al
            // total para siempre. En producción quedaron dos así —«Alojamiento» 130.00 y
            // «Suplemento de limpieza» 15.00 de una reserva de Airbnb— duplicando un cargo que
            // ya había mandado Beds24, y nadie lo vio en cuatro meses porque el depósito
            // espejo persigue al total y el saldo salía a cero por compensación.
            //
            // Se anota AQUÍ y no en postFlush porque después del borrado el FK ya ha puesto
            // `evento_id` a NULL y no hay forma de saber de qué estancia eran.
            //
            // Sólo los MANUALES: los de Beds24 los gobierna el sync y `assertCargoBorrable()`
            // tampoco deja borrarlos: si un cargo del canal cuelga de la estancia que se
            // borra, es el sync quien debe retirarlo, no nosotros.
            if ($entity instanceof PmsEventoCalendario) {
                foreach ($this->cargosManualesDe($entity, $args->getObjectManager()) as $cargo) {
                    $this->cargosDeEstanciasBorradas[(string) $cargo->getId()] = true;
                }
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

        // 5. HORARIO EXTRA (entrada temprana / salida tardía) — las casillas se marcan
        //    EDITANDO una estancia que ya existe. Se anota y se resuelve en postFlush,
        //    en los dos sentidos: al marcar nace el cargo, al desmarcar se retira.
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
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
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
    /**
     * Los cargos MANUALES que cuelgan de esta estancia.
     *
     * Consulta directa y no `$evento->getCargos()`: la entidad no expone esa colección
     * inversa, y añadirla sólo para esto cargaría cargos en cada estancia que se toque.
     *
     * @return list<PmsCargoFinanciero>
     */
    private function cargosManualesDe(PmsEventoCalendario $evento, EntityManagerInterface $em): array
    {
        if ($evento->getId() === null) {
            return [];
        }

        /** @var list<PmsCargoFinanciero> $cargos */
        $cargos = $em->getRepository(PmsCargoFinanciero::class)
            ->findBy(['evento' => $evento]);

        return array_values(array_filter(
            $cargos,
            static fn (PmsCargoFinanciero $c): bool => $c->isManual()
        ));
    }

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
            || ($this->informacionIds === []
                && $this->eventosHorarioExtra === []
                && $this->cargosDeEstanciasBorradas === [])
        ) {
            return;
        }

        $ids = array_keys($this->informacionIds);
        $horarioExtraEventos = array_values($this->eventosHorarioExtra);
        $cargosHuerfanos = array_keys($this->cargosDeEstanciasBorradas);
        $this->informacionIds = [];
        $this->eventosHorarioExtra = [];
        $this->cargosDeEstanciasBorradas = [];

        // 🧹 Los cargos de las estancias que acaban de borrarse. Van los primeros: el
        // recálculo de cabeceras de más abajo tiene que ver el total YA sin ellos, o dejaría
        // el depósito espejo cuadrado contra un importe que incluye lo que se va.
        if ($cargosHuerfanos !== []) {
            $em = $args->getObjectManager();
            $repo = $em->getRepository(PmsCargoFinanciero::class);
            $retirados = 0;

            $this->isFlushing = true;
            try {
                foreach ($cargosHuerfanos as $cargoId) {
                    $cargo = $repo->find($cargoId);

                    // Puede haber desaparecido ya: si el operador borró a la vez la estancia y
                    // sus cargos, el UnitOfWork se los llevó en el mismo flush.
                    if ($cargo instanceof PmsCargoFinanciero) {
                        $ids[] = (string) $cargo->getInformacionFinanciera()?->getId();
                        $em->remove($cargo);
                        $retirados++;
                    }
                }

                if ($retirados > 0) {
                    $em->flush();
                }
            } finally {
                $this->isFlushing = false;
            }

            $ids = array_values(array_unique(array_filter($ids)));
        }

        $this->isFlushing = true;
        try {
            /** @var EntityManagerInterface $em */
            $em = $args->getObjectManager();

            // El horario extra va ANTES del recálculo, para que el saldo ya lo incluya.
            $ids = array_unique([
                ...$ids,
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
     * El depósito automático de una OTA de pago total no se edita por descuido (§12.4.5).
     *
     * Mientras el sistema lo gestiona no es un dato propio: es el reflejo de los cargos, y se
     * mantiene cuadrado en cada recálculo. Editarlo así daría una falsa sensación de control
     * —el siguiente cargo del canal lo devolvería a su sitio—, así que se bloquea con un
     * mensaje que dice dónde está la verdad.
     *
     * 🔓 LA SALIDA: marcar `intervenido`. Entonces el operador se hace cargo del importe y
     * PmsPagoOtaAutomaticoService deja de pisarlo, así que la edición ya no es una promesa
     * falsa y se permite. La condición se pregunta a la ENTIDAD
     * (`isGestionadoPorElSistema()`), no se reconstruye aquí: la SPA necesita la misma regla
     * para saber cuándo pedir el candado.
     *
     * Se lee el estado YA MUTADO del pago, no el changeSet, y es deliberado: así un mismo
     * PATCH puede traer `intervenido: true` junto con el importe nuevo —que es como lo manda
     * el panel— sin tener que llegar en dos viajes.
     *
     * El propio servicio sí lo mueve, y lo declara con `estaSincronizando()`.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function assertPagoAutomaticoNoEditable(PmsPagoFinanciero $pago, array $changeSet): void
    {
        if (!$pago->isGestionadoPorElSistema() || $this->pagoOta->estaSincronizando()) {
            return;
        }

        // ⚠️ ESPEJO de `intervieneAlGuardar()` en ReservaFinanzasPanel.vue, que decide con esta
        // misma lista cuándo el guardado interviene el depósito. Si aquí se añade o quita un
        // campo, hay que tocar allí también: si no, el panel mandará a guardar algo que este
        // método va a rechazar, o intervendrá un depósito sin que hiciera falta.
        $camposBloqueados = ['monto', 'fechaPago', 'medioPago', 'comisionPorcentaje', 'moneda'];
        if (array_intersect($camposBloqueados, array_keys($changeSet)) === []) {
            return; // referencia/notas sí se pueden anotar
        }

        throw new DomainException(
            'Este pago es el depósito automático del canal (Airbnb/VRBO cobran y depositan) y '
            . 'se mantiene cuadrado con los cargos. Si el importe no coincide con lo que entró '
            . 'en la cuenta, corrige los CARGOS de la reserva: el depósito los sigue. Para '
            . 'fijarlo a mano de todas formas, abre el candado del panel: el sistema dejará de '
            . 'cuadrarlo hasta que lo devuelvas al automático.'
        );
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
     *
     * ── Segunda excepción: el registro que todavía vale CERO ────────────────────
     * El candado existe porque «la moneda queda fija al momento de registrar el importe y su
     * tipo de cambio». En un registro que sigue en `0.00` no se ha registrado ningún importe,
     * así que no hay nada fijado y nada que romper: cambiarle la moneda no invalida ninguna
     * foto, porque no hay foto.
     *
     * Y hace falta. Desde el 15/08/2026 una estancia directa nace con una línea en cero, en la
     * moneda de la cabecera —normalmente USD—, para que el operador escriba el precio acordado.
     * Si ese precio se cerró en soles, con el candado total la única salida era borrar la línea
     * y crear otra: cuatro clics para arreglar un campo que nunca había significado nada.
     *
     * ⚠️ Se mira el importe **ANTERIOR**, no el que trae el PATCH. El panel manda importe y
     * moneda en el mismo viaje —«350 soles»—, así que con el importe ya mutado esta excepción
     * no se aplicaría nunca y el candado saltaría justo en el caso para el que se abrió.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
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

        if ($this->importeAnteriorEnCero($entity, $changeSet)) {
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
     * ¿El registro valía CERO antes de este guardado?
     *
     * «Antes» es literal: si el importe viene en el mismo changeSet se toma su valor VIEJO. Es
     * la diferencia entre «esta línea no tenía dinero» y «esta línea no tiene dinero ahora
     * mismo», y sólo la primera justifica soltar el candado de la moneda.
     *
     * Un cargo lleva `totalLinea` y `monto`; un pago sólo `monto`. Se exige que **todo** lo que
     * exista esté en cero: con cualquiera de los dos con importe, ya hay dinero registrado.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function importeAnteriorEnCero(PmsCargoFinanciero|PmsPagoFinanciero $entity, array $changeSet): bool
    {
        $anterior = static function (string $campo, ?string $actual) use ($changeSet): ?string {
            /** @var mixed $viejo */
            $viejo = $changeSet[$campo][0] ?? $actual;

            return is_scalar($viejo) ? (string) $viejo : null;
        };

        $importes = [$anterior('monto', $entity->getMonto())];

        if ($entity instanceof PmsCargoFinanciero) {
            $importes[] = $anterior('totalLinea', $entity->getTotalLinea());
        }

        foreach ($importes as $importe) {
            if ($importe !== null && (float) $importe !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mismo candado para el tipo de cambio, con UNA excepción deliberada: rellenar el que está
     * vacío sí se permite.
     *
     * Un registro sin TC en moneda distinta a la cabecera aporta **0** al saldo (§12.2). Si el
     * candado fuera total, la única forma de arreglarlo sería borrarlo y rehacerlo — y en un
     * cargo sincronizado desde Beds24 eso ni siquiera es posible.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
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
