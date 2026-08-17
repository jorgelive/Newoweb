<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `travel_itinerario.slug`: separa el CÓDIGO del nombre de la plantilla.
 *
 * `nombreInterno` se usaba como slug (`HD-COMBINADA-POOL-AM`). Se saca el código a un campo
 * propio, `slug`, para que `nombreInterno` pueda pasar a ser un nombre operativo de verdad —el
 * que puede ir al proveedor—.
 *
 * ── Copia, no mueve ─────────────────────────────────────────────────────────
 * `slug` nace con el valor actual de `nombreInterno` (el código). `nombreInterno` se queda con
 * el mismo valor: se editará plantilla por plantilla a un nombre real, y mientras tanto no
 * rompe nada. En EasyAdmin el slug ocupa el primer lugar y el nombre va a continuación.
 */
final class Version20260817240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'travel_itinerario: añade slug (código), copiado de nombreInterno.';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'travel_itinerario' AND COLUMN_NAME = 'slug'
        SQL);

        if (!$existe) {
            $this->addSql('ALTER TABLE travel_itinerario ADD slug VARCHAR(150) DEFAULT NULL');
        }

        // El código actual (nombre_interno) se copia al slug. El nombre se queda como está.
        $this->addSql('UPDATE travel_itinerario SET slug = nombre_interno WHERE slug IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_itinerario DROP slug');
    }
}
