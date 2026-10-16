<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El recojo de Booking: a ALGUNOS clientes Genius, no a «sus clientes preferentes».
 *
 * Jorge (15/10/2026): Booking ofrece el traslado gratis en algunos casos, a clientes Genius y
 * sobre todo en estancias largas. «Clientes preferentes» era vago y podía hacer creer a cualquier
 * huésped de Booking que lo tenía. La guía publicada ya lo dice así; esto iguala lo que lee el
 * agente (CLAUDE.md: las tres superficies dicen lo mismo). Por SQL porque `agente_pasos` no se
 * traduce; el `REPLACE` exige la frase de antes.
 */
final class Version20261015140000 extends AbstractMigration
{
    private const string FICHA = 'Traslados (general)';
    private const string ANTES = "pregunta por el recojo que ofrece Booking a sus\nclientes preferentes";
    private const string AHORA = "pregunta por el recojo gratis que Booking ofrece a\nalgunos clientes Genius (sobre todo en estancias largas)";

    public function getDescription(): string
    {
        return 'pms_guia_item «Traslados (general)»: el recojo de Booking es para algunos clientes Genius';
    }

    public function up(Schema $schema): void
    {
        $this->cambiar(self::ANTES, self::AHORA);
    }

    public function down(Schema $schema): void
    {
        $this->cambiar(self::AHORA, self::ANTES);
    }

    private function cambiar(string $de, string $a): void
    {
        $this->addSql(
            'UPDATE pms_guia_item SET agente_pasos = JSON_ARRAY(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(agente_pasos, \'$[0]\')), ?, ?)) WHERE nombre_interno = ? AND JSON_LENGTH(agente_pasos) = 1',
            [$de, $a, self::FICHA]
        );
    }
}
