<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bitácora de cambios de estado de los servicios de La Biblia.
 *
 * `operacion_estado_bitacora`: una fila por cambio de `estadoReservaProveedor` o
 * `estadoOperacion`, escrita por `EstadoBitacoraListener`. Y `estado_reserva_proveedor_desde`
 * en el servicio, para pintar «desde hace X» sin consultar la bitácora en cada fila.
 *
 * No se rellenan los cambios pasados: la historia empieza a registrarse desde aquí. Las filas
 * existentes quedan sin «desde» hasta su próximo cambio de estado, que es correcto — no se
 * puede inventar cuándo pasó algo que no se registró.
 */
final class Version20260817180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bitácora de estados de OperacionServicio + estado_reserva_proveedor_desde.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE operacion_estado_bitacora (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                operacion_servicio_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                campo VARCHAR(20) NOT NULL,
                valor_anterior VARCHAR(30) DEFAULT NULL,
                valor_nuevo VARCHAR(30) NOT NULL,
                usuario_id VARCHAR(36) DEFAULT NULL,
                usuario_nombre VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_bitacora_servicio_campo (operacion_servicio_id, campo),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE operacion_estado_bitacora
            ADD CONSTRAINT FK_BITACORA_SERVICIO
            FOREIGN KEY (operacion_servicio_id) REFERENCES operacion_servicio (id) ON DELETE CASCADE
        SQL);

        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'operacion_servicio'
              AND COLUMN_NAME = 'estado_reserva_proveedor_desde'
        SQL);

        if (!$existe) {
            $this->addSql("ALTER TABLE operacion_servicio ADD estado_reserva_proveedor_desde DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_estado_bitacora DROP FOREIGN KEY FK_BITACORA_SERVICIO');
        $this->addSql('DROP TABLE operacion_estado_bitacora');
        $this->addSql('ALTER TABLE operacion_servicio DROP estado_reserva_proveedor_desde');
    }
}
