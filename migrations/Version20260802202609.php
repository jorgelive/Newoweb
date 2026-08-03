<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enriquecimiento financiero: tabla de pagos propios (pms_pago_financiero con medio de pago,
 * comisión, tipo de cambio) y columnas de moneda (relación a maestro_moneda), tipo de cambio y
 * clasificación (tipo_cargo) en cargos; la moneda de la cabecera pasa de string a relación.
 */
final class Version20260802202609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pagos financieros (pms_pago_financiero) + moneda/tipo de cambio/tipo_cargo en cargos y moneda relacional en la cabecera financiera.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pms_pago_financiero (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', informacion_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', moneda_id VARCHAR(3) DEFAULT NULL, monto NUMERIC(10, 2) NOT NULL, tipo_cambio NUMERIC(10, 3) DEFAULT NULL, medio_pago VARCHAR(30) NOT NULL, comision NUMERIC(10, 2) DEFAULT NULL, fecha_pago DATE NOT NULL, referencia VARCHAR(100) DEFAULT NULL, notas LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F276E47D8C899341 (informacion_id), INDEX IDX_F276E47DB77634D2 (moneda_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_pago_financiero ADD CONSTRAINT FK_F276E47D8C899341 FOREIGN KEY (informacion_id) REFERENCES pms_informacion_financiera (id)');
        $this->addSql('ALTER TABLE pms_pago_financiero ADD CONSTRAINT FK_F276E47DB77634D2 FOREIGN KEY (moneda_id) REFERENCES maestro_moneda (id)');
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD moneda_id VARCHAR(3) DEFAULT NULL, ADD tipo_cargo VARCHAR(20) DEFAULT NULL, ADD tipo_cambio NUMERIC(10, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD CONSTRAINT FK_F1459993B77634D2 FOREIGN KEY (moneda_id) REFERENCES maestro_moneda (id)');
        $this->addSql('CREATE INDEX IDX_F1459993B77634D2 ON pms_cargo_financiero (moneda_id)');
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD moneda_id VARCHAR(3) DEFAULT NULL, DROP moneda');
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD CONSTRAINT FK_2AFB3104B77634D2 FOREIGN KEY (moneda_id) REFERENCES maestro_moneda (id)');
        $this->addSql('CREATE INDEX IDX_2AFB3104B77634D2 ON pms_informacion_financiera (moneda_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_pago_financiero DROP FOREIGN KEY FK_F276E47D8C899341');
        $this->addSql('ALTER TABLE pms_pago_financiero DROP FOREIGN KEY FK_F276E47DB77634D2');
        $this->addSql('DROP TABLE pms_pago_financiero');
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP FOREIGN KEY FK_F1459993B77634D2');
        $this->addSql('DROP INDEX IDX_F1459993B77634D2 ON pms_cargo_financiero');
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP moneda_id, DROP tipo_cargo, DROP tipo_cambio');
        $this->addSql('ALTER TABLE pms_informacion_financiera DROP FOREIGN KEY FK_2AFB3104B77634D2');
        $this->addSql('DROP INDEX IDX_2AFB3104B77634D2 ON pms_informacion_financiera');
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD moneda VARCHAR(10) DEFAULT NULL, DROP moneda_id');
    }
}
