<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La pista del tema «reservar» menciona las mascotas.
 *
 * La pista es lo único de la categoría que el modelo lee para elegir tema, y «capacidad, mínimo de
 * noches, cómo reservar» no lleva a nadie a buscar ahí la política de mascotas. Con la ficha
 * creada y sin esta línea, el conocimiento la tendría y no la encontraría.
 *
 * Texto plano, sin listeners: por migración (CLAUDE.md §Qué entra por migración y qué por comando).
 */
final class Version20260813230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'La pista del tema «reservar» menciona mascotas, para que el modelo llegue a la ficha.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE agent_conocimiento_categoria SET pista = ? WHERE id = 'reservar'",
            ['capacidad, mínimo de noches, mascotas, cómo reservar']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE agent_conocimiento_categoria SET pista = ? WHERE id = 'reservar'",
            ['capacidad, mínimo de noches, cómo reservar']
        );
    }
}
