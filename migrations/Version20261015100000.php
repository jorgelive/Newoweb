<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rellena `delivery_status`, `delivered_at` y `read_at` de las colas de WhatsApp ya enviadas, con
 * lo que Meta avisó y se guardó en el MENSAJE (`metadata.whatsappMeta.*_at`). Desde hoy lo escribe
 * `WhatsappMetaSendQueue::registrarEstadoDeMeta()` al llegar cada aviso; hasta hoy no lo escribía
 * nadie y las 2 177 colas entregadas estaban en `submitted`. Ver docs/Mensajeria.md, recibos.
 *
 * - Sólo las filas que casan con su mensaje por el id de Meta (la respuesta guardada lo lleva):
 *   2 054 de 2 177 en producción. Las demás —reintentos, acuses de lectura de mensajes entrantes,
 *   respuestas sin id— no se tocan: atribuirles la entrega de otra fila sería inventar.
 * - Sólo SUBE: no pisa una fila que ya tenga un estado mayor.
 * - Las fechas del mensaje son ISO en UTC («…Z»); las columnas van en la hora del sistema
 *   (`APP_ZONA_HORARIA`, America/Lima, −05:00 fijo: Perú no cambia de hora). `CONVERT_TZ` con
 *   desplazamientos numéricos no necesita las tablas de zonas de MySQL.
 * - `webhook_history` no se inventa: para el pasado no hay avisos guardados por fila.
 *
 * Sin vuelta atrás de datos: devolver las filas a `submitted` sería borrar información cierta.
 */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colas de WhatsApp: entrega y lectura rellenadas desde los avisos de Meta guardados en el mensaje';
    }

    public function up(Schema $schema): void
    {
        $leido = "STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.whatsappMeta.read_at')), '%Y-%m-%dT%H:%i:%sZ')";
        $entregado = "STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.whatsappMeta.delivered_at')), '%Y-%m-%dT%H:%i:%sZ')";

        $this->addSql("UPDATE msg_whatsapp_meta_send_queue q
            JOIN msg_message m ON m.id = q.message_id
            SET q.read_at = COALESCE(q.read_at, CONVERT_TZ($leido, '+00:00', '-05:00')),
                q.delivered_at = COALESCE(q.delivered_at, CONVERT_TZ(COALESCE($entregado, $leido), '+00:00', '-05:00')),
                q.delivery_status = CASE
                    WHEN $leido IS NOT NULL THEN 'read'
                    WHEN $entregado IS NOT NULL AND q.delivery_status <> 'read' THEN 'delivered'
                    ELSE q.delivery_status END
            WHERE q.status = 'success'
              AND m.direction = 'outgoing'
              AND JSON_EXTRACT(m.external_ids, '$.whatsapp_meta') IS NOT NULL
              AND q.last_response_raw LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(m.external_ids, '$.whatsapp_meta')), '%')
              AND ($leido IS NOT NULL OR $entregado IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        // Ver el docblock: no se deshace a propósito.
    }
}
