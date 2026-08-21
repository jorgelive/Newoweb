<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `endpoint_id` del correo pasa a obligatorio, como en las otras seis colas.
 *
 * La primera versión lo dejó nullable porque el correo no tiene rutas —su destino es un buzón—
 * y el contrato lo permitía: `ExchangeQueueItemInterface::getEndpoint()` devolvía
 * `?EndpointInterface`. Pero ese `?` era mentira: `HomogeneousBatch` lo exige no nulo y
 * `claimRunnable()` agrupa por `(config_id, endpoint_id)` en SQL nativo.
 *
 * Cerrado el contrato, la columna se alinea. Un canal sin rutas usa un endpoint **marcador**.
 */
final class Version20260820440000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'endpoint_id obligatorio en la cola de correo, como en el resto.';
    }

    public function up(Schema $schema): void
    {
        // Red por si quedara alguna fila sin él; la anterior ya las rellenó.
        $this->addSql(<<<'SQL'
            UPDATE msg_email_send_queue
               SET endpoint_id = (SELECT id FROM exchange_exchange_endpoint
                                   WHERE provider = 'email' AND accion = 'email_send' LIMIT 1)
             WHERE endpoint_id IS NULL
        SQL);

        // ⚠️ El nombre de la clave foránea se BUSCA, no se escribe.
        //
        // La migración anterior la creó como `FK_MSG_EMAIL_ENDPOINT` y en desarrollo se rehízo a
        // mano con el nombre que Doctrine genera (`FK_91FF846521AF7E36`). Escribir uno de los dos
        // rompe en el otro entorno — y una migración que falla a mitad deja la tabla a medias,
        // porque el DDL de MySQL no es transaccional.
        $nombreFk = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'msg_email_send_queue\'
                AND COLUMN_NAME = \'endpoint_id\' AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1'
        );

        if (is_string($nombreFk) && $nombreFk !== '') {
            $this->addSql(sprintf('ALTER TABLE msg_email_send_queue DROP FOREIGN KEY %s', $nombreFk));
        }

        $this->addSql('ALTER TABLE msg_email_send_queue MODIFY endpoint_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE msg_email_send_queue ADD CONSTRAINT FK_91FF846521AF7E36
            FOREIGN KEY (endpoint_id) REFERENCES exchange_exchange_endpoint (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_email_send_queue DROP FOREIGN KEY FK_91FF846521AF7E36');
        $this->addSql('ALTER TABLE msg_email_send_queue MODIFY endpoint_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE msg_email_send_queue ADD CONSTRAINT FK_91FF846521AF7E36
            FOREIGN KEY (endpoint_id) REFERENCES exchange_exchange_endpoint (id) ON DELETE SET NULL');
    }
}
