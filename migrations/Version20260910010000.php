<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca las identificaciones que se crearon COPIANDO un escaneo.
 *
 * 🔥 Sin esto se validaban contra sí mismas: el panel de sueltos escribe número y nombre desde la
 * lectura, y en la siguiente tanda el cotejo veía un manifiesto que coincide al 100 % —porque
 * salió de ahí— y lo sellaba en verde. Un sello puesto por el propio dato que había que comprobar.
 *
 * Las 265 filas existentes se dan por tecleadas por una persona, que es lo que son: el panel de
 * sueltos no ha creado ninguna todavía.
 */
final class Version20260910010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'copiada_del_escaneo en cotizacion_pasajero_identificacion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion ADD copiada_del_escaneo TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion DROP copiada_del_escaneo');
    }
}
