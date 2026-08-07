<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260806203550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobrador del pago: quién recibió el dinero de manos del huésped (FK a user, nullable).';
    }

    public function up(Schema $schema): void
    {
        // Nullable sin backfill a propósito: no se puede adivinar quién cobró los pagos
        // históricos, y los depósitos automáticos de las OTA no tienen cobrador humano.
        $this->addSql('ALTER TABLE pms_pago_financiero ADD cobrador_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE pms_pago_financiero ADD CONSTRAINT FK_F276E47DE34DBF53 FOREIGN KEY (cobrador_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F276E47DE34DBF53 ON pms_pago_financiero (cobrador_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_pago_financiero DROP FOREIGN KEY FK_F276E47DE34DBF53');
        $this->addSql('DROP INDEX IDX_F276E47DE34DBF53 ON pms_pago_financiero');
        $this->addSql('ALTER TABLE pms_pago_financiero DROP cobrador_id');
    }
}
