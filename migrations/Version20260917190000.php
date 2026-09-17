<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cada bienvenida, sólo para su OTA: en el selector del chat salían las dos.
 *
 * El 13/09/2026 la bienvenida se partió en dos —con y sin prepago— y las reglas se repuntaron, pero
 * las plantillas se quedaron como estaban:
 *
 * | plantilla | nombre | origen | lo que es |
 * |---|---|---|---|
 * | `bienvenida` | «Bienvenida (Booking y Airbnb)» | booking, airbnb | **sólo Airbnb** |
 * | `bienvenida_booking` | «Bienvenida (Booking)» | cualquiera | **sólo Booking** |
 *
 * En una reserva de Booking el selector ofrecía las dos (captura del 17/09/2026), y la de Airbnb
 * no dice nada del prepago. `bienvenida_booking` saldría además en Airbnb y en directas.
 *
 * Migración y no comando porque ni `name` ni `allowed_sources` pasan por `AutoTranslate`.
 * Idempotente: fija el valor final, no lo deduce.
 */
final class Version20260917190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bienvenidas: cada una con su nombre y su único origen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET name = 'Bienvenida (Airbnb)', allowed_sources = JSON_ARRAY('airbnb') WHERE code = 'bienvenida'");
        $this->addSql("UPDATE msg_template SET allowed_sources = JSON_ARRAY('booking') WHERE code = 'bienvenida_booking'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET name = 'Bienvenida (Booking y Airbnb)', allowed_sources = JSON_ARRAY('booking', 'airbnb') WHERE code = 'bienvenida'");
        $this->addSql("UPDATE msg_template SET allowed_sources = JSON_ARRAY() WHERE code = 'bienvenida_booking'");
    }
}
