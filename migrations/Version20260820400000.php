<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El canal de CORREO: su cola, su configuración y el endpoint marcador.
 *
 * ⚠️ **Escrita a mano y no con `migrations:diff`.** La diff proponía además soltar las tablas
 * `energia_*`, que no tienen nada que ver con esto: son de un módulo cuyo mapeo no está en el
 * código. Aceptarla habría borrado datos de producción por el camino.
 *
 * ── Qué se siembra y por qué ────────────────────────────────────────────────
 * - **`exchange_email_config`**: el buzón remitente. Las credenciales NO están aquí — el DSN de
 *   Graph vive en `MAILER_DSN` (ver `docs/CorreoSaliente.md`), y un secreto en una fila acaba en
 *   un volcado. Nace **inactivo**: el remitente hay que ponerlo a mano, y activarlo con un
 *   remitente vacío dejaría los correos fallando de uno en uno.
 * - **El endpoint `email_send`**: un marcador. `HomogeneousBatch` exige un endpoint porque los
 *   demás canales hablan con una API; el destino de un correo es un buzón. Se prefiere una fila
 *   inerte a hacer opcional el endpoint en el motor, que obligaría a revisar los canales que ya
 *   funcionan.
 *
 * ⚠️ El canal `email` **no se activa aquí**. Se activa cuando el remitente esté puesto.
 */
final class Version20260820400000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Canal de correo: cola de envío, configuración del buzón y endpoint marcador.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exchange_email_config (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            nombre VARCHAR(150) DEFAULT NULL,
            activo TINYINT(1) DEFAULT 1 NOT NULL,
            remitente VARCHAR(180) NOT NULL,
            remitente_nombre VARCHAR(150) DEFAULT NULL,
            responder_a VARCHAR(180) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE msg_email_send_queue (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            message_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            config_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            destination_email VARCHAR(180) DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) DEFAULT \'pending\' NOT NULL,
            run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            locked_at DATETIME DEFAULT NULL,
            locked_by VARCHAR(100) DEFAULT NULL,
            failed_reason LONGTEXT DEFAULT NULL,
            retry_count INT DEFAULT 0 NOT NULL,
            max_attempts SMALLINT DEFAULT 3 NOT NULL,
            external_id VARCHAR(255) DEFAULT NULL,
            last_request_raw LONGTEXT DEFAULT NULL,
            last_response_raw LONGTEXT DEFAULT NULL,
            last_http_code SMALLINT DEFAULT NULL,
            execution_result JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_91FF8465537A1329 (message_id),
            INDEX IDX_91FF846524DB0683 (config_id),
            INDEX idx_msg_email_worker (status, run_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE msg_email_send_queue ADD CONSTRAINT FK_91FF8465537A1329
            FOREIGN KEY (message_id) REFERENCES msg_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE msg_email_send_queue ADD CONSTRAINT FK_91FF846524DB0683
            FOREIGN KEY (config_id) REFERENCES exchange_email_config (id) ON DELETE SET NULL');

        // El buzón remitente, INACTIVO hasta que alguien ponga la dirección. Tiene que ser un
        // buzón real del tenant: Graph rechaza enviar «como» un alias, y el fallo llega tarde
        // — está documentado en docs/CorreoSaliente.md §6.1.
        $this->addSql('INSERT INTO exchange_email_config (id, nombre, activo, remitente, remitente_nombre, created_at)
            VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'Buzón de salida\', 0, \'\', \'OpenPeru\', NOW())');

        // El endpoint marcador. No apunta a ninguna ruta: el cliente de correo lo ignora.
        $this->addSql('INSERT INTO exchange_exchange_endpoint (id, provider, nombre, accion, endpoint, metodo, descripcion, activo, created_at)
            VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'email\', \'Envío de correo\', \'email_send\', \'\', \'POST\',
                    \'Marcador: el motor exige un endpoint por lote y el destino de un correo es un buzón, no una ruta.\',
                    1, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM exchange_exchange_endpoint WHERE provider = \'email\' AND accion = \'email_send\'');
        $this->addSql('ALTER TABLE msg_email_send_queue DROP FOREIGN KEY FK_91FF8465537A1329');
        $this->addSql('ALTER TABLE msg_email_send_queue DROP FOREIGN KEY FK_91FF846524DB0683');
        $this->addSql('DROP TABLE msg_email_send_queue');
        $this->addSql('DROP TABLE exchange_email_config');
    }
}
