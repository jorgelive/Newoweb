<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `operacion_servicio.tarifa_nombre`: el nombre INTERNO de la tarifa, aparte.
 *
 * `descripcion_servicio` colapsa dos nombres en uno: lo resuelve
 * `resolverDescripcion()` con respaldo —`nombreParaProveedor` → nombre interno → nombre del
 * componente—, así que en cuanto la tarifa tiene nombre para el proveedor, **el nombre interno
 * desaparece de la pantalla**.
 *
 * Y los dos hacen falta a la vez: «Del Origen Al Presente de Lima» es lo que el proveedor
 * entiende, y «Pool City Lima CT002 (Base 1-4)» es lo que se busca en el tarifario cuando él
 * pregunta por qué se le paga eso.
 *
 * ── Se rellena solo ─────────────────────────────────────────────────────────
 * Aquí sólo se crea la columna. El valor lo pone `nombre_interno_snapshot` de la tarifa, y de
 * eso se encarga la reconciliación: `operacion:resincronizar` lo propone como cualquier otro
 * campo gobernado por la cotización, con su plan y su aprobación. Rellenarlo por SQL aquí
 * saltaría ese control y además duplicaría la lógica de `calcularValores()`.
 *
 * Mientras no se resincronice, queda nulo y la pantalla enseña sólo el nombre del proveedor,
 * que es lo que enseñaba antes.
 */
final class Version20260817140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: añade tarifa_nombre (nombre interno de la tarifa).';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'operacion_servicio'
              AND COLUMN_NAME = 'tarifa_nombre'
        SQL);

        if (!$existe) {
            $this->addSql('ALTER TABLE operacion_servicio ADD tarifa_nombre VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP tarifa_nombre');
    }
}
