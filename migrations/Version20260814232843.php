<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Esquema del módulo Energía (consumo eléctrico por enchufe Tuya): dispositivo, suscripción
 * por estancia y lecturas horarias. Ver docs/Energia.md.
 *
 * Generada con migrations:diff y luego PODADA: el diff también quería DROPear
 * `pms_evento_calendario_backup_20260808` —un respaldo manual que no está (ni estará) en el
 * mapping de Doctrine—. Un respaldo se borra a mano cuando ya no hace falta, nunca como daño
 * colateral de desplegar otro módulo. Si se regenera esta migración, volverá a colarse: podarlo
 * otra vez.
 */
final class Version20260814232843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Módulo Energía: energia_dispositivo, energia_suscripcion y energia_lectura con sus FKs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE energia_dispositivo (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', unidad_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', tuya_device_id VARCHAR(64) NOT NULL, nombre VARCHAR(120) NOT NULL, ubicacion VARCHAR(120) DEFAULT NULL, mide_consumo TINYINT(1) DEFAULT 1 NOT NULL, conmutable TINYINT(1) DEFAULT 1 NOT NULL, encendido TINYINT(1) DEFAULT NULL, estado_tomado_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', activo TINYINT(1) DEFAULT 1 NOT NULL, lectura_total NUMERIC(12, 3) DEFAULT NULL, lectura_tomada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', potencia_vatios INT DEFAULT NULL, fallos_consecutivos INT DEFAULT 0 NOT NULL, ultima_alerta_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', notas LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_energia_dispositivo_unidad (unidad_id), UNIQUE INDEX uniq_energia_dispositivo_tuya (tuya_device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE energia_lectura (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', suscripcion_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', dispositivo_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', registrada_por_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', leida_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cubo_horario VARCHAR(10) DEFAULT NULL, consumo_total NUMERIC(12, 3) NOT NULL, consumo_cliente NUMERIC(12, 3) NOT NULL, incremento NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, motivo VARCHAR(20) NOT NULL, nota LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F2E4EEA3189E045D (suscripcion_id), INDEX IDX_F2E4EEA3FD37F77B (dispositivo_id), INDEX IDX_F2E4EEA3F9F79B0E (registrada_por_id), INDEX idx_energia_lectura_suscripcion (suscripcion_id, leida_en), INDEX idx_energia_lectura_dispositivo (dispositivo_id, leida_en), UNIQUE INDEX uniq_energia_lectura_hora (dispositivo_id, cubo_horario), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE energia_suscripcion (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', dispositivo_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', evento_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', inicia_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', termina_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', lectura_inicial NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, consumo_total NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, consumo_cliente NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, tarifa_soles_kwh NUMERIC(8, 4) NOT NULL, credito_diario_soles NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, activa TINYINT(1) DEFAULT 1 NOT NULL, cerrada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A3951BB2FD37F77B (dispositivo_id), INDEX idx_energia_suscripcion_activa (activa), INDEX idx_energia_suscripcion_evento (evento_id), UNIQUE INDEX uniq_energia_suscripcion_dispositivo_evento (dispositivo_id, evento_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE energia_dispositivo ADD CONSTRAINT FK_38342FC9D01464C FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE energia_lectura ADD CONSTRAINT FK_F2E4EEA3189E045D FOREIGN KEY (suscripcion_id) REFERENCES energia_suscripcion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE energia_lectura ADD CONSTRAINT FK_F2E4EEA3FD37F77B FOREIGN KEY (dispositivo_id) REFERENCES energia_dispositivo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE energia_lectura ADD CONSTRAINT FK_F2E4EEA3F9F79B0E FOREIGN KEY (registrada_por_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE energia_suscripcion ADD CONSTRAINT FK_A3951BB2FD37F77B FOREIGN KEY (dispositivo_id) REFERENCES energia_dispositivo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE energia_suscripcion ADD CONSTRAINT FK_A3951BB287A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE energia_dispositivo DROP FOREIGN KEY FK_38342FC9D01464C');
        $this->addSql('ALTER TABLE energia_lectura DROP FOREIGN KEY FK_F2E4EEA3189E045D');
        $this->addSql('ALTER TABLE energia_lectura DROP FOREIGN KEY FK_F2E4EEA3FD37F77B');
        $this->addSql('ALTER TABLE energia_lectura DROP FOREIGN KEY FK_F2E4EEA3F9F79B0E');
        $this->addSql('ALTER TABLE energia_suscripcion DROP FOREIGN KEY FK_A3951BB2FD37F77B');
        $this->addSql('ALTER TABLE energia_suscripcion DROP FOREIGN KEY FK_A3951BB287A5F842');
        $this->addSql('DROP TABLE energia_dispositivo');
        $this->addSql('DROP TABLE energia_lectura');
        $this->addSql('DROP TABLE energia_suscripcion');
    }
}
