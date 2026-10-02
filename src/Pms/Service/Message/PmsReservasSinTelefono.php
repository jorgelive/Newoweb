<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Dto\Lee;
use App\Message\Contract\AsuntosSinTelefonoInterface;
use App\Message\Dto\AsuntoSinTelefono;
use App\Pms\Entity\PmsEventoEstado;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reservas con estancias por delante (o en curso) a cuyo huésped no le sale un WhatsApp.
 *
 * «Vigente» es tener alguna estancia que todavía no ha terminado en un estado que identifica a un
 * huésped (`IDENTIFICAN_HUESPED`): un bloqueo no tiene a quién escribirle. El hilo que cuenta es el
 * TITULAR de la reserva, el que programa sus avisos.
 *
 * Una sola consulta y en SQL: el contacto por reserva (`TelefonoDeContacto`) es una consulta por
 * fila, y esto barre todas las vigentes de golpe.
 */
final readonly class PmsReservasSinTelefono implements AsuntosSinTelefonoInterface
{
    public function __construct(private Connection $db) {}

    public function sinTelefono(DateTimeImmutable $hoy): array
    {
        $filas = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT r.id AS reserva_id, r.localizador, r.nombre_cliente, r.apellido_cliente, r.telefono,
                   ch.nombre AS canal,
                   MIN(e.inicio) AS inicio, MAX(e.fin) AS fin,
                   GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre SEPARATOR ', ') AS unidades,
                   (SELECT e2.id FROM pms_evento_calendario e2
                     WHERE e2.reserva_id = r.id AND e2.fin >= :hoy AND e2.estado_id IN (:estados)
                     ORDER BY e2.inicio LIMIT 1) AS evento_id,
                   c.id AS conversacion_id, c.guest_phone, c.whatsapp_disabled,
                   c.fusion_sugerida IS NOT NULL AS fusion_sugerida
            FROM pms_reserva r
            JOIN pms_evento_calendario e ON e.reserva_id = r.id AND e.fin >= :hoy AND e.estado_id IN (:estados)
            LEFT JOIN pms_unidad u ON u.id = e.pms_unidad_id
            LEFT JOIN pms_channel ch ON ch.id = r.channel_id
            LEFT JOIN pms_conversacion_enlace l ON l.reserva_id = r.id AND l.es_titular = 1
            LEFT JOIN msg_conversation c ON c.id = l.conversacion_id
            GROUP BY r.id, c.id
            HAVING c.id IS NULL OR c.guest_phone IS NULL OR c.guest_phone = '' OR c.whatsapp_disabled = 1
            ORDER BY inicio
            SQL,
            ['hoy' => $hoy->format('Y-m-d'), 'estados' => PmsEventoEstado::IDENTIFICAN_HUESPED],
            ['estados' => ArrayParameterType::STRING],
        );

        $asuntos = [];

        foreach ($filas as $fila) {
            $hayHilo = is_string($fila['conversacion_id'] ?? null);
            $motivo = AsuntoSinTelefono::motivo($hayHilo, Lee::texto($fila['guest_phone'] ?? null), (bool) Lee::entero($fila['whatsapp_disabled'] ?? null), Lee::texto($fila['telefono'] ?? null));
            $reserva = $this->uuid($fila['reserva_id'] ?? null);
            $evento = $this->uuid($fila['evento_id'] ?? null);

            if ($motivo === null || $reserva === null || $evento === null) {
                continue;
            }

            $nombre = trim((Lee::texto($fila['nombre_cliente'] ?? null) ?? '') . ' ' . (Lee::texto($fila['apellido_cliente'] ?? null) ?? ''));
            $inicio = Lee::texto($fila['inicio'] ?? null);

            $asuntos[] = new AsuntoSinTelefono(
                negocio: 'pms_reserva',
                id: $reserva,
                nombre: $nombre !== '' ? $nombre : 'Sin nombre',
                detalle: implode(' · ', array_filter([
                    Lee::texto($fila['canal'] ?? null),
                    Lee::texto($fila['unidades'] ?? null),
                    Lee::texto($fila['localizador'] ?? null),
                ])),
                fecha: $inicio !== null ? substr($inicio, 0, 10) : null,
                motivo: $motivo,
                conversacionId: $this->uuid($fila['conversacion_id'] ?? null),
                fusionSugerida: (bool) Lee::entero($fila['fusion_sugerida'] ?? null),
                destino: ['reserva' => $reserva, 'evento' => $evento],
            );
        }

        return $asuntos;
    }

    /** Las columnas `binary(16)` llegan como 16 bytes crudos. */
    private function uuid(mixed $crudo): ?string
    {
        return is_string($crudo) && strlen($crudo) === 16 ? Uuid::fromBinary($crudo)->toRfc4122() : null;
    }
}
