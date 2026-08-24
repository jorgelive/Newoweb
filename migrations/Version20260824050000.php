<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_file_grupo.detalle`: el itinerario largo de un grupo.
 *
 * Aparte de `nombre` porque son dos lecturas distintas: el corto sirve para ELEGIR entre veinte
 * códigos de un vistazo, el largo para COMPROBAR un horario cuando ya sabes cuál miras. Ver
 * `docs/Cotizaciones.md` §6.m.
 *
 * ⚠️ Escrita a mano: el `diff` arrastra tablas `energia_*` y un backup de `pms_evento_calendario`
 * que existen en la base local y no en las entidades.
 */
final class Version20260824050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade cotizacion_file_grupo.detalle para el itinerario largo del subgrupo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo ADD detalle LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo DROP detalle');
    }
}
