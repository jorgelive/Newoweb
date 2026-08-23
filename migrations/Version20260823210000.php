<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `tipodocumento` del pasajero pasa a nulable mientras se vacía.
 *
 * La API ya no lo expone ni lo acepta —lo sustituyen las identificaciones—, así que un pasajero
 * nuevo llegaría sin él y el `NOT NULL` lo rechazaría. La columna sigue existiendo un ciclo más
 * sólo para que `app:cotizacion:pasajeros-a-identificaciones` tenga de dónde copiar en producción.
 */
final class Version20260823210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_file_pasajero.tipodocumento a nulable (columna en retirada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero CHANGE tipodocumento tipodocumento VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero CHANGE tipodocumento tipodocumento VARCHAR(20) NOT NULL');
    }
}
