<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un boarding pass es de un VUELO, no de una reserva.
 *
 * `CotizacionFilearchivo` podía colgar de un pasajero y de un subgrupo, y con eso no bastaba: la
 * clave de un subgrupo de reserva aérea es el **PNR**, y un PNR cubre ida y vuelta. `DM6771` y
 * `DM6770` caían en el mismo, así que se volvía a no saber cuál era cuál — y quien vuela
 * Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta tiene ocho boarding passes.
 *
 * Los vuelos ya existían como dato —número, fecha, aerolínea, ruta— y ya colgaban de los
 * subgrupos. Sólo faltaba que el archivo pudiera apuntar a uno.
 *
 * ⚠️ Nulable: la inmensa mayoría de los adjuntos no son de ningún vuelo.
 */
final class Version20260908000807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'El adjunto puede apuntar a un vuelo: su boarding pass de ese vuelo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_file_archivo ADD vuelo_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE cotizacion_file_archivo ADD CONSTRAINT FK_D33AFC674FF34720 FOREIGN KEY (vuelo_id) REFERENCES cotizacion_vuelo (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_D33AFC674FF34720 ON cotizacion_file_archivo (vuelo_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP FOREIGN KEY FK_D33AFC674FF34720');
        $this->addSql('DROP INDEX IDX_D33AFC674FF34720 ON cotizacion_file_archivo');
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP vuelo_id');
    }
}
