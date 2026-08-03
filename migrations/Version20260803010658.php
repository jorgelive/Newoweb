<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Imputación de un cargo manual a una estancia concreta.
 *
 * En una reserva con varias casitas, los cargos de Beds24 se atribuyen por `beds24_booking_id`,
 * pero los manuales (reservas directas) no tienen booking del canal: se les añade `evento_id`
 * para poder decir a qué casita corresponde cada costo. NULL = cargo de la reserva en conjunto.
 *
 * ON DELETE SET NULL: si se elimina la estancia, el cargo sobrevive como cargo general en vez
 * de desaparecer con ella (es dinero registrado, no un dato derivado).
 */
final class Version20260803010658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_cargo_financiero.evento_id para imputar cargos manuales a una estancia concreta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD evento_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD CONSTRAINT FK_F145999387A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F145999387A5F842 ON pms_cargo_financiero (evento_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP FOREIGN KEY FK_F145999387A5F842');
        $this->addSql('DROP INDEX IDX_F145999387A5F842 ON pms_cargo_financiero');
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP evento_id');
    }
}
