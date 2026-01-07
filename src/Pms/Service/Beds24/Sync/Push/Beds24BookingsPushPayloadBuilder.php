<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Push;

use App\Pms\Entity\PmsBeds24LinkQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsEventoCalendario;
use DateTimeInterface;

/**
 * Beds24BookingsPushPayloadBuilder
 *
 * Responsabilidad:
 * - Construir el payload FINAL que se envía a Beds24.
 * - Aplicar reglas de negocio de sincronización (root vs mirror).
 * - Derivar CREATE vs UPDATE según exista o no beds24BookId.
 *
 * Importante:
 * - Este builder NO decide si se encola o no (eso es del listener / creator).
 * - NO hace dedupe (eso es responsabilidad del queue).
 * - Asume que el estado actual de las entidades es la fuente de verdad.
 */
final class Beds24BookingsPushPayloadBuilder
{
    public function buildPostPayload(PmsBeds24LinkQueue $queue): array
    {
        $link = $queue->getLink();
        if ($link === null) {
            throw new \RuntimeException('Queue sin link (link fue eliminado).');
        }

        // Regla central de dominio:
        // - root (isMirror=false): reserva real
        // - mirror (isMirror=true): bloqueo espejo derivado
        // Este flag gobierna TODO el payload.
        $isMirror = $link->isMirror();

        $evento = $link->getEvento();
        if ($evento === null) {
            throw new \RuntimeException('Link sin evento.');
        }

        $map = $link->getUnidadBeds24Map();
        if ($map === null) {
            throw new \RuntimeException('Link sin unidadBeds24Map.');
        }

        $reserva = $evento->getReserva(); // puede ser null si es bloqueo directo sin reserva

        // Beds24 espera un ARRAY de bookings.
        // 1 queue = 1 link = 1 booking payload.

        // Campos SIEMPRE sincronizados (root + mirror):
        // - roomId
        // - status (derivado del estado del evento)
        // - arrival / departure
        // - pax por habitación
        $payload = [
            'roomId'    => (int) $map->getBeds24RoomId(),
            'status'    => $this->resolveBeds24Status($queue),
            'arrival'   => $this->formatDate($evento->getInicio()),
            'departure' => $this->formatDate($evento->getFin()),

            // Pax por habitación (evento)
            'numAdult'  => (int) ($evento->getCantidadAdultos() ?? 0),
            'numChild'  => (int) ($evento->getCantidadNinos() ?? 0),
        ];

        // Si existe beds24BookId, esto se convierte en UPDATE/reactivate.
        $bookId = $this->toIntOrNull($link->getBeds24BookId());
        if ($bookId !== null) {
            // UPDATE vs CREATE:
            // - Si existe 'id', Beds24 interpreta UPDATE / reactivate.
            // - Si NO existe, Beds24 interpreta CREATE.
            $payload['id'] = $bookId;
        }

        // Multi-room (masterId):
        // - SOLO para links root.
        // - Los mirrors NUNCA envían masterId porque no representan reservas reales.
        if ($isMirror === false) {
            if ($reserva !== null && method_exists($reserva, 'getBeds24MasterId')) {
                $masterId = $this->toIntOrNull($reserva->getBeds24MasterId());

                // id del booking actual (si existe). En CREATE es null.
                $currentId = $bookId;

                // En CREATE intentamos derivar el id "principal" local para comparación (si tu entidad lo tiene).
                if ($currentId === null && method_exists($reserva, 'getBeds24BookIdPrincipal')) {
                    $currentId = $this->toIntOrNull($reserva->getBeds24BookIdPrincipal());
                }

                // Enviar masterId solo si realmente es distinto al id actual.
                if ($masterId !== null && ($currentId === null || $currentId !== $masterId)) {
                    $payload['masterId'] = $masterId;
                }
            }
        }

        $firstName = null;
        $lastName  = null;
        $email     = null;
        $phone     = null;
        $mobile    = null;

        // Datos personales:
        // - Solo existen si el evento pertenece a una reserva.
        // - En mirrors se permiten solo para trazabilidad, nunca para pricing ni channel.
        if ($reserva instanceof PmsReserva) {
            $firstName = $this->normalizeString($reserva->getNombreCliente());
            $lastName  = $this->normalizeString($reserva->getApellidoCliente());
            $email     = $this->normalizeString($reserva->getEmailCliente());
            $phone     = $this->normalizeString($reserva->getTelefono());
            $mobile    = $this->normalizeString($reserva->getTelefono2());

            // arrivalTime (Beds24) ↔ horaLlegadaCanal (PMS)
            $arrivalTime = $this->normalizeString($reserva->getHoraLlegadaCanal());
            if ($arrivalTime !== null) {
                $payload['arrivalTime'] = $arrivalTime;
            }

            // notes/comments
            $notes = $this->normalizeString($reserva->getNota());
            if ($notes !== null) {
                $payload['notes'] = $notes;
            }

            $comments = $this->normalizeString($reserva->getComentariosHuesped());
            if ($comments !== null) {
                $payload['comments'] = $comments;
            }

            // apiReference (Beds24) ↔ referenciaCanal (PMS)
            $apiReference = $this->normalizeString($reserva->getReferenciaCanal());
            if ($apiReference !== null) {
                $payload['apiReference'] = $apiReference;
            }

            // country2 / lang / channel
            // Regla: el channel solo debe enviarse en CREACIÓN REAL de reserva (no mirrors).
            $lang = $this->normalizeString($reserva->getIdioma()?->getCodigo());
            if ($lang !== null) {
                $payload['lang'] = $lang;
            }

            $country2 = $this->normalizeString($reserva->getPais()?->getIso2());
            if ($country2 !== null) {
                $payload['country2'] = $country2;
            }

            if ($isMirror === false) {
                $channel = $this->normalizeString($reserva->getChannel()?->getBeds24ChannelId());
                if ($channel !== null) {
                    $payload['channel'] = $channel;
                }
            }
        } else {
            // Caso sin reserva: nombre sintético con tu estado interno.
            // Preferimos PmsEventoEstado.nombre; fallback a codigo.
            $estadoNombre = null;
            if (method_exists($evento, 'getEstado') && $evento->getEstado() !== null) {
                $estadoObj = $evento->getEstado();
                if (is_object($estadoObj) && method_exists($estadoObj, 'getNombre')) {
                    $estadoNombre = $estadoObj->getNombre();
                }
                if (($estadoNombre === null || trim((string) $estadoNombre) === '') && is_object($estadoObj) && method_exists($estadoObj, 'getCodigo')) {
                    $estadoNombre = $estadoObj->getCodigo();
                }
            }

            $estadoNombre = is_string($estadoNombre) ? trim($estadoNombre) : '';
            if ($estadoNombre === '') {
                $estadoNombre = 'sin-estado';
            }

            $firstName = 'Evento (' . $estadoNombre . ')';
        }

        // Prefijo obligatorio para mirrors:
        // Garantiza trazabilidad visual en Beds24 y evita confundir bloqueos con reservas reales.
        if ($isMirror === true && $firstName !== null) {
            $firstName = '(M) ' . $firstName;
        }

        if ($firstName !== null) {
            $payload['firstName'] = $firstName;
        }
        if ($lastName !== null) {
            $payload['lastName'] = $lastName;
        }
        if ($email !== null) {
            $payload['email'] = $email;
        }

        // Beds24 distingue phone y mobile.
        // NO replicamos phone → mobile automáticamente:
        // copiar valores genera ruido y confusión en auditoría.
        if ($phone !== null) {
            $payload['phone'] = $phone;
        }
        if ($mobile !== null) {
            $payload['mobile'] = $mobile;
        }

        // Pricing:
        // - price, commission y rateDescription SOLO se envían en root.
        // - Un mirror jamás debe alterar montos en Beds24.
        if ($isMirror === false) {
            $rateDescription = $this->normalizeString($evento->getRateDescription());
            if ($rateDescription !== null) {
                $payload['rateDescription'] = $rateDescription;
            }

            $price = $this->normalizeString($evento->getMonto());
            if ($price !== null) {
                $payload['price'] = $price;
            }

            $commission = $this->normalizeString($evento->getComision());
            if ($commission !== null) {
                $payload['commission'] = $commission;
            }
        }

        // Comentario “humano” adicional para auditoría (no reemplaza notes/comments)
        if ($isMirror === true) {
            $payload['comment'] = 'Reserva espejo';
        } else {
            $payload['comment'] = $this->buildAuditComment($queue);
        }

        return [$payload];
    }

