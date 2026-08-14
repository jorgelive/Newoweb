<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill de la imputación a estancia (`evento_id`) en los cargos sincronizados de Beds24.
 *
 * ### Qué pasaba
 *
 * La imputación de un cargo a su estancia vivía en DOS claves según el origen: los cargos del
 * canal sólo llevaban `beds24_booking_id` y los manuales la FK `evento_id`. Únicamente el panel
 * sabía unificarlas (`claveEstancia()` traduciendo con `getEstancias()`); cualquier otro
 * consumidor —caja, agente, un futuro estado de pago por estancia— tenía que repetir esa
 * traducción o quedarse sin imputación. Frágil, como señaló la revisión del 2026-08-14.
 *
 * ### Qué hace
 *
 * Resuelve `beds24_booking_id → evento_id` por el link PRINCIPAL (los espejos virtuales nacen
 * sin `beds24_book_id`) y lo persiste en los cargos sincronizados que aún no lo tenían. El join
 * pasa por la reserva de la cabecera como guarda: un link no puede imputar un cargo a una
 * estancia de OTRA reserva ni aunque los datos vinieran anómalos.
 *
 * Desde ahora los cargos nuevos nacen imputados y la imputación se reevalúa en cada sync
 * (`Beds24InvoiceReceivePersister::eventosPorBookingId()`), así que esto es sólo la foto
 * inicial. `beds24_booking_id` no se toca: queda como verdad histórica cruda.
 *
 * Va por SQL directo con conciencia: `evento_id` no dispara listeners ni recalcula totales
 * (la conversión y el rollup no dependen de la imputación), así que no hace falta el ORM.
 */
final class Version20260814100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill de evento_id en los cargos sincronizados de Beds24 (imputación a estancia por el link principal).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_cargo_financiero c
            INNER JOIN pms_informacion_financiera i ON i.id = c.informacion_id
            INNER JOIN pms_evento_beds24_link l
                ON l.beds24_book_id = CAST(c.beds24_booking_id AS UNSIGNED)
                AND l.es_principal = 1
            INNER JOIN pms_evento_calendario e
                ON e.id = l.evento_id
                AND e.reserva_id = i.reserva_id
            SET c.evento_id = e.id
            WHERE c.beds24_item_id IS NOT NULL
              AND c.beds24_booking_id IS NOT NULL
              AND c.evento_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No hay forma de distinguir las filas backfilleadas de las que el persister imputó
        // después, así que se desimputan TODOS los sincronizados: es exactamente el estado
        // anterior a esta migración (los sincronizados no llevaban evento_id) y el panel
        // vuelve a resolverlos por beds24_booking_id, que sigue intacto.
        $this->addSql(<<<'SQL'
            UPDATE pms_cargo_financiero
            SET evento_id = NULL
            WHERE beds24_item_id IS NOT NULL
        SQL);
    }
}
