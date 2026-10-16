<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Álbumes de experiencias de la web: `front_experiencia_album` y sus fotos y vídeos
 * (`front_experiencia_medio`). Ver `ExperienciaAlbum` y docs/WebPublica.md.
 *
 * Tablas nuevas y vacías: nada que rellenar. Las columnas JSON (`titulo`, `texto`, `pie`) nacen
 * `NOT NULL` sin filas, así que no hay literal `null` que heredar (CLAUDE.md).
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Álbumes de experiencias de la web (front_experiencia_album, front_experiencia_medio)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE front_experiencia_album (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', titulo JSON NOT NULL, texto JSON NOT NULL, destino VARCHAR(120) DEFAULT NULL, fecha DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', slug VARCHAR(80) DEFAULT NULL, orden INT DEFAULT 0 NOT NULL, autorizado TINYINT(1) DEFAULT 0 NOT NULL, publicado TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_960F56E5989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE front_experiencia_medio (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', album_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', tipo VARCHAR(10) NOT NULL, orden INT DEFAULT 0 NOT NULL, es_portada TINYINT(1) DEFAULT 0 NOT NULL, pie JSON NOT NULL, video_url VARCHAR(255) DEFAULT NULL, image_name VARCHAR(255) DEFAULT NULL, image_size INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', token VARCHAR(16) DEFAULT NULL, sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_2203B4AD1137ABCF (album_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE front_experiencia_medio ADD CONSTRAINT FK_2203B4AD1137ABCF FOREIGN KEY (album_id) REFERENCES front_experiencia_album (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE front_experiencia_medio DROP FOREIGN KEY FK_2203B4AD1137ABCF');
        $this->addSql('DROP TABLE front_experiencia_medio');
        $this->addSql('DROP TABLE front_experiencia_album');
    }
}
