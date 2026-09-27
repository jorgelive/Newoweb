<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El anuncio de cada casita en Airbnb, para dárselo a quien consulta por allí.
 *
 * Nulable y sin relleno: lo pega el operador desde el panel. Ver `PmsUnidad::$urlAnuncioAirbnb`.
 */
final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_unidad.url_anuncio_airbnb.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad ADD url_anuncio_airbnb VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad DROP url_anuncio_airbnb');
    }
}
