<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `imputacion_fijada` → `fijado_por_operador`.
 *
 * ⚠️ El nombre se quedó corto a las tres horas de nacer. La bandera ya no congela sólo la
 * IMPUTACIÓN: también los IMPORTES, porque Beds24 pone a cero el alojamiento de lo cancelado la
 * mitad de las veces (18 de 35 medidos el 08/09/2026, contra 3 de 49 en las confirmadas). Mover un
 * cargo de 239.40 y volver media hora después podía dejarlo en la casita de antes **y** en 0.00.
 *
 * Un nombre que describe la mitad de lo que hace es peor que uno genérico: el siguiente que lo lea
 * dará por hecho que los importes se siguen sincronizando. Se renombra ahora, con cero filas a
 * `true`, que es cuando sale gratis.
 */
final class Version20260908084500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'El candado del cargo pasa a llamarse fijado_por_operador: congela imputación E importes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero CHANGE imputacion_fijada fijado_por_operador TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero CHANGE fijado_por_operador imputacion_fijada TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
