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
 * ⚠️ `acceso_identificado` es un flag APARTE de `totales_ocultos`: aquél es presentación de precio,
 * éste es acceso. Si fueran el mismo, ocultar el total de grupo implicaría pedir el documento.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tipo y observaciones del pasajero, acceso identificado, y se quita es_jefe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero ADD tipo VARCHAR(20) DEFAULT NULL, ADD observaciones LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD acceso_identificado TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo DROP es_jefe');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo ADD es_jefe TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP acceso_identificado');
        $this->addSql('ALTER TABLE cotizacion_file_pasajero DROP tipo, DROP observaciones');
    }
}
