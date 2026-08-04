<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Estado `extension` y vínculo `evento_origen_id`.
 *
 * La noche que un horario extra inutiliza (entrada temprana o salida tardía) deja
 * de ser una fecha desplazada en el push y pasa a ser un EVENTO real en estado
 * `extension`, hermano de la estancia y colgado de la misma reserva.
 *
 * Va como `black` a Beds24 —bloquea la unidad en todos los canales, también en las
 * reservas OTA, donde el push no puede tocar fechas (§9.4)— y es invisible en el
 * PMS: calendarios, listado de eventos, estancias del drawer y rollup de la
 * reserva lo filtran. Ver docs/PmsBeds24ReservasSync.md §7.1.b.
 *
 * `evento_origen_id` es lo que permite recolocarla o retirarla cuando se desmarca
 * la casilla; `ON DELETE CASCADE` la borra si se borra la estancia.
 */
final class Version20260804234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estado extension + evento_origen_id en pms_evento_calendario.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pms_evento_estado (id, nombre, color, codigo_beds24, orden, created_at, updated_at)
            VALUES ('extension', 'Extensión', '#94A3B8', 'black', 7, NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                nombre = nuevo.nombre,
                color = nuevo.color,
                codigo_beds24 = nuevo.codigo_beds24,
                updated_at = NOW()
            SQL);

        // El COMMENT del tipo y el nombre de índice son los que genera Doctrine:
        // sin ellos, `schema:validate` marca la tabla como desincronizada.
        $this->addSql("ALTER TABLE pms_evento_calendario ADD evento_origen_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BCAE4AADE3 FOREIGN KEY (evento_origen_id) REFERENCES pms_evento_calendario (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_7348A9BCAE4AADE3 ON pms_evento_calendario (evento_origen_id)');
    }

    public function down(Schema $schema): void
    {
        // Primero las extensiones: sin ellas la FK ya no estorba y no quedan
        // eventos huérfanos con un estado que va a desaparecer.
        $this->addSql("DELETE FROM pms_evento_calendario WHERE estado_id = 'extension'");
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BCAE4AADE3');
        $this->addSql('DROP INDEX IDX_7348A9BCAE4AADE3 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP evento_origen_id');
        $this->addSql("DELETE FROM pms_evento_estado WHERE id = 'extension'");
    }
}
