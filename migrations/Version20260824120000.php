<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rol del pasajero, sus observaciones, el acceso identificado — y fuera `es_jefe`.
 *
 * ⚠️ **`es_jefe` se cae el mismo día que nació.** El padrón nuevo lo desmintió: hay 9 coordinadores
 * para 9 grupos, uno cada uno, así que quién lidera ya lo dice el ROL. Una bandera que no cambia
 * nada y que además podía abrir una puerta de acceso es peor que no tenerla. Cero filas en
 * producción, así que no se pierde nada.
 *
 * ⚠️ El acceso identificado NO va como flag en la cotización sino como **modo del expediente**.
 * Iba a haber una bandera por consecuencia —ocultar totales, pedir documento, habilitar padrón— y
 * eran la misma decisión escrita tres veces, con combinaciones que no significan nada. El modo es
 * propiedad del NEGOCIO, no de la propuesta: la v2 de un viaje de colegio sigue siendo un viaje de
 * colegio.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tipo y observaciones del pasajero, modo del expediente, y se quita es_jefe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero ADD tipo VARCHAR(20) DEFAULT NULL, ADD observaciones LONGTEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE cotizacion_file ADD modo VARCHAR(20) DEFAULT 'estandar' NOT NULL");
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo DROP es_jefe');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo ADD es_jefe TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_file DROP modo');
        $this->addSql('ALTER TABLE cotizacion_file_pasajero DROP tipo, DROP observaciones');
    }
}
