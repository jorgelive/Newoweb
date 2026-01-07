<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Pms\Entity\PmsBeds24LinkQueue;
use App\Pms\Service\Beds24\Client\Beds24BookingsPostDeleteClient;
use App\Pms\Service\Beds24\Sync\Push\Beds24BookingsPushPayloadBuilder;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class Beds24LinkQueueProcessor
{
    private const ACCION_POST = 'POST_BOOKINGS';
    private const ACCION_DELETE = 'DELETE_BOOKINGS';

    public function __construct(
        private readonly Beds24BookingsPostDeleteClient   $client,
        private readonly Beds24BookingsPushPayloadBuilder $payloadBuilder,
        private readonly EntityManagerInterface           $em,
    ) {}

    /**
     * Procesa un batch YA “claim-eado” y bloqueado por el repository.
     *
     * Diseño:
     * - agrupa por (Beds24Config + Accion del endpoint)
     * - manda 1 request por grupo
     * - Beds24 responde por item (éxito parcial) => marcamos success/failure por queue
     *
     * Importante:
     * - El repository hace el lock con SQL para evitar carreras (workers).
     * - Aquí repetimos markProcessing en ORM para consistencia interna (admin/UI/otras lecturas por ORM).
     *
     * @param PmsBeds24LinkQueue[] $queues
     */
    public function processBatch(array $queues, DateTimeImmutable $now, string $workerId): int
    {
        if ($queues === []) {
            return 0;
        }

        // ------------------------------------------------------------------
        // 1) Marcar processing (ORM) - aunque SQL ya hizo status='processing'
        // Esto mantiene consistencia si otros componentes leen el estado vía ORM.
        // ------------------------------------------------------------------
        foreach ($queues as $q) {
            $q->markProcessing($workerId, $now);
        }

        // ------------------------------------------------------------------
        // 2) Agrupar por config + acción
        //
        // Nota futura:
        // - si mañana quieres respetar un límite de N items por request,
        //   aquí puedes chunkear cada grupo (array_chunk) por tamaño.
        // ------------------------------------------------------------------
        /** @var array<string, array{accion: string, config: object, items: array<int, PmsBeds24LinkQueue>}> $groups */
        $groups = [];

        foreach ($queues as $q) {
            $accion = $q->getEndpoint()?->getAccion();

            if ($accion !== self::ACCION_POST && $accion !== self::ACCION_DELETE) {
                // Endpoint inválido: lo marcamos failed con backoff largo
                $q->markFailure(
                    'Endpoint no soportado: ' . (string) $accion,
                    null,
                    $now->add(new DateInterval('PT10M'))
                );
                continue;
            }

            // Prioridad: usar beds24Config persistida en la cola (más barata que navegar link->map)
            // Fallback: resolver desde link->map (por compat con colas antiguas).
            $config = $q->getBeds24Config()
                ?? $q->getLink()?->getUnidadBeds24Map()?->getBeds24Config();

            if (!$config) {
                // No podemos saber con qué cuenta mandar
                $q->markFailure(
                    'Queue sin Beds24Config (cola/config/link/map).',
                    null,
                    $now->add(new DateInterval('PT10M'))
                );
                continue;
            }

            // La clave del grupo define batching por cuenta + tipo request
            $groupKey = ((string) $config->getId()) . '|' . $accion;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'accion' => $accion,
                    'config' => $config,
                    'items'  => [],
                ];
            }

            $groups[$groupKey]['items'][] = $q;
        }

        $processed = 0;

        // ------------------------------------------------------------------
        // 3) Ejecutar 1 request por grupo
        // ------------------------------------------------------------------
        foreach ($groups as $group) {
            $accion = $group['accion'];
            $config = $group['config'];
            $items  = $group['items'];

            // Construir rows batch (1 row por queue)
            $rows = [];
            $indexToQueue = [];

            foreach ($items as $q) {
                try {
                    // El builder retorna [row] (array indexado) por compat con Beds24 “array de bookings”
                    if ($accion === self::ACCION_POST) {
                        $one = $this->payloadBuilder->buildPostPayload($q);
                    } else {
                        $one = $this->payloadBuilder->buildDeletePayload($q);
                    }

                    $row = $one[0] ?? null;
                    if (!is_array($row)) {
                        throw new \RuntimeException('Payload inválido: row vacío.');
                    }

                    $rows[] = $row;
                    $indexToQueue[count($rows) - 1] = $q;

                    // Auditoría por item: útil para debug cuando Beds24 dice "invalid"
                    // (no guardamos el batch completo; guardamos el row de este queue)
                    $q->setLastRequestJson(json_encode($row));
                } catch (\Throwable $e) {
                    // Si falla el build payload, este item falla, pero no rompe el batch entero
                    $q->markFailure($e->getMessage(), null, $now->add(new DateInterval('PT5M')));
                    $processed++;
                }
            }

            if ($rows === []) {
                // nada que mandar en este grupo
                $this->em->flush();
                continue;
            }

            try {
                // Request del grupo (un solo call)
                $resp = ($accion === self::ACCION_POST)
                    ? $this->client->postBookings($config, $rows)
                    : $this->client->deleteBookings($config, $rows);

                // IMPORTANTE: En bookings, Beds24 suele responder:
                // - array indexado por item, cada uno: {success:true|false, ...}
                // Por eso alineamos por índice con el request.
                //
                // Beds24 puede responder:
                // - Lista por-item (lo normal en /bookings batch): [ {success:true|false,...}, ... ]
                // - Envelope: { success:true|false, data:[...], ... } (otros endpoints / variantes)
                //
                // Aquí normalizamos SIEMPRE a una lista indexada alineada con el request.
                if (!is_array($resp)) {
                    throw new \RuntimeException('Beds24 response inválida: no es array.');
                }

                $respItems = null;

                if (array_is_list($resp)) {
                    $respItems = $resp;
                } elseif (isset($resp['data']) && is_array($resp['data']) && array_is_list($resp['data'])) {
                    $respItems = $resp['data'];
                }

                if ($respItems === null) {
                    // Guardrail: si mañana Beds24 cambia de formato, preferimos fallar ruidoso antes que marcar success incorrectamente.
                    throw new \RuntimeException('Beds24 response inválida: no es lista por item ni envelope con data[].');
                }

                foreach ($indexToQueue as $i => $q) {
                    $itemResp = $respItems[$i] ?? null;

                    // Guardar respuesta por item (ideal para auditoría y troubleshooting)
                    $q->setLastResponseJson(json_encode($itemResp));

                    // Beds24 por-item: success puede venir como boolean true/false.
                    $ok = is_array($itemResp) && (($itemResp['success'] ?? null) === true);

                    if ($ok) {
                        // Nota: si quisieras guardar el HTTP code real, tendrías que exponerlo en el client.
                        // Aquí ponemos 200 porque el request fue exitoso y Beds24 te devolvió JSON válido.
                        $q->setLastHttpCode(200);

                        // ------------------------------------------------------------------
                        // Materialización del beds24BookingId DEVUELTO POR EL SERVER
                        //
                        // Importante:
                        // - El ID remoto nace AQUÍ (Processor), no en el dominio ni en el builder.
                        // - Puede venir en formato batch:
                        //     { success:true, new:{id:123} }
                        // - O en otros formatos futuros; por eso lo aislamos aquí.
                        // ------------------------------------------------------------------
                        $newBookId = null;

                        if (is_array($itemResp) && isset($itemResp['new']['id'])) {
                            $newBookId = (string) $itemResp['new']['id'];
                        }

                        // 1) Actualizar LINK (fuente de verdad del mapping local ↔ remoto)
                        if ($newBookId !== null) {
                            $link = $q->getLink();
                            if ($link !== null) {
                                // A partir de aquí, el link pasa de CREATE → UPDATE en sincronizaciones futuras
                                if ($link->getBeds24BookId() === null || $link->getBeds24BookId() === '') {
                                    $link->setBeds24BookId($newBookId);
                                }
                            }
                        }

                        // 2) Actualizar SNAPSHOT de la COLA (auditoría / resiliencia)
                        //
                        // Esto es crítico para:
                        // - DELETE futuros aunque el link se borre (onDelete=SET NULL)
                        // - Reintentos
                        // - Debug histórico
                        if ($newBookId !== null) {
                            if ($q->getBeds24BookIdOriginal() === null) {
                                $q->setBeds24BookIdOriginal($newBookId);
                            }

                            // Caso especial: colas creadas cuando el link aún no tenía ID (PULL / mirror)
                            if ($q->getLinkIdOriginal() === null && $q->getLink()?->getId() !== null) {
                                $q->setLinkIdOriginal($q->getLink()->getId());
                            }
                        }

                        // Marcar éxito FINAL del item
                        $q->markSuccess($now);
                    } else {
                        // Error parcial: solo este item se marca failed, el resto puede ser success
                        $msg = $this->extractBeds24ItemErrorMessage($itemResp);
                        $q->setLastHttpCode(200);
                        $q->markFailure($msg, 200, $now->add(new DateInterval('PT5M')));
                    }

                    $processed++;
                }

            } catch (\Throwable $e) {
                // Falló el request completo (auth, endpoint caído, HTTP >= 400, etc.)
                // => todos los items del grupo fallan igual.
                $next = $now->add(new DateInterval('PT5M'));

                foreach ($items as $q) {
                    $q->setLastResponseJson(null);
                    $q->markFailure($e->getMessage(), null, $next);
                    $processed++;
                }
            }

            // Flush por grupo: reduce overhead vs flush por item.
            $this->em->flush();
        }

        return $processed;
    }

    /**
     * Extrae un mensaje útil del error por item.
     * Tu test real mostró:
     *   { success:false, errors:[{action,field,message}] }
     */
    private function extractBeds24ItemErrorMessage(mixed $itemResp): string
    {
        if (!is_array($itemResp)) {
            return 'Beds24 item error: respuesta inválida.';
        }

        if (isset($itemResp['error']) && is_string($itemResp['error'])) {
            $e = trim($itemResp['error']);
            return $e !== '' ? $e : 'Beds24 item error.';
        }

        $errors = $itemResp['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $first = $errors[0] ?? null;
            if (is_array($first)) {
                $field  = isset($first['field']) ? (string) $first['field'] : '';
                $msg    = isset($first['message']) ? (string) $first['message'] : 'invalid';
                $action = isset($first['action']) ? (string) $first['action'] : 'action';
                return sprintf('Beds24 %s: %s %s', $action, $field, $msg);
            }
            return 'Beds24 item error.';
        }

        // A veces Beds24 devuelve "message" por item.
        if (isset($itemResp['message']) && is_string($itemResp['message'])) {
            $m = trim($itemResp['message']);
            if ($m !== '') {
                return $m;
            }
        }

        // fallback genérico
        return 'Beds24 item error (success=false).';
    }
}