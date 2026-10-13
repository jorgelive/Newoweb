<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quita la negativa de transporte de la ficha «Cómo llegar desde el aeropuerto (público)».
 *
 * Terminaba en «No ofrecemos traslado propio.», y el 11/10/2026 el agente la generalizó a un
 * prospecto que preguntaba por transporte a Machu Picchu: «No organizamos transporte directo». La
 * agencia sí lo vende, y el 12/10/2026 creó en util el catálogo «Traslados» (aeropuerto ↔ centro
 * de Cusco). La frase pasa a ser la oferta, en positivo y con el puntero a
 * `consultar_tours`: lo que se le quita a un texto del agente, el agente lo niega (CLAUDE.md, «El
 * agente»), y una negativa que convive con el producto en el catálogo deja al modelo eligiendo.
 *
 * Por SQL porque `AgentConocimiento::$contenido` lo lee sólo el agente: no lleva
 * `#[AutoTranslate]` ni listeners. El `REPLACE` exige la frase exacta, así que repetirla no hace
 * nada, y una ficha ya reescrita a mano se queda como esté.
 */
final class Version20261011120000 extends AbstractMigration
{
    private const string ANTES = 'No ofrecemos traslado propio.';
    private const string AHORA = 'Si prefiere que le recojan, nuestra agencia (OpenPeru Travel) vende el traslado desde el aeropuerto: consúltalo con consultar_tours.';
    private const string FICHA = '019ffc40ea2f758d987f31d08c1df4ac';

    public function getDescription(): string
    {
        return 'agent_conocimiento: «No ofrecemos traslado propio» pasa a ofrecer el traslado de la agencia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE agent_conocimiento SET contenido = REPLACE(contenido, ?, ?) WHERE id = UNHEX(?)',
            [self::ANTES, self::AHORA, self::FICHA]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE agent_conocimiento SET contenido = REPLACE(contenido, ?, ?) WHERE id = UNHEX(?)',
            [self::AHORA, self::ANTES, self::FICHA]
        );
    }
}
