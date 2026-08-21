<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La cola de correo necesita `endpoint_id`, aunque el correo no tenga endpoint.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * `AbstractExchangeRepository::claimRunnable()` arma los lotes en **SQL nativo**, agrupando por
 * `(config_id, endpoint_id)` para garantizar que un lote sea homogéneo. Sin la columna, la
 * reclamación falla con «Unknown column 'endpoint_id'» y la cola se queda llena — que es lo que
 * pasó al mandar el primer correo.
 *
 * Se le da al correo la MISMA forma que a los demás canales en vez de sembrar excepciones en el
 * motor: cambiarlo para un caso obliga a revisar los cinco que ya funcionan.
 *
 * El endpoint sigue siendo un **marcador**: no apunta a ninguna ruta, porque el destino de un
 * correo es un buzón.
 */
final class Version20260820420000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade endpoint_id a la cola de correo: el motor agrupa los lotes por él.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_email_send_queue
            ADD endpoint_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');

        $this->addSql('ALTER TABLE msg_email_send_queue ADD CONSTRAINT FK_MSG_EMAIL_ENDPOINT
            FOREIGN KEY (endpoint_id) REFERENCES exchange_exchange_endpoint (id) ON DELETE SET NULL');

        $this->addSql('CREATE INDEX IDX_91FF846521AF7E36 ON msg_email_send_queue (endpoint_id)');

        // Lo que ya estuviera encolado se queda sin endpoint y el lote no se podría armar.
        $this->addSql(<<<'SQL'
            UPDATE msg_email_send_queue
               SET endpoint_id = (SELECT id FROM exchange_exchange_endpoint
                                   WHERE provider = 'email' AND accion = 'email_send' LIMIT 1)
             WHERE endpoint_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_email_send_queue DROP FOREIGN KEY FK_MSG_EMAIL_ENDPOINT');
        $this->addSql('DROP INDEX IDX_91FF846521AF7E36 ON msg_email_send_queue');
        $this->addSql('ALTER TABLE msg_email_send_queue DROP endpoint_id');
    }
}