    public function buildDeletePayload(PmsBeds24LinkQueue $queue): array
    {
        // DELETE en Beds24:
        // - Siempre requiere un bookId remoto.
        // - El status se deriva del estado del evento (no se hardcodea).
        // en delete normalmente necesitas el bookId.
        $bookId = $queue->getBeds24BookIdOriginal()
            ?? $queue->getLink()?->getBeds24BookId();

        if ($bookId === null || trim((string)$bookId) === '') {
            throw new \RuntimeException('DELETE sin beds24BookId disponible.');
        }

        // DELETE also derives status from PmsEventoEstado.codigoBeds24 to avoid hardcoding
        $status = $this->resolveBeds24Status($queue);

        return [[
            'id'     => (int) $bookId,
            'status' => $status,
            'comment'=> 'Cancelled from PMS',
        ]];
    }

    private function resolveBeds24Status(PmsBeds24LinkQueue $queue): string
    {
        // Resolución de status Beds24:
        // - DELETE => cancelled
        // - POST/UPDATE => se deriva del estado del evento (codigoBeds24)
        $accion = $queue->getEndpoint()?->getAccion();

        // DELETE siempre cancela en Beds24
        if ($accion === 'DELETE_BOOKINGS') {
            return 'cancelled';
        }

        $link = $queue->getLink();
        $evento = $link?->getEvento();

        $codigoBeds24 = null;

        if ($evento !== null && method_exists($evento, 'getEstado') && $evento->getEstado() !== null) {
            $estado = $evento->getEstado();

            if (is_object($estado) && method_exists($estado, 'getCodigoBeds24')) {
                $codigoBeds24 = $estado->getCodigoBeds24();
            }
        }

        $codigoBeds24 = is_string($codigoBeds24) ? trim($codigoBeds24) : '';

        // Si el estado del evento define código Beds24, lo usamos tal cual
        if ($codigoBeds24 !== '') {
            return $codigoBeds24;
        }

        // Fallback seguro:
        // confirmed evita dejar bookings en estado indeterminado en Beds24.
        return 'confirmed';
    }

