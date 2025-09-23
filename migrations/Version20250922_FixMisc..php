<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250922_FixMisc extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DEFAULT NULL en textos, columnas generadas (VIRTUAL) y renombres de índices en res_unit_caracteristica_link.';
    }

    public function up(Schema $schema): void
    {
        // Textos con DEFAULT NULL
        $this->addSql("ALTER TABLE cot_cotnota CHANGE titulo titulo VARCHAR(100) DEFAULT NULL, CHANGE contenido contenido LONGTEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE ser_tipotarifa CHANGE titulo titulo VARCHAR(100) DEFAULT NULL");

        // Columnas GENERADAS (VIRTUAL) que reflejan columnas base
        $this->addSql("ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal VARCHAR(160) AS (titulo) VIRTUAL NULL");
        $this->addSql("ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal LONGTEXT AS (resumen) VIRTUAL NULL");
        $this->addSql("ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal VARCHAR(100) AS (titulo) VIRTUAL NULL");
        $this->addSql("ALTER TABLE res_unitcaracteristica CHANGE contenidooriginal contenidooriginal LONGTEXT AS (contenido) VIRTUAL NULL");
        $this->addSql("ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal VARCHAR(100) AS (titulo) VIRTUAL NULL, CHANGE contenidooriginal contenidooriginal LONGTEXT AS (contenido) VIRTUAL NULL");

        // Renombres de índices (MySQL 5.7.7+ / 8.0+)
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX idx_link_unit TO IDX_6C7BB6FF8BD700D");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX idx_link_car TO IDX_6C7BB6FCBC63B7B");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX idx_link_pri TO IDX_6C7BB6FA3886252");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX uniq_link_unit_car TO UNIQ_6C7BB6FF8BD700DCBC63B7B");
    }

    public function down(Schema $schema): void
    {
        // Reversión best-effort (ajústala si tu estado previo era distinto)

        // Volver virtuales a columnas estándar con DEFAULT NULL
        $this->addSql("ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL, CHANGE contenidooriginal contenidooriginal LONGTEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE res_unitcaracteristica CHANGE contenidooriginal contenidooriginal LONGTEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL");
        $this->addSql("ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal LONGTEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal VARCHAR(160) DEFAULT NULL");

        // Títulos a DEFAULT NULL (igual que en up; si antes eran NOT NULL, cámbialo aquí)
        $this->addSql("ALTER TABLE ser_tipotarifa CHANGE titulo titulo VARCHAR(100) DEFAULT NULL");
        $this->addSql("ALTER TABLE cot_cotnota CHANGE titulo titulo VARCHAR(100) DEFAULT NULL, CHANGE contenido contenido LONGTEXT DEFAULT NULL");

        // Renombrar índices a nombres “legibles” originales
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX UNIQ_6C7BB6FF8BD700DCBC63B7B TO uniq_link_unit_car");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX IDX_6C7BB6FF8BD700D TO idx_link_unit");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX IDX_6C7BB6FCBC63B7B TO idx_link_car");
        $this->addSql("ALTER TABLE res_unit_caracteristica_link RENAME INDEX IDX_6C7BB6FA3886252 TO idx_link_pri");
    }
}
