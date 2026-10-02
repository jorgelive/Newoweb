<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Enum\FileEstadoEnum;
use App\Dto\Lee;
use App\Message\Contract\AsuntosSinTelefonoInterface;
use App\Message\Dto\AsuntoSinTelefono;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Expedientes sin archivar a cuyo cliente no le sale un WhatsApp.
 *
 * «Vigente» es no estar archivado: un expediente cerrado puede seguir con el viaje por delante.
 * El hilo que cuenta es el TITULAR del expediente (`cotizacion_conversacion_enlace`).
 */
final readonly class CotizacionFilesSinTelefono implements AsuntosSinTelefonoInterface
{
    public function __construct(private Connection $db) {}

    public function sinTelefono(DateTimeImmutable $hoy): array
    {
        $filas = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT f.id AS file_id, f.localizador, f.nombre_grupo, f.pasajero_principal, f.telefono, f.created_at,
                   c.id AS conversacion_id, c.guest_phone, c.whatsapp_disabled,
                   c.fusion_sugerida IS NOT NULL AS fusion_sugerida
            FROM cotizacion_file f
            LEFT JOIN cotizacion_conversacion_enlace l ON l.file_id = f.id AND l.es_titular = 1
            LEFT JOIN msg_conversation c ON c.id = l.conversacion_id
            WHERE f.estado <> :archivado
              AND (c.id IS NULL OR c.guest_phone IS NULL OR c.guest_phone = '' OR c.whatsapp_disabled = 1)
            ORDER BY f.created_at DESC
            SQL,
            ['archivado' => FileEstadoEnum::ARCHIVADO->value],
        );

        $asuntos = [];

        foreach ($filas as $fila) {
            $crudo = $fila['file_id'] ?? null;
            $file = is_string($crudo) && strlen($crudo) === 16 ? Uuid::fromBinary($crudo)->toRfc4122() : null;
            $hilo = $fila['conversacion_id'] ?? null;
            $motivo = AsuntoSinTelefono::motivo(is_string($hilo), Lee::texto($fila['guest_phone'] ?? null), (bool) Lee::entero($fila['whatsapp_disabled'] ?? null), Lee::texto($fila['telefono'] ?? null));

            if ($file === null || $motivo === null) {
                continue;
            }

            $creado = Lee::texto($fila['created_at'] ?? null);

            $asuntos[] = new AsuntoSinTelefono(
                negocio: 'cotizacion_file',
                id: $file,
                nombre: Lee::texto($fila['nombre_grupo'] ?? null) ?? Lee::texto($fila['pasajero_principal'] ?? null) ?? 'Sin nombre',
                detalle: implode(' · ', array_filter(['Cotización', Lee::texto($fila['localizador'] ?? null)])),
                fecha: $creado !== null ? substr($creado, 0, 10) : null,
                motivo: $motivo,
                conversacionId: is_string($hilo) && strlen($hilo) === 16 ? Uuid::fromBinary($hilo)->toRfc4122() : null,
                fusionSugerida: (bool) Lee::entero($fila['fusion_sugerida'] ?? null),
                destino: ['file' => $file],
            );
        }

        return $asuntos;
    }
}
