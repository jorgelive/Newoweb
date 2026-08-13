<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cada mensaje encolado sabe de qué ASUNTO es: `asunto_type` + `asunto_id` en `msg_message`.
 *
 * Es el tercer paso de la cirugía de docs/Mensajeria.md §20. Con varios asuntos colgando del
 * mismo hilo, la regla deja de bastar como identidad del mensaje programado: la misma regla
 * programa legítimamente un recordatorio POR RESERVA, y sin estas columnas el motor encontraba
 * el mensaje de la reserva A al evaluar la B y le pisaba la fecha.
 *
 * ── Por qué dos VARCHAR y no una FK a `pms_conversacion_enlace` ─────────────
 * Los enlaces viven en una tabla POR MÓDULO (una FK ataría la mensajería al esquema del PMS,
 * la dependencia que se está quitando), y la identidad real del asunto es el par
 * `(context_type, context_id)`: sobrevive a que un repoblado borre y recree la fila del enlace.
 * El razonamiento completo está en `Message::$asuntoType`.
 *
 * ── NULL = era anterior ─────────────────────────────────────────────────────
 * No hay backfill a propósito: un mensaje sin estampar pertenece por definición al asunto
 * propio de su conversación (`context_type`/`context_id` de ella), y el motor lo ADOPTA —lo
 * estampa— la primera vez que lo vuelve a tocar. Rellenarlo aquí con un UPDATE masivo sería
 * repetir en SQL la misma deducción que ya hace el código: dos criterios condenados a
 * separarse, el fallo que ya costó caro con la normalización de teléfonos.
 */
final class Version20260812310000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'asunto_type/asunto_id en msg_message: cada mensaje programado sabe de qué asunto es';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE msg_message ADD asunto_type VARCHAR(50) DEFAULT NULL, ADD asunto_id VARCHAR(100) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_msg_asunto ON msg_message (asunto_type, asunto_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_msg_asunto ON msg_message');
        $this->addSql('ALTER TABLE msg_message DROP asunto_type, DROP asunto_id');
    }
}
