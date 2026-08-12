<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pone al día los METADATOS de `pms_unidad_servicio_canal` y `pms_evento_limpieza`.
 *
 * Son las seis sentencias que `doctrine:schema:validate` venía reclamando desde antes del
 * despliegue del 2026-08-12: las tablas se crearon a mano —o con nombres de índice
 * explícitos— y el mapping espera otra cosa. Ninguna cambia estructura, tipo ni datos:
 *
 * - `COMMENT '(DC2Type:uuid)'` en cuatro columnas `BINARY(16)`. Es la marca con la que
 *   Doctrine reconoce que ese binario es un UUID al hidratar. Las columnas ya funcionaban;
 *   lo que faltaba era la etiqueta.
 * - Renombre de cuatro índices, de los nombres explícitos (`idx_evento_limpieza_evento`) a
 *   los que Doctrine genera (`IDX_86E941D987A5F842`). Mismo índice, mismas columnas.
 *
 * ### Por qué una migración y no `schema:update --force`
 *
 * `--force` las aplicaría igual, pero sin dejar rastro en `doctrine_migration_versions`: el
 * siguiente entorno volvería a mostrar exactamente el mismo diff, y alguien volvería a
 * preguntarse si es suyo. Escrito aquí, se sincroniza una vez y para todos.
 *
 * ⚠️ Si los nombres de índice originales fueran deliberados, la alternativa era declararlos
 * en la entidad con `#[ORM\Index(name: …)]` y dejar que Doctrine aceptara los suyos. Se optó
 * por alinearse con el mapping porque no había nada que dependiera de esos nombres.
 */
final class Version20260812270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sincroniza comentarios DC2Type:uuid y nombres de índice en pms_unidad_servicio_canal y pms_evento_limpieza.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_unidad_servicio_canal CHANGE unidad_id unidad_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE pms_unidad_servicio_canal RENAME INDEX idx_unidad_servicio_canal_unidad TO IDX_E5F11B349D01464C');
        $this->addSql('ALTER TABLE pms_unidad_servicio_canal RENAME INDEX idx_unidad_servicio_canal_channel TO IDX_E5F11B3472F5A1AA');
        $this->addSql("ALTER TABLE pms_evento_limpieza CHANGE evento_id evento_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', CHANGE user_id user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE pms_evento_limpieza RENAME INDEX idx_evento_limpieza_evento TO IDX_86E941D987A5F842');
        $this->addSql('ALTER TABLE pms_evento_limpieza RENAME INDEX idx_evento_limpieza_user TO IDX_86E941D9A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_limpieza RENAME INDEX IDX_86E941D9A76ED395 TO idx_evento_limpieza_user');
        $this->addSql('ALTER TABLE pms_evento_limpieza RENAME INDEX IDX_86E941D987A5F842 TO idx_evento_limpieza_evento');
        $this->addSql('ALTER TABLE pms_evento_limpieza CHANGE evento_id evento_id BINARY(16) NOT NULL, CHANGE user_id user_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE pms_unidad_servicio_canal RENAME INDEX IDX_E5F11B3472F5A1AA TO idx_unidad_servicio_canal_channel');
        $this->addSql('ALTER TABLE pms_unidad_servicio_canal RENAME INDEX IDX_E5F11B349D01464C TO idx_unidad_servicio_canal_unidad');
        $this->addSql('ALTER TABLE pms_unidad_servicio_canal CHANGE unidad_id unidad_id BINARY(16) NOT NULL');
    }
}
