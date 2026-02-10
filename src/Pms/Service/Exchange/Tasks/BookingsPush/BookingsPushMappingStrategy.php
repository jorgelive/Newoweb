<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use RuntimeException;

/**
 * Estrategia de Mapeo para Subida de Reservas a Beds24 (PUSH).
 * * Maneja la dualidad de la API: DELETE vía Query Params vs UPSERT vía JSON Body.
 * * Incluye lógica para reservas espejo, master IDs y limpieza de arrays para PHP.
 */
final readonly class BookingsPushMappingStrategy implements MappingStrategyInterface
{
    /**
     * Transforma el lote de la cola al formato de transporte HTTP.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // Construcción base de la URL
        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        // =====================================================================
        // ESTRATEGIA: DELETE (Query Params)
        // =====================================================================
        if ($method === 'DELETE') {
            $ids = [];
            foreach ($batch->getItems() as $index => $item) {
                /** @var PmsBookingsPushQueue $item */
                // Prioridad: Snapshot Original (por si el link se borró) -> Link Actual
                $bookId = $item->getBeds24BookIdOriginal() ?? $item->getLink()?->getBeds24BookId();

                if ($bookId) {
                    $ids[] = $bookId;
                    $correlation[$index] = (string) $item->getId();
                }
            }

            if (!empty($ids)) {
                // Beds24 requiere "id=100&id=101", pero http_build_query genera "id[0]=100&id[1]=101"
                // Hack de Regex para eliminar los índices numéricos que rompen la API v2.
                $queryString = http_build_query(['id' => $ids], '', '&', PHP_QUERY_RFC3986);
                $queryString = preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', $queryString);

                $fullUrl .= '?' . $queryString;
            }

            // En DELETE, el body va vacío
            $payload = [];

        } else {
            // =================================================================
            // ESTRATEGIA: UPSERT / POST (JSON Body)
            // =================================================================
            foreach ($batch->getItems() as $index => $item) {
                /** @var PmsBookingsPushQueue $item */
                try {
                    $payload[] = $this->buildUpsertPayload($item);
                    $correlation[$index] = (string) $item->getId();
                } catch (RuntimeException $e) {
                    // Si un ítem está corrupto, lo saltamos pero no detenemos el lote
                    continue;
                }
            }
        }

        return new MappingResult(
            method: $method,
            fullUrl: $fullUrl,
            payload: $payload,
            config: $config,
            correlationMap: $correlation
        );
    }

    /**
     * Procesa la respuesta de la API usando correlación posicional.
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        // Iteramos la respuesta de la API (Beds24 devuelve un array ordenado igual que el input)
        foreach ($apiResponse as $index => $respData) {

            // 1. Recuperar el ID de nuestra cola
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }
            $queueId = $mapping->correlationMap[$index];

            // 2. Determinar éxito/fracaso
            $success = (bool)($respData['success'] ?? false);
            $errorMsg = null;

            if (!$success) {
                // Captura mensajes anidados como "access denied" o validaciones
                $errorMsg = $respData['errors'][0]['message'] ?? $respData['message'] ?? 'Error desconocido';
            }

            // 3. Extraer el ID remoto confirmado
            $remoteId = $respData['new']['id'] ?? $respData['id'] ?? $respData['bookId'] ?? null;

            // 4. Construir el resultado normalizado
            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg,
                remoteId: $remoteId ? (string)$remoteId : null,
                extraData: (array)$respData // Guardamos todo para la auditoría RAW del Handler
            );
        }

        return $results;
    }

    // =========================================================================
    // BUILDERS Y LOGICA DE NEGOCIO
    // =========================================================================

    private function buildUpsertPayload(PmsBookingsPushQueue $queue): array
    {
        $link = $queue->getLink();

        // Validación estricta de integridad
        if (!$link || !$link->getEvento() || !$link->getUnidadBeds24Map()) {
            throw new RuntimeException('Estructura de Link incompleta/corrupta.');
        }

        $evento = $link->getEvento();
        $map = $link->getUnidadBeds24Map();
        $isMirror = $link->isMirror();
        $reserva = $evento->getReserva();

        // 1. Campos Base (Siempre requeridos)
        $payload = [
            'roomId'    => (int) $map->getBeds24RoomId(),
            'status'    => $this->resolveBeds24Status($queue),
            'arrival'   => $evento->getInicio()?->format('Y-m-d'),
            'departure' => $evento->getFin()?->format('Y-m-d'),
            'numAdult'  => (int) ($evento->getCantidadAdultos() ?? 0),
            'numChild'  => (int) ($evento->getCantidadNinos() ?? 0),
        ];

        // 2. ID de Beds24 (Para modificaciones)
        $bookId = $this->toIntOrNull($link->getBeds24BookId())
            ?? $this->toIntOrNull($queue->getBeds24BookIdOriginal());

        if ($bookId !== null) {
            $payload['id'] = $bookId;
        }

        // 3. Datos del Huésped vs Sintéticos (Bloqueos)
        if ($reserva instanceof PmsReserva) {
            $this->mapGuestData($payload, $reserva, $isMirror);

            // Si NO es espejo, enviamos datos financieros y Master ID
            if (!$isMirror) {
                $this->applyMasterIdLogic($payload, $reserva, $bookId);
                $this->mapFinancials($payload, $evento);
            }
        } else {
            $this->mapSyntheticData($payload, $evento);
        }

        // 4. Marcador visual para Espejos
        if ($isMirror && isset($payload['firstName'])) {
            $payload['firstName'] = '(M) ' . $payload['firstName'];
        }

        $payload['comment'] = $isMirror ? 'Reserva espejo' : $this->buildAuditComment($queue);

        return $payload;
    }

    private function mapGuestData(array &$payload, PmsReserva $reserva, bool $isMirror): void
    {
        $this->setIf($payload, 'firstName', $reserva->getNombreCliente());
        $this->setIf($payload, 'lastName',  $reserva->getApellidoCliente());
        $this->setIf($payload, 'email',     $reserva->getEmailCliente());
        $this->setIf($payload, 'phone',     $reserva->getTelefono());
        $this->setIf($payload, 'mobile',    $reserva->getTelefono2());
        $this->setIf($payload, 'notes',     $reserva->getNota());
        $this->setIf($payload, 'comments',  $reserva->getComentariosHuesped());
        $this->setIf($payload, 'lang',      $reserva->getIdioma()?->getId());
        $this->setIf($payload, 'country2',  $reserva->getPais()?->getId());

        // Referencias de Canal (Solo si no es espejo, para no confundir al Channel Manager)
        if (!$isMirror) {
            $this->setIf($payload, 'apiReference', $reserva->getReferenciaCanal());
            $this->setIf($payload, 'channel', $reserva->getChannel()?->getBeds24ChannelId());
        }
    }

    private function mapFinancials(array &$payload, PmsEventoCalendario $evento): void
    {
        $this->setIf($payload, 'price',      $evento->getMonto());
        $this->setIf($payload, 'commission', $evento->getComision());
    }

    private function mapSyntheticData(array &$payload, PmsEventoCalendario $evento): void
    {
        $estado = $evento->getEstado()?->getNombre() ?? 'Bloqueo';
        $payload['firstName'] = 'Evento (' . $estado . ')';
    }

    private function resolveBeds24Status(PmsBookingsPushQueue $queue): string
    {
        // Si el endpoint es explícitamente DELETE, forzamos status cancelled
        if ($queue->getEndpoint()?->getMetodo() === 'DELETE') {
            return 'cancelled';
        }

        $link = $queue->getLink();
        $codigo = $link?->getEvento()?->getEstado()?->getCodigoBeds24();

        return $codigo ?: 'confirmed';
    }

    private function buildAuditComment(PmsBookingsPushQueue $queue): string
    {
        $resId = $queue->getLink()?->getEvento()?->getReserva()?->getId();
        return $resId ? 'PMS#' . $resId : 'PMS Update';
    }

    /**
     * Lógica para agrupar reservas (Group Booking).
     * Evita asignar masterId si es igual al ID actual (referencia circular).
     */
    private function applyMasterIdLogic(array &$payload, PmsReserva $reserva, ?int $currentId): void
    {
        if (!method_exists($reserva, 'getBeds24MasterId')) {
            return;
        }

        $mId = $this->toIntOrNull($reserva->getBeds24MasterId());

        // Solo enviamos masterId si es diferente al ID de la reserva actual
        if ($mId && $mId !== $currentId) {
            $payload['masterId'] = $mId;
        }
    }

    // --- Helpers de Sanitización ---

    private function setIf(array &$arr, string $key, mixed $val): void
    {
        if ($val === null) return;
        $s = trim((string)$val);
        if ($s !== '') $arr[$key] = $s;
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if (is_int($v)) return $v;
        $s = trim((string)$v);
        return (is_numeric($s) && $s !== '') ? (int)$s : null;
    }
}