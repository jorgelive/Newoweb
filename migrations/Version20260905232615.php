<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traza de cada intento de cobro contra una pasarela (`FinPasarelaCobroAudit`).
 *
 * ⚠️ **Escrita a mano a partir del diff, no copiada.** `doctrine:migrations:diff` propuso
 * además DROP de `energia_dispositivo`, `energia_lectura`, `energia_suscripcion` y
 * `pms_evento_calendario_backup_20260808`: tablas que quedaron vivas cuando `Energia` pasó a
 * llamarse `Domotica` y que **siguen existiendo en producción con sus datos**. Retirarlas es
 * una decisión propia, con su propia migración y habiendo mirado antes qué hay dentro — no un
 * efecto colateral de crear una tabla de auditoría.
 */
final class Version20260905232615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea fin_pasarela_cobro_audit: un intento de cobro por fila, con lo que contestó la pasarela.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE fin_pasarela_cobro_audit (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                pasarela VARCHAR(30) NOT NULL,
                intentado_en DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                enlace_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                con_3ds TINYINT(1) NOT NULL,
                desenlace VARCHAR(20) NOT NULL,
                objeto VARCHAR(40) DEFAULT NULL,
                action_code VARCHAR(40) DEFAULT NULL,
                outcome_type VARCHAR(40) DEFAULT NULL,
                outcome_code VARCHAR(40) DEFAULT NULL,
                cargo_id VARCHAR(60) DEFAULT NULL,
                motivo LONGTEXT DEFAULT NULL,
                respuesta JSON DEFAULT NULL,
                INDEX idx_fin_cobro_audit_intentado (intentado_en),
                INDEX idx_fin_cobro_audit_enlace (enlace_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fin_pasarela_cobro_audit');
    }
}
