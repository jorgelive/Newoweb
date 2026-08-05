<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vínculo explícito `msg_message.rule_id` → `msg_rule`.
 *
 * El MessageRuleEngine identificaba "el mensaje de esta regla" por su PLANTILLA. Dos reglas
 * que compartieran plantilla (un recordatorio a -7 días y otro a -1 día) se reconocían como
 * el mismo mensaje: la segunda encontraba el de la primera, le reescribía `scheduled_at` y
 * sólo salía un envío. Ver docs/Mensajeria.md §4.3.
 *
 * `ON DELETE SET NULL`: borrar la regla no debe borrar el histórico de lo ya enviado; el
 * mensaje queda huérfano y el motor lo trata como legado (emparejado por plantilla).
 *
 * Es idempotente a propósito: el esquema de `msg_*` se venía creando con `doctrine:schema:update`
 * y no tiene migraciones previas, así que la columna puede existir ya en algunos entornos.
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade msg_message.rule_id para que el motor de reglas distinga reglas que comparten plantilla.';
    }

    public function up(Schema $schema): void
    {
        // Cada paso se comprueba por separado, y NO con un skipIf global al principio.
        //
        // El despliegue seguro aplica el ALTER a mano ANTES del pull (la columna es nullable, así
        // que el código viejo la ignora y ningún cron queda a medias). Con un skipIf global, esa
        // secuencia saltaba también el índice, la FK y el backfill, y dejaba la migración marcada
        // como aplicada con el trabajo a medio hacer.
        if (!$this->columnaExiste()) {
            $this->addSql("ALTER TABLE msg_message ADD rule_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        }

        // Los nombres son los que genera Doctrine para esta relación. Ponerlos "bonitos" haría
        // que cada `doctrine:schema:update --dump-sql` posterior propusiera recrearlos.
        if (!$this->restriccionExiste('FK_726CB64E744E0351')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE msg_message
                ADD CONSTRAINT FK_726CB64E744E0351
                FOREIGN KEY (rule_id) REFERENCES msg_rule (id) ON DELETE SET NULL
            SQL);
        }

        if (!$this->indiceExiste('IDX_726CB64E744E0351')) {
            $this->addSql('CREATE INDEX IDX_726CB64E744E0351 ON msg_message (rule_id)');
        }

        // Backfill conservador: sólo se adopta la regla cuando la plantilla identifica a UNA
        // sola. Si varias reglas comparten plantilla el vínculo es ambiguo justo por el bug que
        // esta migración cierra, así que se deja NULL y el motor sigue emparejando por plantilla.
        //
        // Es re-ejecutable: el `rule_id IS NULL` del WHERE lo hace idempotente, y además
        // garantiza que esta migración siempre emita al menos una sentencia.
        $this->addSql(<<<'SQL'
            UPDATE msg_message m
            JOIN (
                SELECT template_id, MIN(id) AS rule_id
                FROM msg_rule
                WHERE template_id IS NOT NULL
                GROUP BY template_id
                HAVING COUNT(*) = 1
            ) r ON r.template_id = m.template_id
            SET m.rule_id = r.rule_id
            WHERE m.sender_type = 'system' AND m.rule_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->columnaExiste(), 'La columna msg_message.rule_id no existe.');

        if ($this->restriccionExiste('FK_726CB64E744E0351')) {
            $this->addSql('ALTER TABLE msg_message DROP FOREIGN KEY FK_726CB64E744E0351');
        }

        if ($this->indiceExiste('IDX_726CB64E744E0351')) {
            $this->addSql('DROP INDEX IDX_726CB64E744E0351 ON msg_message');
        }

        $this->addSql('ALTER TABLE msg_message DROP rule_id');
    }

    private function columnaExiste(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'msg_message'
               AND COLUMN_NAME = 'rule_id'"
        );
    }

    private function indiceExiste(string $nombre): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'msg_message'
               AND INDEX_NAME = ?",
            [$nombre]
        );
    }

    private function restriccionExiste(string $nombre): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'msg_message'
               AND CONSTRAINT_NAME = ?",
            [$nombre]
        );
    }
}
