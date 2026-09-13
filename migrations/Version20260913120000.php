<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `telefono_atencion` → `telefono_emergencia`: dos campos no pueden llamarse igual.
 *
 * ── El lío ──────────────────────────────────────────────────────────────────
 * El 11/09/2026 `telefono_principal` pasó a ser «el WhatsApp por el que se atiende al público»
 * —el de la API de Meta, que contesta el sistema—. Con eso, el panel quedó con **dos campos que
 * decían atención**: «WhatsApp de atención al público» y «Teléfono de atención». Los números
 * siempre fueron distintos; lo que no se distinguía era para qué servía cada uno.
 *
 * ── Qué son de verdad ───────────────────────────────────────────────────────
 * | Campo | Quién contesta | Cuándo se usa |
 * |---|---|---|
 * | `telefono_principal` | el sistema (WhatsApp Business API) | **siempre**: es el que se publica |
 * | `telefono_emergencia` | una persona | sólo urgencias: alguien en la puerta sin poder entrar |
 *
 * El nombre importa porque es lo único que impide que alguien rellene el segundo con el mismo
 * número del primero «porque también atiende» — y entonces una urgencia a las 2 de la mañana
 * acabaría en una cola que contesta un bot.
 *
 * ── Sólo cambia el nombre ───────────────────────────────────────────────────
 * `CHANGE` conserva el valor: la fila sigue con su +51 961 281 953. No hay datos que mover ni
 * nada que rellenar.
 */
final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renombra pms_establecimiento.telefono_atencion a telefono_emergencia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento CHANGE telefono_atencion telefono_emergencia VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento CHANGE telefono_emergencia telefono_atencion VARCHAR(30) DEFAULT NULL');
    }
}
