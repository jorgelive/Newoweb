<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251004145146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotizacion_cotnota DROP FOREIGN KEY FK_E133C7BE307090AA');
        $this->addSql('ALTER TABLE cotizacion_cotnota DROP FOREIGN KEY FK_E133C7BE46794666');
        $this->addSql('ALTER TABLE servicio_componente DROP FOREIGN KEY FK_3BDE198771CAA3E7');
        $this->addSql('ALTER TABLE servicio_componente DROP FOREIGN KEY FK_3BDE1987BFE3F144');
        $this->addSql('DROP TABLE cotizacion_cotnota');
        $this->addSql('DROP TABLE servicio_componente');
        $this->addSql('ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal longtext AS (resumen) VIRTUAL NULL');
        $this->addSql('DROP INDEX unique_idx ON cot_cotizaciontranslation');
        $this->addSql('DROP INDEX unique_idx ON cot_cotnotatranslation');
        $this->addSql('DROP INDEX unique_idx ON cot_cotpoliticatranslation');
        $this->addSql('DROP INDEX unique_idx ON mae_categoriatourtranslation');
        $this->addSql('DROP INDEX unique_idx ON mae_clasemediotranslation');
        $this->addSql('DROP INDEX unique_idx ON mae_mediotranslation');
        $this->addSql('DROP INDEX unique_idx ON mae_tipopaxtranslation');
        $this->addSql('DROP INDEX unique_idx ON res_establecimientotranslation');
        $this->addSql('ALTER TABLE res_estado CHANGE creado creado DATETIME DEFAULT NULL, CHANGE modificado modificado DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_6C7BB6FA3886252 ON res_unit_caracteristica_link');
        $this->addSql('DROP INDEX UNIQ_6C7BB6FF8BD700DCBC63B7B ON res_unit_caracteristica_link');
        $this->addSql('DROP INDEX idx_unitcarac_nombre ON res_unitcaracteristica');
        $this->addSql('ALTER TABLE res_unitcaracteristica CHANGE nombre nombre VARCHAR(255) NOT NULL');
        $this->addSql('DROP INDEX unique_idx ON res_unitcaracteristicatranslation');
        $this->addSql('DROP INDEX unique_idx ON res_unitmediotranslation');
        $this->addSql('DROP INDEX unique_idx ON res_unittipocaracteristicatranslation');
        $this->addSql('DROP INDEX unique_idx ON res_unittranslation');
        $this->addSql('ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal varchar(160) AS (titulo) VIRTUAL NULL');
        $this->addSql('DROP INDEX unique_idx ON ser_componenteitemtranslation');
        $this->addSql('ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal varchar(100) AS (titulo) VIRTUAL NULL, CHANGE contenidooriginal contenidooriginal longtext AS (contenido) VIRTUAL NULL');
        $this->addSql('DROP INDEX unique_idx ON ser_itinerariodiatranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_itinerariotranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_modalidadtarifatranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_notaitinerariodiatranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_providermediotranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_serviciotranslation');
        $this->addSql('ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal varchar(100) AS (titulo) VIRTUAL NULL');
        $this->addSql('DROP INDEX unique_idx ON ser_tarifatranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_tipotarifadetalletranslation');
        $this->addSql('DROP INDEX unique_idx ON ser_tipotarifatranslation');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cotizacion_cotnota (cotizacion_id INT NOT NULL, cotnota_id INT NOT NULL, INDEX IDX_E133C7BE46794666 (cotnota_id), INDEX IDX_E133C7BE307090AA (cotizacion_id), PRIMARY KEY(cotizacion_id, cotnota_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE servicio_componente (servicio_id INT NOT NULL, componente_id INT NOT NULL, INDEX IDX_3BDE1987BFE3F144 (componente_id), INDEX IDX_3BDE198771CAA3E7 (servicio_id), PRIMARY KEY(servicio_id, componente_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE cotizacion_cotnota ADD CONSTRAINT FK_E133C7BE307090AA FOREIGN KEY (cotizacion_id) REFERENCES cot_cotizacion (id)');
        $this->addSql('ALTER TABLE cotizacion_cotnota ADD CONSTRAINT FK_E133C7BE46794666 FOREIGN KEY (cotnota_id) REFERENCES cot_cotnota (id)');
        $this->addSql('ALTER TABLE servicio_componente ADD CONSTRAINT FK_3BDE198771CAA3E7 FOREIGN KEY (servicio_id) REFERENCES ser_servicio (id)');
        $this->addSql('ALTER TABLE servicio_componente ADD CONSTRAINT FK_3BDE1987BFE3F144 FOREIGN KEY (componente_id) REFERENCES ser_componente (id)');
        $this->addSql('ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON cot_cotizaciontranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON cot_cotnotatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON cot_cotpoliticatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON mae_categoriatourtranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON mae_clasemediotranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON mae_mediotranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON mae_tipopaxtranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON res_establecimientotranslation (locale, object_id, field)');
        $this->addSql('ALTER TABLE res_estado CHANGE creado creado DATETIME NOT NULL, CHANGE modificado modificado DATETIME NOT NULL');
        $this->addSql('CREATE INDEX IDX_6C7BB6FA3886252 ON res_unit_caracteristica_link (prioridad)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6C7BB6FF8BD700DCBC63B7B ON res_unit_caracteristica_link (unit_id, unitcaracteristica_id)');
        $this->addSql('ALTER TABLE res_unitcaracteristica CHANGE nombre nombre VARCHAR(191) NOT NULL');
        $this->addSql('CREATE INDEX idx_unitcarac_nombre ON res_unitcaracteristica (nombre)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON res_unitcaracteristicatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON res_unitmediotranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON res_unittipocaracteristicatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON res_unittranslation (locale, object_id, field)');
        $this->addSql('ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal VARCHAR(160) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_componenteitemtranslation (locale, object_id, field)');
        $this->addSql('ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL, CHANGE contenidooriginal contenidooriginal LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_itinerariodiatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_itinerariotranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_modalidadtarifatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_notaitinerariodiatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_providermediotranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_serviciotranslation (locale, object_id, field)');
        $this->addSql('ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_tarifatranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_tipotarifadetalletranslation (locale, object_id, field)');
        $this->addSql('CREATE UNIQUE INDEX unique_idx ON ser_tipotarifatranslation (locale, object_id, field)');
    }
}
