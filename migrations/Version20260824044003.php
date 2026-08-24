<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_file_grupo.tipo` pasa de VARCHAR(20) a VARCHAR(40).
 *
 * Los ejes nuevos `reserva_aerea_nacional` y `reserva_aerea_internacional` miden 22 y 27
 * caracteres. Con el largo viejo el segundo no cabía, y MySQL fuera de modo estricto **trunca en
 * vez de fallar**: el eje habría entrado como `reserva_aerea_intern` y `GrupoTipoEnum::from()`
 * reventaría al leerlo, no al escribirlo.
 *
 * ⚠️ Escrita a mano. El `diff` arrastraba tablas `energia_*` y un backup de `pms_evento_calendario`
 * que existen en la base local y no en las entidades: dejarlas habría BORRADO datos en producción.
 */
final class Version20260824044003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Amplía cotizacion_file_grupo.tipo a 40 para los ejes de reserva aérea por tramo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo CHANGE tipo tipo VARCHAR(40) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo CHANGE tipo tipo VARCHAR(20) NOT NULL');
    }
}
