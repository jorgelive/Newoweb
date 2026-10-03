<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quién del equipo escribió cada mensaje: `msg_message.autor_id` → `user`. Lo rellena
 * `AutorDelMensajeListener` con quien esté conectado. Lo anterior queda vacío: no se guardaba.
 */
final class Version20261003160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_message.autor_id: la persona del equipo que escribió el mensaje';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE msg_message ADD autor_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE msg_message ADD CONSTRAINT FK_726CB64E14D45BBE FOREIGN KEY (autor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_726CB64E14D45BBE ON msg_message (autor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_message DROP FOREIGN KEY FK_726CB64E14D45BBE');
        $this->addSql('DROP INDEX IDX_726CB64E14D45BBE ON msg_message');
        $this->addSql('ALTER TABLE msg_message DROP autor_id');
    }
}
