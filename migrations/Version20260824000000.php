<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subgrupos del expediente, su pertenencia, y el dueño de cada archivo.
 *
 * ⚠️ **No es un árbol.** En el padrón real de Punta Cana hay 5 salones y 10 grupos con 43
 * combinaciones, y 9 de los 10 grupos aparecen en más de un salón. Por eso una tabla de grupos con
 * su eje, y la pertenencia en N:M.
 *
 * ⚠️ La pertenencia es **tabla propia y no un `ManyToMany`**, porque lleva `es_jefe`: un atributo
 * de la pertenencia y no de la persona —se lidera *un* grupo, no en general—.
 *
 * ⚠️ Y `cotizacion_pasajero_grupo` **no lleva `file_id` a propósito**: sería una tercera copia del
 * mismo hecho. Que pasajero y grupo sean del mismo expediente lo impone un callback de ciclo de
 * vida, no el esquema.
 */
final class Version20260824000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subgrupos del expediente (salón, grupo, habitación, reserva aérea) y su pertenencia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_file_grupo (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                file_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                tipo VARCHAR(20) NOT NULL,
                clave VARCHAR(60) NOT NULL,
                nombre VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_file_grupo_tipo_clave (file_id, tipo, clave),
                INDEX IDX_F21E5BD693CB796C (file_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_pasajero_grupo (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                pasajero_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                grupo_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                es_jefe TINYINT(1) DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_pasajero_grupo (pasajero_id, grupo_id),
                INDEX IDX_B706894704716FE (pasajero_id),
                INDEX IDX_B7068949C833003 (grupo_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE cotizacion_file_grupo ADD CONSTRAINT fk_file_grupo_file FOREIGN KEY (file_id) REFERENCES cotizacion_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo ADD CONSTRAINT fk_pas_grupo_pasajero FOREIGN KEY (pasajero_id) REFERENCES cotizacion_file_pasajero (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo ADD CONSTRAINT fk_pas_grupo_grupo FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE CASCADE');

        // El dueño del archivo: nulo en las dos = del expediente, que es lo que hay hoy.
        $this->addSql("ALTER TABLE cotizacion_file_archivo ADD pasajero_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', ADD grupo_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE cotizacion_file_archivo ADD CONSTRAINT fk_archivo_pasajero FOREIGN KEY (pasajero_id) REFERENCES cotizacion_file_pasajero (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_file_archivo ADD CONSTRAINT fk_archivo_grupo FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_D33AFC67704716FE ON cotizacion_file_archivo (pasajero_id)');
        $this->addSql('CREATE INDEX IDX_D33AFC679C833003 ON cotizacion_file_archivo (grupo_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP FOREIGN KEY fk_archivo_pasajero');
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP FOREIGN KEY fk_archivo_grupo');
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP pasajero_id, DROP grupo_id');
        $this->addSql('DROP TABLE cotizacion_pasajero_grupo');
        $this->addSql('DROP TABLE cotizacion_file_grupo');
    }
}
