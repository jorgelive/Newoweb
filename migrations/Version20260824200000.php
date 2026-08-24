<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El teléfono del pasajero.
 *
 * `cotizacion_file` ya tenía uno, pero es el del contacto principal. En un grupo de 133 personas hay
 * 133 familias a las que llamar cuando falta un pasaporte, y el padrón real los trae todos: sin
 * columna acababan apilados en «observaciones», que es donde va a morir un dato.
 */
final class Version20260824200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Teléfono por pasajero (el del expediente es sólo el del contacto principal)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero ADD telefono VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero DROP telefono');
    }
}
