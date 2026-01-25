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

final readonly class BookingsPushMappingStrategy implements MappingStrategyInterface
{
    /**
     * Transforma el lote de la cola al formato de Beds24.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        // --- Lógica específica para DELETE (Estándar URL Params) ---
        if ($method === 'DELETE') {
            $ids = [];
            foreach ($batch->getItems() as $index => $item) {
                /** @var PmsBookingsPushQueue $item */
                $bookId = $item->getBeds24BookIdOriginal() ?? $item->getLink()?->getBeds24BookId();

                if ($bookId) {
                    $ids[] = $bookId;
                    $correlation[$index] = $item->getId();
                }
            }

            if (!empty($ids)) {
                // Generamos la query string: id=81073767&id=81073768
                // Usamos un pequeño truco con preg_replace porque http_build_query
                // por defecto añade índices numéricos [0], [1] que Beds24 v2 no siempre procesa bien.
                $queryString = http_build_query(['id' => $ids], '', '&', PHP_QUERY_RFC3986);
                $queryString = preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', $queryString);

                $fullUrl .= '?' . $queryString;
            }

            // Para DELETE, el payload (body) debe ir vacío según el estándar REST y Beds24 v2
            $payload = [];

        } else {
            // --- Lógica para UPSERT/POST (Estándar JSON Body) ---
            foreach ($batch->getItems() as $index => $item) {
                /** @var PmsBookingsPushQueue $item */
                try {
                    $payload[] = $this->buildUpsertPayload($item);
                    $correlation[$index] = $item->getId();
                } catch (RuntimeException $e) {
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
     * Compatible con éxitos parciales y fallos tipo "access denied".
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        foreach ($apiResponse as $index => $respData) {
            // 1. Buscamos el ID de nuestra base de datos usando la posición
            if (!isset($mapping->correlationMap[$index])) continue;
            $queueId = $mapping->correlationMap[$index];

            // 2. ¿Beds24 dice que falló?
            $success = (bool)($respData['success'] ?? false);
            $errorMsg = null;

            if (!$success) {
                // Extraemos "access denied" del array de errores
                $errorMsg = $respData['errors'][0]['message'] ?? $respData['message'] ?? 'Error desconocido';
            }

            // 3. Extraemos el ID que nos dio Beds24 (solo si tuvo éxito)
            $remoteId = $respData['new']['id'] ?? $respData['id'] ?? $respData['bookId'] ?? null;

            // 4. Creamos el resultado SIN que explote el sistema
            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg, // Aquí se guardará "access denied"
                remoteId: $remoteId ? (string)$remoteId : null,
                extraData: (array)$respData
            );
        }

        return $results;
    }

    // --- Builders de Payload y Helpers ---

    private function buildUpsertPayload(PmsBookingsPushQueue $queue): array
    {
        $link = $queue->getLink();
        if (!$link || !$link->getEvento() || !$link->getUnidadBeds24Map()) {
            throw new RuntimeException('Estructura de Link incompleta.');
        }

        $evento = $link->getEvento();
        $map = $link->getUnidadBeds24Map();
        $isMirror = $link->isMirror();
        $reserva = $evento->getReserva();

        $payload = [
            'roomId'    => (int) $map->getBeds24RoomId(),
            'status'    => $this->resolveBeds24Status($queue),
            'arrival'   => $evento->getInicio()?->format('Y-m-d'),
            'departure' => $evento->getFin()?->format('Y-m-d'),
            'numAdult'  => (int) ($evento->getCantidadAdultos() ?? 0),
            'numChild'  => (int) ($evento->getCantidadNinos() ?? 0),
        ];

        // ID para actualizaciones
        $bookId = $this->toIntOrNull($link->getBeds24BookId()) ?? $this->toIntOrNull($queue->getBeds24BookIdOriginal());
        if ($bookId !== null) {
            $payload['id'] = $bookId;
        }

        // Datos del huésped o bloqueo
        if ($reserva instanceof PmsReserva) {
            $this->mapGuestData($payload, $reserva, $isMirror);
            if (!$isMirror) {
                $this->applyMasterIdLogic($payload, $reserva, $bookId);
                $this->mapFinancials($payload, $evento);
            }
        } else {
            $this->mapSyntheticData($payload, $evento);
        }

        // Trazabilidad Mirror
        if ($isMirror && isset($payload['firstName'])) {
            $payload['firstName'] = '(M) ' . $payload['firstName'];
        }

        $payload['comment'] = $isMirror ? 'Reserva espejo' : $this->buildAuditComment($queue);

        return $payload;
    }

    private function buildDeletePayload(PmsBookingsPushQueue $queue): array
    {
        $bookId = $queue->getBeds24BookIdOriginal() ?? $queue->getLink()?->getBeds24BookId();
        if (!$bookId) throw new RuntimeException('Falta ID para DELETE');

        return [
            'id'      => (int) $bookId,
            'status'  => 'cancelled',
            'comment' => 'Cancelled from PMS',
        ];
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
        $this->setIf($payload, 'lang',      $reserva->getIdioma()?->getCodigo());
        $this->setIf($payload, 'country2',  $reserva->getPais()?->getIso2());

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
        if ($queue->getEndpoint()?->getMetodo() === 'DELETE') return 'cancelled';

        $link = $queue->getLink();
        $codigo = $link?->getEvento()?->getEstado()?->getCodigoBeds24();

        return $codigo ?: 'confirmed';
    }

    private function buildAuditComment(PmsBookingsPushQueue $queue): string
    {
        $resId = $queue->getLink()?->getEvento()?->getReserva()?->getId();
        return $resId ? 'PMS#' . $resId : 'PMS Update';
    }

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

    private function applyMasterIdLogic(array &$payload, PmsReserva $reserva, ?int $currentId): void
    {
        if (!method_exists($reserva, 'getBeds24MasterId')) return;
        $mId = $this->toIntOrNull($reserva->getBeds24MasterId());
        if ($mId && $mId !== $currentId) $payload['masterId'] = $mId;
    }
}