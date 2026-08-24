<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `alumno` → `participante`.
 *
 * El modo grupo sirve igual para la promoción de un colegio que para un incentivo de empresa, y en
 * el segundo nadie es alumno de nada. El importador **sigue aceptando «Alumno»** en el padrón,
 * porque es lo que escribe un colegio: el nombre interno no tiene por qué imponerle vocabulario a
 * quien rellena la hoja.
 *
 * En producción no hay ninguna fila —la columna se despliega en esta misma tanda—, así que el
 * `UPDATE` sólo alcanza a los entornos donde ya se probó.
 */
final class Version20260824220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tipo de pasajero: alumno pasa a participante';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_pasajero SET tipo = 'participante' WHERE tipo = 'alumno'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_pasajero SET tipo = 'alumno' WHERE tipo = 'participante'");
    }
}
