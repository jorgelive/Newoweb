<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El corte del horario extra: los eventos hermanos (`extension`) desaparecen y sus `black` pasan
 * a colgar de la propia estancia como links extra.
 *
 * Fase 4 de docs/PlanHorarioExtraSinEventos.md. Va en SQL, sin listeners, para que no salga
 * NINGÚN push ni ningún DELETE a Beds24 al hacerlo:
 *
 * 1. Los links de cada extensión VIVA se mueven a su estancia con su rol, **conservando el
 *    `beds24BookId`**: la misma `black` de Beds24 sigue siendo la que bloquea. Nunca son
 *    principales (`es_principal = 0`): la reserva de la estancia es la suya.
 *    Es la de ENTRADA si acaba el día en que empieza la estancia; si no, la de salida — el mismo
 *    criterio con el que el calendario las distinguía.
 * 2. Las colas pendientes de los links que se van a borrar se cancelan.
 * 3. Se borran todas las extensiones. Sus links se van con ellas (FK en cascada): son los de las
 *    canceladas, cuya `black` ya está cancelada en Beds24.
 *
 * Inventario del 01/10/2026: 10 extensiones, 2 vivas (M4E23R, salida tardía del 05/08; KXET9H,
 * entrada temprana del 27/09), las dos ya pasadas; ninguna con cargos, asignaciones, limpiezas,
 * peticiones ni suscripciones de domótica.
 *
 * Sin vuelta atrás: los eventos hermanos ya no existen en el código.
 */
final class Version20261001180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Horario extra: los links de las extensiones vivas pasan a la estancia como extra_*, y se borran los eventos extension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_evento_beds24_link l
            JOIN pms_evento_calendario x ON x.id = l.evento_id
            JOIN pms_evento_calendario o ON o.id = x.evento_origen_id
            SET l.rol = IF(DATE(x.fin) = DATE(o.inicio), 'extra_entrada', 'extra_salida'),
                l.es_principal = 0,
                l.evento_id = o.id
            WHERE x.estado_id = 'extension'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE pms_bookings_push_queue q
            JOIN pms_evento_beds24_link l ON l.id = q.link_id
            JOIN pms_evento_calendario x ON x.id = l.evento_id
            SET q.status = 'cancelled', q.failed_reason = 'Extensión retirada en el corte del horario extra'
            WHERE x.evento_origen_id IS NOT NULL AND q.status IN ('pending', 'failed')
            SQL);

        $this->addSql('DELETE FROM pms_evento_calendario WHERE evento_origen_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Los eventos de extensión ya no existen en el código.');
    }
}
