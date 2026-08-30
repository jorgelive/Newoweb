<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\InvoiceReceive;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Exchange\Service\Contract\TargetBookAwareInterface;

final readonly class Beds24InvoiceReceiveMappingStrategy implements MappingStrategyInterface
{
    /**
     * Una sola petición para TODAS las reservas del lote.
     *
     * Beds24 acepta el parámetro repetido (`?bookingId=A&bookingId=B`) y devuelve todo
     * mezclado; cada línea trae su `bookingId`, que es lo que permite repartirlo después.
     *
     * ⚠️ La query se construye a mano en `fullUrl` y NO se pasa por `payload`: el cliente
     * hace `'query' => $payload`, y Symfony serializa un array como `bookingId[0]=…`, que
     * Beds24 no interpreta como parámetro repetido. Encaja igual con el bucle de paginación,
     * porque al saltar de página el cliente sustituye la URL y vacía el payload.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $partes = [];
        $correlacion = [];

        foreach ($batch->getItems() as $job) {
            // El lote es homogéneo, así que en la práctica siempre llega la cola de Beds24. Se
            // comprueba igual porque el contrato del lote es `ExchangeQueueItemInterface`, que
            // NO declara `getTargetBookId()`: una cola de otro canal entraría aquí y moriría con
            // «undefined method» en vez de decir qué pasó.
            if (!$job instanceof TargetBookAwareInterface) {
                continue;
            }

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

        // 🔥 GOTCHA: a diferencia de los mensajes (cuyo 'data' ya es una lista plana), aquí
        // 'data' es una lista de FACTURAS, cada una con su propio arreglo 'invoiceItems'.
        // Se aplana propagando invoiceId / invoiceDate de la factura padre a cada línea (el
        // webhook no los trae). Con varias reservas en la misma petición, Beds24 devuelve un
        // único envoltorio con las líneas de todas mezcladas.
        $porBooking = [];

        foreach ($apiResponse['data'] ?? [] as $factura) {
            if (!is_array($factura)) {
                continue;
            }

            $invoiceId = $factura['invoiceId'] ?? null;
            $invoiceDate = $factura['invoiceDate'] ?? null;

            foreach ($factura['invoiceItems'] ?? [] as $linea) {
                if (!is_array($linea)) {
                    continue;
                }
                $linea['invoiceId']   ??= $invoiceId;
                $linea['invoiceDate'] ??= $invoiceDate;

                // ⚠️ El cast a string es obligatorio: PHP convierte a int las claves
                // numéricas de un array, y `getTargetBookId()` devuelve string. Sin él,
                // la búsqueda de abajo no encuentra nada y TODAS las reservas parecen vacías.
                $porBooking[(string) ($linea['bookingId'] ?? '')][] = $linea;
            }
        }

        // Se recorre la CORRELACIÓN, no el 'data': una reserva sin facturas no deja ningún
        // rastro en la respuesta, y si no le devolviéramos resultado, el orquestador la
        // mandaría a handleFailure() y se reintentaría para siempre. Vacío es un éxito
        // legítimo: significa "esta reserva no tiene facturas".
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
