<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_informacion_financiera.cobro_total_pedido`: el huésped paga el total en lugar del adelanto.
 * Ver `PmsInformacionFinanciera::$cobroTotalPedido`.
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marca «cobrar el total» en la ficha financiera';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD cobro_total_pedido TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_informacion_financiera DROP cobro_total_pedido');
    }
}
