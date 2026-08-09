<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Descripción del cargo redactada PARA EL HUÉSPED, traducible (`I18nContent[]`).
 *
 * `descripcion` la rellena Beds24 con lo que venga del canal y no es presentable, así que
 * un cargo de tipo «Otros» le llegaba al huésped como una cifra suelta sin explicación.
 *
 * Nullable a propósito: la columna se añade sobre una tabla con historial y la inmensa
 * mayoría de los cargos no necesitan explicación —se entienden por su tipo—. Un
 * `NOT NULL DEFAULT '[]'` obligaría a reescribir todas las filas para nada.
 */
final class Version20260809221241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_cargo_financiero.descripcion_cliente (texto traducible para el huésped).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD descripcion_cliente JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP descripcion_cliente');
    }
}
