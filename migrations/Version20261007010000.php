<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Web pública (openperu.pe): campos web del catálogo de tours y Libro de Reclamaciones.
 *
 * Ver docs/WebPublica.md y docs/PlanWebPublica.md.
 *
 * ⚠️ **Las dos columnas JSON entran nulables, se rellenan y sólo entonces pasan a `NOT NULL`.**
 * Un `ADD ... JSON NOT NULL` sobre una tabla con filas las deja con el literal JSON `null`, que
 * no es SQL NULL: satisface la restricción, no lo caza un `IS NULL`, y Doctrine revienta con un
 * `TypeError` al LEER la fila, días después (CLAUDE.md, 09/09/2026).
 */
final class Version20261007010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web pública: publicado_web/slug/titulo_web/descripcion_web en cotizacion_catalogo y front_libro_reclamacion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_catalogo ADD publicado_web TINYINT(1) DEFAULT 0 NOT NULL, ADD slug VARCHAR(80) DEFAULT NULL, ADD titulo_web JSON DEFAULT NULL, ADD descripcion_web JSON DEFAULT NULL, ADD sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE cotizacion_catalogo SET titulo_web = JSON_ARRAY() WHERE titulo_web IS NULL OR JSON_TYPE(titulo_web) = 'NULL'");
        $this->addSql("UPDATE cotizacion_catalogo SET descripcion_web = JSON_ARRAY() WHERE descripcion_web IS NULL OR JSON_TYPE(descripcion_web) = 'NULL'");
        $this->addSql('ALTER TABLE cotizacion_catalogo MODIFY titulo_web JSON NOT NULL, MODIFY descripcion_web JSON NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_cotizacion_catalogo_slug ON cotizacion_catalogo (slug)');

        $this->addSql("CREATE TABLE front_libro_reclamacion (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', correlativo VARCHAR(20) NOT NULL, fecha DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', sitio VARCHAR(100) NOT NULL, consumidor_nombre VARCHAR(160) NOT NULL, consumidor_documento_tipo VARCHAR(12) NOT NULL, consumidor_documento_numero VARCHAR(20) NOT NULL, consumidor_domicilio VARCHAR(240) NOT NULL, consumidor_telefono VARCHAR(40) DEFAULT NULL, consumidor_email VARCHAR(160) NOT NULL, consumidor_es_menor TINYINT(1) DEFAULT 0 NOT NULL, apoderado_nombre VARCHAR(160) DEFAULT NULL, bien_tipo VARCHAR(12) NOT NULL, bien_descripcion LONGTEXT NOT NULL, bien_monto NUMERIC(10, 2) DEFAULT NULL, tipo VARCHAR(12) NOT NULL, detalle LONGTEXT NOT NULL, pedido LONGTEXT NOT NULL, respuesta LONGTEXT DEFAULT NULL, fecha_respuesta DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', atendida TINYINT(1) DEFAULT 0 NOT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_front_libro_reclamacion_fecha (fecha), UNIQUE INDEX uniq_front_libro_reclamacion_correlativo (correlativo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE front_libro_reclamacion');
        $this->addSql('DROP INDEX uniq_cotizacion_catalogo_slug ON cotizacion_catalogo');
        $this->addSql('ALTER TABLE cotizacion_catalogo DROP publicado_web, DROP slug, DROP titulo_web, DROP descripcion_web, DROP sobreescribir_traduccion');
    }
}
