<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El veto de WhatsApp pasa a ser del NÚMERO, no de la persona.
 *
 * `whatsapp_disabled` vivía en la conversación, o sea en la PERSONA: un número muerto apagaba
 * el canal para todo el hilo —y desde la fusión, para todos sus asuntos—, incluido el número
 * bueno de quien tiene dos. El comando de fusión lo propagaba además por el lado pesimista.
 *
 * Pero Meta rechaza un ENVÍO A UN DESTINO. El veto es de ese destino.
 *
 * ⚠️ La columna de la conversación **se queda**, como espejo. No es dejadez: es entrada de
 * `MessageRuleEngineListener::CAMPOS_CRITICOS`, que lee el change set de Doctrine y sólo ve
 * campos mapeados. Convertirla en getter derivado habría apagado la re-evaluación de reglas
 * —y con ella el rescate de los mensajes cancelados por no tener canal— sin un solo error.
 * Mismo trato que `guest_phone`.
 *
 * Llegan también `principal` (cuál es la salida por defecto cuando hay varias) y `retirado_en`,
 * que es una **lápida**: sin ella, retirar un número sería imposible, porque
 * `upsertFromContext()` re-registra los identificadores del dominio en CADA recálculo y el
 * siguiente pull de Beds24 lo resucitaría.
 */
final class Version20260820360000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bloqueo, principal y retirada en msg_identidad; el flag de la conversación queda de espejo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_identidad
            ADD bloqueado TINYINT(1) DEFAULT 0 NOT NULL,
            ADD bloqueado_motivo VARCHAR(255) DEFAULT NULL,
            ADD principal TINYINT(1) DEFAULT 0 NOT NULL,
            ADD retirado_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // ── El teléfono que ya había es el principal ────────────────────────
        // Hoy cada hilo tiene exactamente uno, así que no hay nada que elegir. Se marca para
        // que el día que aparezca un segundo el sistema ya sepa cuál era el bueno, en vez de
        // tener que adivinarlo entonces.
        $this->addSql('UPDATE msg_identidad SET principal = 1 WHERE tipo = "telefono"');

        // ── El veto se muda al número que lo tenía ──────────────────────────
        // Copia FIEL del estado actual, sin juzgar si el bloqueo era correcto. Revisar los
        // vetos vivos es una decisión de persona y se hace mirando la prueba de vida —si ese
        // número tuvo mensajes ENTRANTES, funciona—, no dentro de una migración.
        $this->addSql(<<<'SQL'
            UPDATE msg_identidad i
              JOIN msg_conversation c ON c.id = i.conversacion_id
               SET i.bloqueado = 1,
                   i.bloqueado_motivo = c.whatsapp_disabled_reason
             WHERE i.tipo = 'telefono'
               AND c.whatsapp_disabled = 1
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_identidad
            DROP bloqueado, DROP bloqueado_motivo, DROP principal, DROP retirado_en');
    }
}
