<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250923_RewireMedioAndVirtualsWithMap extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajusta columnas VIRTUAL y re-cablea res_unitmedio → unitcaracteristica_id usando mapeo (unit_id→característica), luego limpia columnas/índices/FKs viejos.';
    }

    public function up(Schema $schema): void
    {
        // ====== Columnas generadas (VIRTUAL) ======
        $this->addSql("ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal VARCHAR(160) AS (titulo) VIRTUAL NULL");
        $this->addSql("ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal LONGTEXT AS (resumen) VIRTUAL NULL");
        $this->addSql("ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal VARCHAR(100) AS (titulo) VIRTUAL NULL");

        // ====== res_unitmedio: quitar FKs/índices antiguos (unit / unittipocaracteristica) ======
        $this->addSql("ALTER TABLE res_unitmedio DROP FOREIGN KEY FK_F08C74869F9CCA53");
        $this->addSql("ALTER TABLE res_unitmedio DROP FOREIGN KEY FK_F08C7486F8BD700D");
        $this->addSql("DROP INDEX IDX_F08C7486F8BD700D ON res_unitmedio");
        $this->addSql("DROP INDEX IDX_F08C74869F9CCA53 ON res_unitmedio");

        // ====== res_unitmedio: agregar NUEVA columna y ajustar titulo (aún NO dropeamos las viejas para poder mapear) ======
        $this->addSql("ALTER TABLE res_unitmedio ADD unitcaracteristica_id INT DEFAULT NULL, CHANGE titulo titulo VARCHAR(255) DEFAULT NULL");

        // ====== res_unitmedio: crear FK/índice nuevos ======
        $this->addSql("CREATE INDEX IDX_F08C7486CBC63B7B ON res_unitmedio (unitcaracteristica_id)");
        $this->addSql("ALTER TABLE res_unitmedio ADD CONSTRAINT FK_F08C7486CBC63B7B FOREIGN KEY (unitcaracteristica_id) REFERENCES res_unitcaracteristica (id) ON DELETE SET NULL");

        // ====== MAPEO de datos (unit_id → unitcaracteristica_id) ======
        // unit_id 1→12, 2→13, 3→14, 4→15, 5→16, 6→23, 7→19
        $this->addSql("
            UPDATE res_unitmedio m
            SET m.unitcaracteristica_id = CASE m.unit_id
                WHEN 1 THEN 12
                WHEN 2 THEN 13
                WHEN 3 THEN 14
                WHEN 4 THEN 15
                WHEN 5 THEN 16
                WHEN 6 THEN 23
                WHEN 7 THEN 19
                ELSE m.unitcaracteristica_id
            END
            WHERE m.unit_id IN (1,2,3,4,5,6,7)
        ");

        // (Opcional) Si quieres forzar que TODO medio mapeado tenga valor:
        // $this->addSql("UPDATE res_unitmedio SET unitcaracteristica_id = unitcaracteristica_id WHERE unit_id IN (1,2,3,4,5,6,7)");

        // ====== res_unitmedio: ahora sí, eliminar columnas viejas ======
        $this->addSql("ALTER TABLE res_unitmedio DROP COLUMN unit_id, DROP COLUMN unittipocaracteristica_id");

        // ====== ser_itinerariodia: columnas generadas ======
        $this->addSql("ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal VARCHAR(100) AS (titulo) VIRTUAL NULL, CHANGE contenidooriginal contenidooriginal LONGTEXT AS (contenido) VIRTUAL NULL");
    }

    public function down(Schema $schema): void
    {
        // Reversión best-effort (no deshace el mapeo de datos, solo re-crea estructura previa)

        // ====== ser_itinerariodia: volver a columnas normales ======
        $this->addSql("ALTER TABLE ser_itinerariodia CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL, CHANGE contenidooriginal contenidooriginal LONGTEXT DEFAULT NULL");

        // ====== res_unitmedio: quitar FK/índice nuevos y columna nueva; restaurar columnas viejas + FKs/índices ======
        $this->addSql("ALTER TABLE res_unitmedio DROP FOREIGN KEY FK_F08C7486CBC63B7B");
        $this->addSql("DROP INDEX IDX_F08C7486CBC63B7B ON res_unitmedio");

        $this->addSql("ALTER TABLE res_unitmedio ADD unit_id INT DEFAULT NULL, ADD unittipocaracteristica_id INT DEFAULT NULL, DROP unitcaracteristica_id, CHANGE titulo titulo VARCHAR(255) NOT NULL");

        $this->addSql("CREATE INDEX IDX_F08C7486F8BD700D ON res_unitmedio (unit_id)");
        $this->addSql("CREATE INDEX IDX_F08C74869F9CCA53 ON res_unitmedio (unittipocaracteristica_id)");

        $this->addSql("ALTER TABLE res_unitmedio ADD CONSTRAINT FK_F08C7486F8BD700D FOREIGN KEY (unit_id) REFERENCES res_unit (id)");
        $this->addSql("ALTER TABLE res_unitmedio ADD CONSTRAINT FK_F08C74869F9CCA53 FOREIGN KEY (unittipocaracteristica_id) REFERENCES res_unittipocaracteristica (id)");

        // ====== Columnas generadas (VIRTUAL) → normales ======
        $this->addSql("ALTER TABLE ser_tarifa CHANGE titulooriginal titulooriginal VARCHAR(100) DEFAULT NULL");
        $this->addSql("ALTER TABLE cot_cotizacion CHANGE resumenoriginal resumenoriginal LONGTEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE ser_componenteitem CHANGE titulooriginal titulooriginal VARCHAR(160) DEFAULT NULL");
    }
}
