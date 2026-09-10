<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cuánto se ha girado un escaneo respecto de su original.
 *
 * Sirve **sólo** para poder regirar desde la copia intacta y acotar la pérdida de calidad a una
 * generación. No es una orientación que nadie aplique al mostrar: los píxeles del fichero vivo ya
 * están derechos. Ver `GiradorDeEscaneo`.
 */
final class Version20260909230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rotacion_aplicada en cotizacion_file_archivo, para regirar desde el original.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo ADD rotacion_aplicada SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP rotacion_aplicada');
    }
}
