<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El género del pasajero pasa a nulable.
 *
 * La columna era `NOT NULL` porque la entidad se diseñó para cotizaciones de dos personas, donde se
 * teclea todo. Un padrón real llega con huecos: el de Punta Cana trae **131 géneros de 133**, y
 * bloquear la carga de 133 personas por dos celdas vacías es desproporcionado.
 *
 * El tipo en PHP ya era `?SexoEnum`, así que el código lo toleraba desde siempre: sólo la columna
 * decía lo contrario.
 */
final class Version20260824180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_file_pasajero.sexo a nulable (los padrones llegan con huecos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero CHANGE sexo sexo VARCHAR(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_pasajero SET sexo = 'M' WHERE sexo IS NULL");
        $this->addSql('ALTER TABLE cotizacion_file_pasajero CHANGE sexo sexo VARCHAR(1) NOT NULL');
    }
}
