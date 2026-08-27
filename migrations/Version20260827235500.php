<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quita el `DEFAULT (json_array())` que `Version20260827220000` le dejó a
 * `cotizacion_segmento.nombre_interno_snapshot`.
 *
 * Se lo puse porque `ADD ... JSON NOT NULL` no puede aterrizar sobre una tabla con filas sin algo
 * que poner en ellas. Cumplida esa función, el default sobra — y estorba: **el mapping no lo
 * declara**, así que cada `migrations:diff` futuro generaría un `CHANGE` espurio, del tipo que
 * acaba colándose dentro de la migración de otro. Ya pasó esta semana con las tablas `energia_*`.
 *
 * El precedente del propio proyecto es `CotizacionCotcomponente::$lugaresManuales`: columna `json`
 * sin default ni en la base ni en la entidad, y con el motivo escrito al lado.
 *
 * Las 225 filas ya están rellenas y Doctrine escribe siempre todas las columnas mapeadas en el
 * INSERT, así que nadie depende de este default.
 */
final class Version20260827235500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira el DEFAULT de cotizacion_segmento.nombre_interno_snapshot para que el mapping y la BD coincidan.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_segmento ALTER COLUMN nombre_interno_snapshot DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_segmento ALTER COLUMN nombre_interno_snapshot SET DEFAULT (JSON_ARRAY())');
    }
}
