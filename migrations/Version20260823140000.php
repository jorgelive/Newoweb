<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El linaje entre una cotización viva y sus fotos congeladas.
 *
 * Hasta ahora dos filas del mismo expediente sólo compartían `file_id`: no había forma de saber
 * que una salió de la otra, ni de reconciliarlas.
 *
 * ⚠️ `SET NULL` y no `CASCADE`: borrar la cotización viva **no** puede llevarse su histórico. Es
 * el rastro de lo que ya se le vendió a alguien.
 *
 * ⚠️ Y `(file_id, version)` sigue SIN índice único, ahora por diseño: un histórico conserva a
 * propósito el número de la cotización de la que salió, porque las versiones son propuestas y una
 * foto del pasado no es una propuesta más.
 */
final class Version20260823140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotización: vínculo `derivada_de_id` entre la viva y sus históricos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD derivada_de_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD CONSTRAINT fk_cotizacion_derivada_de FOREIGN KEY (derivada_de_id) REFERENCES cotizacion_cotizacion (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_cotizacion_derivada_de ON cotizacion_cotizacion (derivada_de_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP FOREIGN KEY fk_cotizacion_derivada_de');
        $this->addSql('DROP INDEX idx_cotizacion_derivada_de ON cotizacion_cotizacion');
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP derivada_de_id');
    }
}
