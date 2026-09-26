<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crea `cotizacion_pedido`: la hermana de `pms_peticion`, para Travel.
 *
 * Nace pegada a la CONVERSACIÓN (`conversacion_id`, texto — el mismo desacople que ya usa
 * `pms_peticion`, otro módulo), no al expediente: al pedirse, el expediente casi nunca existe
 * todavía. `file_id` se rellena solo cuando `CotizacionSincronizadorDeEnlace` vincula el primer
 * expediente a esa conversación — ver su docblock y el de `CotizacionPedido`.
 *
 * Ver `docs/Cotizaciones.md`, «Pendientes de cotizar: CotizacionPedido».
 */
final class Version20260926180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea cotizacion_pedido: pendientes de trabajar del área de Cotizaciones, colgados de la conversación.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cotizacion_pedido (
              id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
              creada_por_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
              file_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
              efectuada_por_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
              conversacion_id VARCHAR(36) NOT NULL,
              texto VARCHAR(180) NOT NULL,
              efectuada_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_C100EFA5EBBFCAF6 (creada_por_id),
              INDEX IDX_C100EFA593CB796C (file_id),
              INDEX IDX_C100EFA5621986CE (efectuada_por_id),
              INDEX idx_pedido_conversacion (conversacion_id, efectuada_at),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cotizacion_pedido
            ADD CONSTRAINT FK_C100EFA5EBBFCAF6 FOREIGN KEY (creada_por_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cotizacion_pedido
            ADD CONSTRAINT FK_C100EFA593CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cotizacion_pedido
            ADD CONSTRAINT FK_C100EFA5621986CE FOREIGN KEY (efectuada_por_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pedido DROP FOREIGN KEY FK_C100EFA5EBBFCAF6');
        $this->addSql('ALTER TABLE cotizacion_pedido DROP FOREIGN KEY FK_C100EFA593CB796C');
        $this->addSql('ALTER TABLE cotizacion_pedido DROP FOREIGN KEY FK_C100EFA5621986CE');
        $this->addSql('DROP TABLE cotizacion_pedido');
    }
}
