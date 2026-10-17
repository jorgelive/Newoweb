<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un día, una cotización: índice único (moneda_id, fecha) en `maestro_tipocambio`.
 *
 * `TipocambioManager::persistMonthData()` deja de guardar por el ORM —su `flush` corría dentro de
 * otro `flush` y cerraba el EntityManager al crear reservas— y pasa a SQL con
 * `ON DUPLICATE KEY UPDATE`, que necesita esta clave para que dos procesos a la vez no dupliquen
 * el día.
 *
 * Antes se borra el duplicado que ya existe en producción (USD 2026-09-15, dos filas con un
 * segundo de diferencia y la misma tasa): se queda la más antigua. Nadie referencia esta tabla
 * por FK, así que borrar la copia no deja nada colgando.
 */
final class Version20261017180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'maestro_tipocambio: índice único (moneda_id, fecha), quitando antes los días duplicados';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE t FROM maestro_tipocambio t
            JOIN maestro_tipocambio o
              ON o.moneda_id = t.moneda_id
             AND o.fecha = t.fecha
             AND (o.created_at < t.created_at OR (o.created_at = t.created_at AND o.id < t.id))
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tipocambio_moneda_fecha ON maestro_tipocambio (moneda_id, fecha)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_tipocambio_moneda_fecha ON maestro_tipocambio');
    }
}
