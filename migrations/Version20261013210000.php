<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `maestro_tipocambio_consulta.ultima_peticion`: 20 minutos mínimos entre peticiones a decolecta.
 * Ver `TipocambioManager::MINUTOS_ENTRE_CONSULTAS` y docs/PmsBeds24ReservasSync.md §12.18.
 */
final class Version20261013210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'maestro_tipocambio_consulta.ultima_peticion (espera mínima entre peticiones)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maestro_tipocambio_consulta ADD ultima_peticion DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maestro_tipocambio_consulta DROP ultima_peticion');
    }
}
