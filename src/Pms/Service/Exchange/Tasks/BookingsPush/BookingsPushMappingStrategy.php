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
 * * Incluye lógica para reservas espejo, master IDs y protección de datos OTA (Fuente de la verdad).
 */
final readonly class BookingsPushMappingStrategy implements MappingStrategyInterface
{
    /**
     * Status de Beds24 para una reserva cancelada.
     *
     * Es el ÚNICO estado que el PMS puede imponerle a una reserva de OTA (ver el bloque
     * "4. ESTADO"), así que se nombra en vez de repetir la cadena: el día que Beds24 cambie
     * el literal, esto tiene que fallar en un solo sitio. Espejo de
     * `pms_evento_estado.codigo_beds24` para el estado `cancelada`.
     */
    private const string BEDS24_CANCELLED = 'cancelled';

    /**
     * Transforma el lote de la cola al formato de transporte HTTP.
     * * Interviene en el empaquetado de datos diferenciando entre eliminaciones físicas
     * (DELETE) y creaciones/actualizaciones (POST/PUT emulado), asegurando que los
     * payloads cumplan con los requerimientos específicos de la API v2 de Beds24.
     *
     * @param HomogeneousBatch $batch Lote de tareas de sincronización.
     * @return MappingResult Resultado del mapeo con la configuración de la petición.
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
     * * Asocia cada resultado devuelto por Beds24 con su respectivo ID en la cola
     * de base de datos, determinando el éxito o fracaso y extrayendo los nuevos IDs remotos.
     *
     * @param array $apiResponse Respuesta cruda decodificada desde la API.
     * @param MappingResult $mapping Objeto de mapeo original que contiene la correlación.
     * @return array<string, ItemResult> Resultados indexados por el ID de la cola.
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
                extraData: (array)$respData // Guardamos para la auditoría RAW del Handler
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

        // Validación estricta de integridad (Cláusula de Guarda)
        if (!$link || !$link->getEvento() || !$link->getUnidadBeds24Map()) {
            throw new RuntimeException('Estructura de Link incompleta/corrupta.');
        }

        $evento = $link->getEvento();
        $map = $link->getUnidadBeds24Map();
        $isMirror = $link->isMirror();
        $isOta = $evento->isOta();
        $reserva = $evento->getReserva();

        // 1. ANCLA DE UBICACIÓN (Siempre requerido)
        $payload = [
            'roomId' => (int) $map->getBeds24RoomId(),
        ];

        // 2. ANCLA DE IDENTIDAD (Para modificaciones, evita duplicados)
        $bookId = $this->toIntOrNull($link->getBeds24BookId())
            ?? $this->toIntOrNull($queue->getBeds24BookIdOriginal());

        if ($bookId !== null) {
            $payload['id'] = $bookId;
        }

        // 3. FECHAS Y OCUPACIÓN
        // Solo las enviamos si es una reserva directa (donde somos dueños) o si es
        // un mirror (necesario para bloquear el calendario fantasma). Protege OTAs.
        if (!$isOta || $isMirror) {
            $payload['arrival']   = $evento->getInicio()?->format('Y-m-d');
            $payload['departure'] = $evento->getFin()?->format('Y-m-d');
            $payload['numAdult']  = (int) ($evento->getCantidadAdultos() ?? 0);
            $payload['numChild']  = (int) ($evento->getCantidadNinos() ?? 0);
        }

        // 4. ESTADO
        //
        // Regla general: en una OTA no espejo NO se manda `status`. El peligro concreto es
        // pisar una cancelación hecha en el canal con un `confirmed` viejo del PMS: la
        // reserva reviviría en Airbnb y volveríamos a vender una noche ya liberada.
        //
        // EXCEPCIÓN, y es asimétrica a propósito: la CANCELACIÓN sí viaja. Ese fallo no
        // puede darse en ese sentido —cancelar algo ya cancelado es un no-op— y sin ella el
        // PMS y el canal divergen en silencio: una consulta de Airbnb en «abierto» que se
        // cancela aquí vuelve a aparecer abierta en el siguiente pull programado, porque
        // Beds24 nunca se enteró. El operador la cancela una y otra vez sin efecto.
        //
        // Dicho de otro modo: el PMS puede CERRAR en el canal, nunca abrir ni confirmar.
        $estadoBeds24 = $this->resolveBeds24Status($queue);

        if (!$isOta || $isMirror || $estadoBeds24 === self::BEDS24_CANCELLED) {
            $payload['status'] = $estadoBeds24;
        }

        // 5. DATOS DEL HUÉSPED Y FINANZAS
        if ($reserva instanceof PmsReserva) {
            $this->mapGuestData(
                payload: $payload,
                reserva: $reserva,
                evento: $evento,
                isMirror: $isMirror,
                isOta: $isOta
            );

            // Si NO es espejo Y NO es OTA, somos dueños absolutos: enviamos finanzas y grupos.
            if (!$isMirror && !$isOta) {
                $this->applyMasterIdLogic($payload, $reserva, $bookId);
                $this->mapFinancials($payload, $evento);
            }
        } else {
            $this->mapSyntheticData($payload, $evento);
        }

        // 6. MARCADORES Y AUDITORÍA
        if ($isMirror && isset($payload['firstName'])) {
            $payload['firstName'] = '(M) ' . $payload['firstName'];
        }

        $payload['comment'] = $isMirror ? 'Reserva espejo' : $this->buildAuditComment($queue);

        // Identificadores PMS para deduplicación determinista en migraciones
        $payload['custom1'] = 'PMS:' . (string) $link->getId();
        $payload['custom2'] = $isMirror ? 'MIRROR' : 'PRINCIPAL';

        return $payload;
    }

    private function mapGuestData(array &$payload, PmsReserva $reserva, PmsEventoCalendario $evento, bool $isMirror, bool $isOta): void
    {
        // Datos seguros (Nombres e info interna)
        $this->setIf($payload, 'firstName', $reserva->getNombreCliente());
        $this->setIf($payload, 'lastName',  $reserva->getApellidoCliente());
        $this->setIf($payload, 'notes',     $reserva->getNota());
        $this->setIf($payload, 'comments',  $evento->getComentariosHuesped());
        $this->setIf($payload, 'lang',      $reserva->getIdioma()?->getId());
        $this->setIf($payload, 'country2',  $reserva->getPais()?->getId());

        // Contacto Sensible: Proteger correos proxy y teléfonos originales de la OTA
        if (!$isOta) {
            $this->setIf($payload, 'email',  $reserva->getEmailCliente());
            $this->setIf($payload, 'phone',  $reserva->getTelefono());
            $this->setIf($payload, 'mobile', $reserva->getTelefono2());
        }

        // Referencias de Canal: Solo se envían en reservas directas reales.
        // Un mirror no debe cruzar IDs de canal, y una reserva OTA no necesita que se le reenvíe su propio ID.
        if (!$isMirror && !$isOta) {
            $this->setIf($payload, 'apiReference', $evento->getReferenciaCanal());
            $this->setIf($payload, 'channel', $evento->getChannel()?->getBeds24ChannelId());
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
            return self::BEDS24_CANCELLED;
        }

        // Las validaciones de integridad superiores garantizan que estos objetos existen.
        // Se remueve el nullsafe operator (?->) para cumplir con el rigor de la estructura esperada.
        $codigo = $queue->getLink()->getEvento()->getEstado()?->getCodigoBeds24();

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