<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Entity\PmsBookingsPushQueue;
use DateTimeImmutable;
use Throwable;

/**
 * Handler estandarizado para la subida de reservas (Crear/Actualizar/Borrar).
 */
final class BookingsPushHandler implements ExchangeHandlerInterface
{
    /**
     * Procesa el éxito de la petición.
     * @return array El resumen de ejecución para la columna 'execution_result'.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof PmsBookingsPushQueue) {
            return ['status' => 'error', 'message' => 'Entidad no compatible'];
        }

        $link = $item->getLink();

        // 1. Extraer ID remoto de la respuesta de Beds24 v2
        // Buscamos en varios niveles por si es un 'new' o un update estándar
        $remoteId = $data['id'] ?? $data['bookId'] ?? $data['new']['id'] ?? null;
        $success = $data['success'] ?? true;

        if ($success) {
            // 2. Persistencia en el LINK (si aún existe en nuestra DB)
            if ($link) {
                // Guardamos el ID de base de datos del link en la cola (tu petición)
                // Esto es redundante pero seguro: linkIdOriginal se llena al setear el link,
                // pero lo reforzamos aquí por si acaso.
                $item->setLinkIdOriginal($link->getId());

                if ($remoteId) {
                    $strRemoteId = (string)$remoteId;

                    // Actualizamos el bookId en el Link principal
                    if ($strRemoteId !== $link->getBeds24BookId()) {
                        $link->setBeds24BookId($strRemoteId);
                    }

                    // IMPORTANTE: Sincronizamos el bookId en la QUEUE
                    // Esto garantiza que si el link se borra, la cola sabe a quién cancelar en Beds24
                    $item->setBeds24BookIdOriginal($strRemoteId);
                }

                $link->setLastSeenAt(new \DateTimeImmutable());
            }
            // 3. Caso especial: Si el link ya se borró pero la API nos devolvió un ID
            elseif ($remoteId) {
                $item->setBeds24BookIdOriginal((string)$remoteId);
            }
        }

        // 4. Marcamos éxito
        $item->markSuccess(new \DateTimeImmutable());

        return [
            'status' => $success ? 'success' : 'api_error',
            'remote_id' => $remoteId,
            'link_id' => $item->getLinkIdOriginal(), // Usamos el ID original guardado
            'msg' => $data['message'] ?? 'Procesado correctamente'
        ];
    }

    /**
     * Gestiona fallos técnicos.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        // Reintento en 5 minutos para Push (evitar saturación en errores de red)
        $nextRetry = new DateTimeImmutable('+5 minutes');

        $item->markFailure(
            reason: mb_substr($e->getMessage(), 0, 255),
            httpCode: (int) $e->getCode() ?: 500,
            nextRetry: $nextRetry
        );
    }
}