<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los documentos de identidad del pasajero, en filas.
 *
 * `cotizacion_file_pasajero` tenía **un** `tipodocumento` y **un** `numerodocumento`, sin
 * vencimiento. Un padrón real lo desmiente: 130 de 133 personas llevan DNI **y** pasaporte con
 * fechas distintas, y 100 son menores —autorización notarial aparte—.
 *
 * ⚠️ Las columnas viejas **no se tiran aquí**. El dato se copia con
 * `app:cotizacion:pasajeros-a-identificaciones`, se comprueba, y se quitan en una migración
 * posterior. Tirarlas en el mismo paso deja el despliegue sin vuelta atrás si la copia sale mal.
 */
final class Version20260823200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea cotizacion_pasajero_identificacion (DNI, pasaporte… con su vencimiento)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_pasajero_identificacion (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                pasajero_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                pais_emisor_id VARCHAR(2) DEFAULT NULL,
                tipo VARCHAR(20) NOT NULL,
                numero VARCHAR(100) NOT NULL,
                vencimiento DATE DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_pasajero_identificacion_tipo (pasajero_id, tipo),
                INDEX IDX_F0DCEF5A704716FE (pasajero_id),
                INDEX IDX_F0DCEF5AFF43E586 (pais_emisor_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion ADD CONSTRAINT fk_pasid_pasajero FOREIGN KEY (pasajero_id) REFERENCES cotizacion_file_pasajero (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion ADD CONSTRAINT fk_pasid_pais FOREIGN KEY (pais_emisor_id) REFERENCES maestro_pais (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cotizacion_pasajero_identificacion');
    }
}
