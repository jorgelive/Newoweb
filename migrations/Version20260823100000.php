<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El componente que no viene del catálogo: su marca y su nombre interno.
 *
 * `es_manual` no es lo mismo que «no tiene maestro»: un componente recién creado tampoco lo
 * tiene, pero está esperando que le elijan uno. Esta columna dice que **ya se decidió que no lo
 * tendrá**, y de ahí cuelga que el editor esconda el buscador de insumos.
 *
 * `nombre_interno_snapshot` cubre el hueco que dejaba eso: el componente sólo tenía el título
 * público, y su nombre interno salía siempre del maestro. Sin maestro no había ninguno, así que
 * La Biblia rotulaba la fila con el título del cliente o con «Servicio sin nombre».
 */
final class Version20260823100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componente manual: marca `es_manual` y nombre interno propio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD es_manual TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD nombre_interno_snapshot VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP es_manual');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP nombre_interno_snapshot');
    }
}