    private function buildAuditComment(PmsBeds24LinkQueue $queue): string
    {
        $link = $queue->getLink();
        $evento = $link?->getEvento();
        $reserva = $evento?->getReserva();

        $parts = [];

        if ($reserva !== null && method_exists($reserva, 'getId') && $reserva->getId() !== null) {
            $parts[] = 'PMS reserva #' . $reserva->getId();
        }

        if ($evento !== null && method_exists($evento, 'getId') && $evento->getId() !== null) {
            $parts[] = 'evento #' . $evento->getId();
        }

        if ($link !== null && method_exists($link, 'getId') && $link->getId() !== null) {
            $parts[] = 'link #' . $link->getId();
        }

        if (method_exists($queue, 'getDedupeKey') && $queue->getDedupeKey()) {
            $parts[] = 'dedupe=' . $queue->getDedupeKey();
        }

        return $parts !== [] ? implode(' | ', $parts) : 'PMS';
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }

        if (is_int($v)) {
            return $v;
        }

        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (int) $s;
    }

    /**
     * Normaliza strings para payload Beds24.
     *
     * Reglas:
     * - trim()
     * - convierte strings vacíos en null
     *
     * Motivo:
     * - Evita enviar campos vacíos que Beds24 interpreta como cambios reales.
     * - Reduce ruido en updates y falsos diffs.
     */
    private function normalizeString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function formatDate(?DateTimeInterface $dt): ?string
    {
        return $dt ? $dt->format('Y-m-d') : null;
    }
}