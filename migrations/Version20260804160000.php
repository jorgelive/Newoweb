<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_cargo_financiero.es_automatico`: marca el espejo contable de los canales
 * que cobran al huésped por nosotros (Airbnb, VRBO — `PmsChannel::CANAL_PAGO_TOTAL`).
 *
 * POR QUÉ. En esos canales el importe que guardamos es lo que la OTA nos remite,
 * no lo que el huésped pagó (que lleva encima la comisión de servicio de la OTA).
 * Enseñárselo en su estado de cuenta invita a una conversación incómoda sobre por
 * qué las cifras no cuadran. Con este flag se excluyen del resumen sin adivinar.
 *
 * Es la contraparte del `es_automatico` que ya existía en `pms_pago_financiero`
 * (el depósito que crea PmsPagoOtaAutomaticoService). Con los dos marcados, un
 * huésped de Airbnb sin cargos extra ve la barra al 100 % y ninguna cifra; con
 * cargos extra ve SOLO esos, que son los que nos debe a nosotros.
 *
 * BACKFILL. Se marcan los cargos que vinieron del canal (`beds24_item_id` no nulo:
 * NULL = cargo manual del operador) y cuya reserva está en un canal de pago total.
 * Los cargos manuales quedan en false, que es lo que hace que un extra tecleado a
 * mano siga siendo visible para el huésped.
 *
 * ⚠️ `es_automatico` NO significa "lo generó el sistema": PmsCargosAutomaticosService
 * también genera cargos solos —los de reservas DIRECTAS, desde el tarifario— y esos
 * se quedan en false a propósito, porque el huésped nos los paga a nosotros y tiene
 * que verlos. Ver el docblock del campo en PmsCargoFinanciero.
 */
final class Version20260804160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anade pms_cargo_financiero.es_automatico y marca los cargos espejo de Airbnb/VRBO.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_cargo_financiero
            ADD es_automatico TINYINT(1) DEFAULT 0 NOT NULL
            SQL);

        // El canal vive en la reserva, y el cargo llega a ella por la cabecera
        // financiera. Se marcan solo los cargos de ORIGEN CANAL (beds24_item_id
        // no nulo) para no tocar los extras que el operador tecleó a mano.
        $this->addSql(<<<'SQL'
            UPDATE pms_cargo_financiero c
            INNER JOIN pms_informacion_financiera i ON i.id = c.informacion_id
            INNER JOIN pms_reserva r               ON r.id = i.reserva_id
            SET c.es_automatico = 1
            WHERE c.beds24_item_id IS NOT NULL
              AND r.channel_id IN ('airbnb', 'vrbo')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP es_automatico');
    }
}
