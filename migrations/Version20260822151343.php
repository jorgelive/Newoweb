<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puntos geográficos de inicio y fin de segmento.
 *
 * ⚠️ Escrita a mano a partir del diff, no tal cual salió. El `diff` quería además borrar las
 * tablas `energia_*` y `pms_evento_calendario_backup_20260808` —viven en la base y ya no tienen
 * entidad— y eso habría destruido datos en producción de rebote, en una migración que no va de
 * eso. Si esas tablas sobran, se retiran en su propia migración y mirando qué hay dentro.
 */
final class Version20260822151343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puntos geográficos de inicio y fin de segmento: tabla travel_punto y cuatro campos en travel_segmento.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE travel_punto (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                lugar_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                organizacion_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                nombre VARCHAR(120) NOT NULL,
                tipo VARCHAR(30) DEFAULT 'otro' NOT NULL,
                direccion VARCHAR(255) DEFAULT NULL,
                referencia LONGTEXT DEFAULT NULL,
                activo TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_1FAAE6DBB5A3803B (lugar_id),
                INDEX IDX_1FAAE6DB90B1019E (organizacion_id),
                UNIQUE INDEX uniq_travel_punto_nombre (nombre),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE travel_punto ADD CONSTRAINT FK_1FAAE6DBB5A3803B FOREIGN KEY (lugar_id) REFERENCES travel_lugar (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE travel_punto ADD CONSTRAINT FK_1FAAE6DB90B1019E FOREIGN KEY (organizacion_id) REFERENCES travel_organizacion (id) ON DELETE SET NULL');

        $this->addSql("ALTER TABLE travel_segmento ADD inicio_punto_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', ADD fin_punto_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', ADD inicio_modo VARCHAR(20) DEFAULT 'sin_definir' NOT NULL, ADD fin_modo VARCHAR(20) DEFAULT 'sin_definir' NOT NULL");
        $this->addSql('ALTER TABLE travel_segmento ADD CONSTRAINT FK_58BEE4232042125 FOREIGN KEY (inicio_punto_id) REFERENCES travel_punto (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE travel_segmento ADD CONSTRAINT FK_58BEE423230FA010 FOREIGN KEY (fin_punto_id) REFERENCES travel_punto (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_58BEE4232042125 ON travel_segmento (inicio_punto_id)');
        $this->addSql('CREATE INDEX IDX_58BEE423230FA010 ON travel_segmento (fin_punto_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_segmento DROP FOREIGN KEY FK_58BEE4232042125');
        $this->addSql('ALTER TABLE travel_segmento DROP FOREIGN KEY FK_58BEE423230FA010');
        $this->addSql('DROP INDEX IDX_58BEE4232042125 ON travel_segmento');
        $this->addSql('DROP INDEX IDX_58BEE423230FA010 ON travel_segmento');
        $this->addSql('ALTER TABLE travel_segmento DROP inicio_punto_id, DROP fin_punto_id, DROP inicio_modo, DROP fin_modo');

        $this->addSql('ALTER TABLE travel_punto DROP FOREIGN KEY FK_1FAAE6DBB5A3803B');
        $this->addSql('ALTER TABLE travel_punto DROP FOREIGN KEY FK_1FAAE6DB90B1019E');
        $this->addSql('DROP TABLE travel_punto');
    }
}
