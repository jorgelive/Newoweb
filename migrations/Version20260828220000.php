<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los vuelos dejan de ser una línea de texto y pasan a ser datos.
 *
 * Hasta ahora vivían dentro de `cotizacion_file_grupo.detalle`, en texto libre. Comprobar si
 * una aerolínea movió un horario era comparar cadenas, y «¿a quién afecta?» no se podía
 * preguntar. Ver `CotizacionVuelo` para el porqué completo.
 *
 * ⚠️ **Escrita a mano.** `migrations:diff` arrastró además tres tablas `energia_*` y un backup
 * de `pms_evento_calendario` que son trabajo ajeno sin desplegar: el diff mira TODO el esquema,
 * no sólo lo que acabas de tocar. Aquí van únicamente las dos tablas nuevas.
 *
 * No toca ni una fila existente: `detalle` se queda como está, y los vuelos se cargan aparte.
 */
final class Version20260828220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vuelos como entidad: cotizacion_vuelo y su puente N:M con los localizadores.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_vuelo (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                file_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                numero VARCHAR(40) NOT NULL,
                fecha DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                aerolinea VARCHAR(100) DEFAULT NULL,
                emitido TINYINT(1) DEFAULT 1 NOT NULL,
                segmentos JSON NOT NULL,
                notas JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_5AA69BB293CB796C (file_id),
                INDEX idx_vuelo_fecha (fecha),
                UNIQUE INDEX uniq_vuelo_file_numero_fecha (file_id, numero, fecha),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_grupo_vuelo (
                vuelo_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                grupo_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_CEBF204E4FF34720 (vuelo_id),
                INDEX IDX_CEBF204E9C833003 (grupo_id),
                PRIMARY KEY(vuelo_id, grupo_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE cotizacion_vuelo ADD CONSTRAINT FK_5AA69BB293CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_grupo_vuelo ADD CONSTRAINT FK_CEBF204E4FF34720 FOREIGN KEY (vuelo_id) REFERENCES cotizacion_vuelo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_grupo_vuelo ADD CONSTRAINT FK_CEBF204E9C833003 FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_grupo_vuelo DROP FOREIGN KEY FK_CEBF204E4FF34720');
        $this->addSql('ALTER TABLE cotizacion_grupo_vuelo DROP FOREIGN KEY FK_CEBF204E9C833003');
        $this->addSql('ALTER TABLE cotizacion_vuelo DROP FOREIGN KEY FK_5AA69BB293CB796C');
        $this->addSql('DROP TABLE cotizacion_grupo_vuelo');
        $this->addSql('DROP TABLE cotizacion_vuelo');
    }
}
