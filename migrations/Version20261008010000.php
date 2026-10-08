<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca de cada catálogo en la web pública: OpenPeru Travel (por defecto) u Open World Travel,
 * la división Caribe. Ver `CatalogoMarcaEnum` y docs/WebPublica.md §3.
 *
 * «Viajes de Promoción» (TWWSCJ, Punta Cana) nace ya como Open World: es la razón de ser de la
 * división, y dejarlo en OpenPeru obligaría a acordarse de cambiarlo a mano tras desplegar. En
 * una base sin ese catálogo (desarrollo) el UPDATE no toca nada.
 */
final class Version20261008010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_catalogo.marca_web (openperu | open_world); TWWSCJ como open_world';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_catalogo ADD marca_web VARCHAR(20) DEFAULT 'openperu' NOT NULL");
        $this->addSql("UPDATE cotizacion_catalogo SET marca_web = 'open_world' WHERE localizador = 'TWWSCJ'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_catalogo DROP marca_web');
    }
}
