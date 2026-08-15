<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lugares del proveedor: hasta dónde llega su cobertura.
 *
 * Segundo enganche del mismo vocabulario `travel_lugar`, y significa algo distinto que el
 * del componente. En el componente la etiqueta dice **dónde ocurre o desde dónde se
 * despacha** ese servicio concreto; aquí dice **hasta dónde opera** el proveedor. Por eso
 * un operador de Lima que también despacha Ica lleva las dos, y por eso son dos tablas y
 * no una: la misma fila de `travel_lugar` responde dos preguntas diferentes según de qué
 * cuelgue.
 *
 * ⚠️ Escrita a mano, como la de los lugares: el `diff` sobre este proyecto arrastra medio
 * esquema de Travel porque esas tablas nacieron con `schema:update`. De su salida se
 * copiaron sólo estas tres sentencias, con los nombres de índice y clave ajena que genera
 * Doctrine — cambiarlos dejaría `doctrine:schema:validate` en rojo.
 *
 * Nace vacía: el etiquetado es manual.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Travel: pool N:N entre proveedores y lugares (cobertura del proveedor).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE travel_proveedor_lugar_pool (proveedor_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', lugar_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', INDEX IDX_F6713B80CB305D73 (proveedor_id), INDEX IDX_F6713B80B5A3803B (lugar_id), PRIMARY KEY(proveedor_id, lugar_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE travel_proveedor_lugar_pool ADD CONSTRAINT FK_F6713B80CB305D73 FOREIGN KEY (proveedor_id) REFERENCES travel_proveedor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE travel_proveedor_lugar_pool ADD CONSTRAINT FK_F6713B80B5A3803B FOREIGN KEY (lugar_id) REFERENCES travel_lugar (id) ON DELETE CASCADE');
    }

    /** ⚠️ Revertir destruye el etiquetado de cobertura; el pool es su única copia. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_proveedor_lugar_pool DROP FOREIGN KEY FK_F6713B80CB305D73');
        $this->addSql('ALTER TABLE travel_proveedor_lugar_pool DROP FOREIGN KEY FK_F6713B80B5A3803B');
        $this->addSql('DROP TABLE travel_proveedor_lugar_pool');
    }
}
