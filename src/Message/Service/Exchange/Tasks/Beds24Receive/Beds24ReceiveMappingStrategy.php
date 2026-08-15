<?php
declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24ReceiveMappingStrategy implements MappingStrategyInterface
{
    /**
     * Una sola petición para TODAS las reservas del lote.
     *
     * Beds24 acepta el parámetro repetido (`?bookingId=A&bookingId=B`) y devuelve los
     * mensajes de todas mezclados en un `data` plano; cada mensaje trae su `bookingId`,
     * que es lo que permite repartirlos después.
     *
     * ⚠️ La query se construye a mano en `fullUrl` y NO se pasa por `payload`: el cliente
     * hace `'query' => $payload`, y Symfony serializa un array como `bookingId[0]=…`, que
     * Beds24 no interpreta como parámetro repetido. Encaja igual con el bucle de paginación
     * de Beds24ExchangeClient, porque al saltar de página sustituye la URL y vacía el payload.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $partes = [];
        $correlacion = [];

        foreach ($batch->getItems() as $job) {
            $bookId = (string) $job->getTargetBookId();
            if ($bookId === '') {
                continue;
            }
            $partes[] = 'bookingId=' . rawurlencode($bookId);
            // Lista y no valor único: dos ítems en cola podrían apuntar al mismo booking.
            $correlacion[$bookId][] = (string) $job->getId();
        }

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string) $endpoint->getEndpoint(), '/');
        if ($partes !== []) {
            $fullUrl .= '?' . implode('&', $partes);
        }

        return new MappingResult(
            method: (string) $endpoint->getMetodo(),
            fullUrl: $fullUrl,
            payload: [],
            config: $config,
            correlationMap: $correlacion
        );
    }

    /**
     * @param array<string, mixed> $apiResponse
     * @return array<string, mixed>
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        /** @var array<string, string[]> $correlacion bookingId => ids de cola */
        $correlacion = $mapping->correlationMap;

        // Error estructurado desde la API: falla el lote entero, no hay nada que repartir.
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            $msg = $apiResponse['message'] ?? 'Error desconocido desde Beds24';
            $fallos = [];
            foreach ($correlacion as $jobIds) {
                foreach ($jobIds as $jobId) {
                    $fallos[$jobId] = new ItemResult($jobId, false, $msg);
                }
            }
            return $fallos;
        }

        // A diferencia de las facturas (cuyo 'data' anida invoiceItems dentro de cada
        // factura), aquí 'data' ya es la lista plana de mensajes.
        $porBooking = [];

        foreach ($apiResponse['data'] ?? [] as $mensaje) {
            if (!is_array($mensaje)) {
                continue;
            }
            // ⚠️ El cast a string es obligatorio: PHP convierte a int las claves numéricas
            // de un array, y `getTargetBookId()` devuelve string. Sin él, la búsqueda de
            // abajo no encuentra nada y TODAS las reservas parecen no tener mensajes.
            $porBooking[(string) ($mensaje['bookingId'] ?? '')][] = $mensaje;
        }

        // Se recorre la CORRELACIÓN, no el 'data': una reserva sin mensajes no deja ningún
        // rastro en la respuesta, y si no le devolviéramos resultado, el orquestador la
        // mandaría a handleFailure() y se reintentaría para siempre. Vacío es un éxito
        // legítimo: significa "esta reserva no tiene mensajes".
        $resultados = [];
        foreach ($correlacion as $bookId => $jobIds) {
            foreach ($jobIds as $jobId) {
                $resultados[$jobId] = new ItemResult(
                    queueItemId: $jobId,
                    success: true,
                    message: null,
                    remoteId: null,
                    extraData: $porBooking[(string) $bookId] ?? []
                );
            }
        }

        return $resultados;
    }
}
