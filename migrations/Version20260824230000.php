<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `padre` → `acompanante`.
 *
 * Segunda mitad del mismo ajuste que llevó `alumno` a `participante`: los roles describen el PAPEL
 * y no el contexto, porque el modo grupo sirve igual para la promoción de un colegio que para un
 * incentivo de empresa. Allí nadie es padre de familia de nadie.
 *
 * En producción no hay ninguna fila: la columna `tipo` se despliega en esta misma tanda.
 */
final class Version20260824230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tipo de pasajero: padre pasa a acompanante';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_pasajero SET tipo = 'acompanante' WHERE tipo = 'padre'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_pasajero SET tipo = 'padre' WHERE tipo = 'acompanante'");
    }
}
