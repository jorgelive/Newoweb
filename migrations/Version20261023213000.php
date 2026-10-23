<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El último nombre interno repetido: los dos trenes desde Ollantaytambo (23/10/2026).
 *
 * `TREN_IDA-MAPI-OLL` va a Machu Picchu Pueblo y `TREN_IDA-MAPI-OLL-104` deja en el km 104 para
 * el Camino Inca corto; los dos se llamaban «Viaje en tren desde Ollantaytambo». Ver
 * `Version20261023210000`, que hizo lo mismo con los demás.
 */
final class Version20261023213000 extends AbstractMigration
{
    private const string ANTES = 'Viaje en tren desde Ollantaytambo';

    public function getDescription(): string
    {
        return 'Los dos trenes desde Ollantaytambo dicen a dónde van';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE travel_segmento SET nombre_interno = ? WHERE slug = ? AND nombre_interno = ?', ['Viaje en tren desde Ollantaytambo a Machu Picchu Pueblo', 'TREN_IDA-MAPI-OLL', self::ANTES]);
        $this->addSql('UPDATE travel_segmento SET nombre_interno = ? WHERE slug = ? AND nombre_interno = ?', ['Viaje en tren desde Ollantaytambo al Km 104 (Camino Inca)', 'TREN_IDA-MAPI-OLL-104', self::ANTES]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE travel_segmento SET nombre_interno = ? WHERE slug IN (?, ?)', [self::ANTES, 'TREN_IDA-MAPI-OLL', 'TREN_IDA-MAPI-OLL-104']);
    }
}
