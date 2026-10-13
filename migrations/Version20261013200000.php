<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `maestro_tipocambio_consulta`: tope diario de peticiones al proveedor del tipo de cambio.
 * Ver `MaestroTipocambioConsulta` y docs/PmsBeds24ReservasSync.md §12.18.
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'maestro_tipocambio_consulta (tope diario de peticiones a decolecta)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE maestro_tipocambio_consulta (id INT AUTO_INCREMENT NOT NULL, dia DATE NOT NULL, intentos INT NOT NULL, ultimo_fallo VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_tc_consulta_dia (dia), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maestro_tipocambio_consulta');
    }
}
