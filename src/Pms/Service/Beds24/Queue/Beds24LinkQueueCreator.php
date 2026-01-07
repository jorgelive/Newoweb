<?php

namespace App\Pms\Service\Beds24\Queue;

use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsBeds24LinkQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Service\Beds24\Sync\SyncContext;

final class Beds24LinkQueueCreator
{
    private const ENDPOINT_POST_BOOKINGS   = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS';

    public function __construct(
        private readonly SyncContext $syncContext,
    ) {}

    /**
     * Registra (si corresponde) una intención de sincronización hacia Beds24
     * para un LINK y un endpoint específico.
     *
     * 🔑 REGLAS DE NEGOCIO IMPORTANTES:
     *
     * UI:
     *  - Se permite POST / DELETE normales.
     *
     * PULL:
     *  - Caso A: crear MIRRORS nuevos (lock de unidades espejo)
     *  - Caso B: si cambia un ROOT, propagar UPDATE a MIRRORS existentes
     *
     * ❌ Nunca:
     *  - Actualizar ROOT en PULL
     *  - Re-enviar cambios al mismo booking origen
     */
    public function enqueueForLink(
        PmsEventoBeds24Link $link,
        PmsBeds24Endpoint $endpoint
    ): void {

        // ==============================================================
        // 🧠 POLÍTICA GLOBAL PARA PULL
        // ==============================================================
        if ($this->syncContext->isPull()) {

            // ❌ En PULL solo se permiten POST_BOOKINGS
            if ($endpoint->getAccion() !== self::ENDPOINT_POST_BOOKINGS) {
                return;
            }

            /**
             * Caso A — creación de MIRRORS nuevos
             *
             * - link SIN beds24BookId
             * - típicamente mirrors recién creados
             */
            if ($link->getBeds24BookId() === null || $link->getBeds24BookId() === '') {
                // permitido → sigue
            }
            /**
             * Caso B — propagación ROOT → MIRRORS
             *
             * - link YA EXISTE en Beds24
             * - PERO debe ser MIRROR
             * - y el cambio proviene del ROOT (evento compartido)
             */
            else {
                if (!$link->isMirror()) {
                    // ❌ ROOT nunca se actualiza en PULL
                    return;
                }
                // ✅ MIRROR existente → permitido (propagación)
            }
        }

        // ==============================================================
        // Guards mínimos
        // ==============================================================
        $evento = $link->getEvento();
        if (!$evento) {
            return;
        }

        $map = $link->getUnidadBeds24Map();
        if ($map === null) {
            return;
        }

        // ==============================================================
        // Resolución para dedupe
        // ==============================================================
        $isNewLink     = ($link->getId() === null);
        $originLink   = $link->getOriginLink();
        $originBookId = null;

        if ($originLink !== null) {
            $originBookId = trim((string) $originLink->getBeds24BookId()) ?: null;
        }

        if ($isNewLink && $this->syncContext->isPull()) {
            // En PULL, links nuevos SOLO si son mirrors
            if ($originBookId === null) {
                return;
            }
        }

        // ==============================================================
        // DEDUPE KEY (determinista)
        // ==============================================================
        if ($isNewLink) {
            if ($this->syncContext->isPull()) {
                $mapKey = $map->getId() ?? ('obj' . spl_object_id($map));
                $dedupeKey = sprintf(
                    'mirror:originBookId:%s:map:%s:endpoint:%s',
                    $originBookId,
                    $mapKey,
                    $endpoint->getAccion()
                );
            } else {
                $dedupeKey = sprintf(
                    'ui:event:%s:map:%d:endpoint:%s',
                    $evento->getCreated()?->format('c') ?? 'null',
                    (int) $map->getId(),
                    $endpoint->getAccion()
                );
            }
        } else {
            $dedupeKey = sprintf(
                'link:%d:endpoint:%s',
                $link->getId(),
                $endpoint->getAccion()
            );
        }

        // ==============================================================
        // Payload snapshot (para hash)
        //
        // ⚠️ IMPORTANTE (hash semantics):
        //
        // Este payload NO se envía a Beds24.
        // Se usa EXCLUSIVAMENTE para calcular payloadHash y decidir:
        //   - si una cola existente debe reactivarse
        //   - o si no hay cambios relevantes
        //
        // EFECTOS COLATERALES CONTROLADOS:
        //
        // 1) Incluir más campos ⇒ más colas se reactivan
        // 2) Excluir campos ⇒ cambios silenciosos (no sync)
        //
        // Regla de negocio:
        // - DIRECTAS  → hash incluye datos de cabecera (propagan cambios humanos)
        // - NO directas (OTA) → hash TAMBIÉN incluye cabecera
        //   ⚠️ Esto implica que cambios MANUALES en OTA reactivarán colas,
        //      lo cual es DESEADO para auditoría y consistencia,
        //      pero debe asumirse conscientemente.
        //
        // Si en el futuro se quiere “congelar” OTAs:
        // - basta con retirar estos campos del hash cuando !isDirecto()
        // ==============================================================
        $payload = [
            // Identidad lógica
            'linkId'       => $link->getId(),
            'isMirror'     => $link->isMirror(),

            // Evento (fuente de verdad de fechas / estado)
            'eventoId'     => $evento->getId(),
            'inicio'       => $evento->getInicio()?->format('c'),
            'fin'          => $evento->getFin()?->format('c'),
            'estado'       => $evento->getEstado()?->getCodigo(),

            // Destino Beds24
            'beds24RoomId' => $map->getBeds24RoomId(),
            'configId'     => $map->getBeds24Config()?->getId(),
        ];

        // --------------------------------------------------------------
        // 🧾 CABECERA DE RESERVA (DIRECTAS Y NO DIRECTAS)
        //
        // Se incluye en el hash para:
        // - permitir auditoría histórica
        // - reactivar colas cuando datos humanos cambian
        //
        // ⚠️ NO implica que el builder envíe estos campos
        //    (el builder sigue aplicando reglas: mirrors, OTAs, etc.)
        // --------------------------------------------------------------
        $reserva = $evento->getReserva();

        if ($reserva !== null) {
            $datosLocked = (bool) ($reserva->isDatosLocked() ?? false);

            // Siempre incluimos el flag para que el hash cambie cuando se bloquea/desbloquea.
            $payload['datosLocked'] = $datosLocked;

            // Cuando está locked, NO queremos que los campos sensibles participen del hash.
            // Cuando está unlocked, SÍ participan para reactivar colas si cambian datos humanos.
            if (!$datosLocked) {
                $payload['cliente'] = [
                    'nombre'    => $reserva->getNombreCliente(),
                    'apellido'  => $reserva->getApellidoCliente(),
                    'telefono'  => $reserva->getTelefono(),
                    'telefono2' => $reserva->getTelefono2(),
                    'email'     => $reserva->getEmailCliente(),
                    'nota'      => $reserva->getNota(),
                    'canal'     => $reserva->getChannel()?->getCodigo(),
                ];
            } else {
                // En locked dejamos solo campos no sensibles (si te sirve para auditoría sin reactivar por cambios humanos)
                $payload['cliente'] = [
                    'canal' => $reserva->getChannel()?->getCodigo(),
                ];
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = sha1((string) $json);

        // ==============================================================
        // DEDUPE REAL
        // ==============================================================
        foreach ($link->getQueues() as $queue) {
            if ($queue->getDedupeKey() !== $dedupeKey) {
                continue;
            }

            $queue->setLink($link);
            $queue->setBeds24Config($map->getBeds24Config());

            if ($queue->getPayloadHash() === $payloadHash) {
                return;
            }

            $queue
                ->setPayloadHash($payloadHash)
                ->setNeedsSync(true)
                ->setStatus(PmsBeds24LinkQueue::STATUS_PENDING)
                ->setLastMessage(null)
                ->setLastHttpCode(null)
                ->setNextRetryAt(null);

            return;
        }

        // ==============================================================
        // Crear nueva cola
        // ==============================================================
        $queue = new PmsBeds24LinkQueue();
        $queue
            ->setLink($link)
            ->setBeds24Config($map->getBeds24Config())
            ->setEndpoint($endpoint)
            ->setDedupeKey($dedupeKey)
            ->setPayloadHash($payloadHash)
            ->setNeedsSync(true)
            ->setStatus(PmsBeds24LinkQueue::STATUS_PENDING);

        $link->addQueue($queue);
    }


    /**
     * Cancela las colas POST pendientes para un link cuando se elimina localmente
     * antes de tener un beds24BookId, para evitar enviar un create que ya no sería válido.
     */
    public function cancelPendingPostForLink(PmsEventoBeds24Link $link, ?string $reason = null): void
    {
        foreach ($link->getQueues() as $queue) {
            if (!$queue instanceof PmsBeds24LinkQueue) {
                continue;
            }

            $accion = $queue->getEndpoint()?->getAccion();
            if ($accion !== self::ENDPOINT_POST_BOOKINGS) {
                continue;
            }

            if ($queue->isNeedsSync() !== true) {
                continue;
            }

            $status = $queue->getStatus();
            if (!in_array($status, [PmsBeds24LinkQueue::STATUS_PENDING, PmsBeds24LinkQueue::STATUS_PROCESSING], true)) {
                continue;
            }

            $queue->setLink($link);
            $queue
                ->setNeedsSync(false)
                ->setStatus(PmsBeds24LinkQueue::STATUS_CANCELLED)
                ->setLastStatus(PmsBeds24LinkQueue::STATUS_CANCELLED)
                ->setLastMessage(sprintf(
                    'Cancelled POST_BOOKINGS queue: %s',
                    $reason ?: 'no beds24BookId to delete'
                ))
                ->setNextRetryAt(null);
        }
    }
}