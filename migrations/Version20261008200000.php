<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_informacion_financiera.activa` deja de existir: se calcula de las estancias.
 *
 * La columna se apagaba con la última estancia cancelada y nada la volvía a encender, así que
 * toda reserva que se pasó a directa quedó «ANULADA» (B5X9HB). Desde el 08/09/2026 no decide
 * dinero —lo hace el estado de cada estancia— y ninguna consulta la filtra. Ver
 * `PmsInformacionFinanciera::isActiva()`.
 */
final class Version20261008200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira pms_informacion_financiera.activa: ahora se calcula de las estancias';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_informacion_financiera DROP activa');
    }

    public function down(Schema $schema): void
    {
        // Se repone apagada donde todas las estancias están canceladas, que es lo que calcula hoy.
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD activa TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
            SET i.activa = 0
            WHERE EXISTS (SELECT 1 FROM pms_evento_calendario e WHERE e.reserva_id = i.reserva_id)
              AND NOT EXISTS (
                  SELECT 1 FROM pms_evento_calendario e
                  WHERE e.reserva_id = i.reserva_id AND COALESCE(e.estado_id, '') <> 'cancelada'
              )
            SQL);
    }
}
