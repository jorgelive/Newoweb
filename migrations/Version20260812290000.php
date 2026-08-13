<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Primer corte de la cirugía: el CONTACTO y los ENLACES nacen, sin que nada cambie todavía.
 *
 * ── Qué problema se está abriendo en canal ──────────────────────────────────
 * `msg_conversation` intenta ser dos cosas a la vez —el hilo con una PERSONA y el expediente de
 * un ACTIVO— y tiene dos creadores con claves distintas escribiendo en ella:
 * `WhatsappMetaReceivePersister::resolveConversation()` busca por teléfono, y
 * `MessageConversationFactory` por `(context_type, context_id)`. Medido en producción: 20
 * teléfonos con más de una conversación; uno con 247 mensajes repartidos en dos hilos; un
 * titular con 7 conversaciones creadas el mismo día, una por cada casita que reservó.
 *
 * Esta migración sólo crea el sitio donde va a vivir la separación:
 *
 *   maestro_contacto         → la PERSONA: su número, su nombre, su idioma
 *   pms_conversacion_enlace  → el ASUNTO: qué reserva se habla en qué hilo, uno a muchos
 *
 * ── No cambia NADA de lo que corre hoy ──────────────────────────────────────
 * `msg_conversation` conserva `context_type`, `context_id`, `guest_name`, `guest_phone` y su
 * `context_data` intactos, y siguen siendo la verdad para `MessageRuleEngine` y para todo lo
 * que segmenta por contexto. Las tablas nuevas nacen **vacías y sin lectores**: poblarlas y
 * enseñarle al motor a programar por asunto son los dos pasos siguientes, y cada uno con su
 * migración. Si esto se despliega solo, el sistema se comporta exactamente igual.
 *
 * ── El teléfono del contacto NO es único ────────────────────────────────────
 * Un matrimonio comparte número y una agencia usa el suyo para los grupos que manda: son
 * personas distintas con el mismo teléfono, no duplicados. Y la tabla se va a poblar desde
 * columnas sucias —números de OTA con el código de país repetido— que harían fallar la
 * migración entera por un puñado de filas malas. La deduplicación es de quien resuelve un
 * número entrante, con el mismo criterio que ya usa `PmsReservaRepository`, no del esquema.
 */
final class Version20260812290000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'maestro_contacto y pms_conversacion_enlace (vacías): el sitio para separar persona y asunto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE maestro_contacto (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', pais_id VARCHAR(2) DEFAULT NULL, idioma_id VARCHAR(2) DEFAULT NULL, nombre VARCHAR(120) DEFAULT NULL, apellido VARCHAR(120) DEFAULT NULL, telefono VARCHAR(30) DEFAULT NULL, telefono2 VARCHAR(30) DEFAULT NULL, telefono2_es_principal TINYINT(1) DEFAULT 0 NOT NULL, email VARCHAR(180) DEFAULT NULL, nota LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_6653FD38C604D5C6 (pais_id), INDEX IDX_6653FD38DEDC0611 (idioma_id), INDEX idx_contacto_telefono (telefono), INDEX idx_contacto_telefono2 (telefono2), INDEX idx_contacto_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE pms_conversacion_enlace (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', conversacion_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', reserva_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', vinculo VARCHAR(30) DEFAULT 'ninguno' NOT NULL, milestones JSON DEFAULT NULL, origen VARCHAR(50) DEFAULT NULL, agencia VARCHAR(100) DEFAULT NULL, status_tag VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_D58944CEABD5A1D6 (conversacion_id), INDEX idx_enlace_reserva (reserva_id), UNIQUE INDEX uniq_enlace_conversacion_reserva (conversacion_id, reserva_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE maestro_contacto ADD CONSTRAINT FK_6653FD38C604D5C6 FOREIGN KEY (pais_id) REFERENCES maestro_pais (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE maestro_contacto ADD CONSTRAINT FK_6653FD38DEDC0611 FOREIGN KEY (idioma_id) REFERENCES maestro_idioma (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE pms_conversacion_enlace ADD CONSTRAINT FK_D58944CEABD5A1D6 FOREIGN KEY (conversacion_id) REFERENCES msg_conversation (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE pms_conversacion_enlace ADD CONSTRAINT FK_D58944CED67139E8 FOREIGN KEY (reserva_id) REFERENCES pms_reserva (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Se sueltan las FK antes de tirar las tablas: sin esto, el orden de borrado depende de
        // en qué motor se ejecute y el rollback falla justo cuando más prisa hay.
        $this->addSql('ALTER TABLE pms_conversacion_enlace DROP FOREIGN KEY FK_D58944CEABD5A1D6');
        $this->addSql('ALTER TABLE pms_conversacion_enlace DROP FOREIGN KEY FK_D58944CED67139E8');
        $this->addSql('ALTER TABLE maestro_contacto DROP FOREIGN KEY FK_6653FD38C604D5C6');
        $this->addSql('ALTER TABLE maestro_contacto DROP FOREIGN KEY FK_6653FD38DEDC0611');
        $this->addSql('DROP TABLE pms_conversacion_enlace');
        $this->addSql('DROP TABLE maestro_contacto');
    }
}
