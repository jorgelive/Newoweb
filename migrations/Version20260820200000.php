<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera el teléfono y la dirección congelados del prestador.
 *
 * Eran una copia por fila que **nunca se llenó**: 0 de 42 en producción. El snapshot los ponía
 * a `null` y la pantalla no tenía dónde escribirlos, así que sólo eran una segunda fuente de
 * verdad esperando a discrepar del catálogo.
 *
 * Y eran **asimétricos**, que es lo que los delató: el comprador —a quien de verdad se le manda
 * la Orden de Servicio— nunca los tuvo, porque su contacto siempre se resolvió vivo desde
 * `TravelOrganizacion` por su id. Dos mecanismos distintos para el mismo dato.
 *
 * Ahora los dos papeles hacen lo mismo: se resuelve contra el catálogo por el id **efectivo**.
 * Corregir un teléfono en la ficha lo corrige en todas las filas, que es exactamente lo que una
 * copia por fila impedía.
 *
 * ⚠️ El `down()` devuelve las columnas **vacías**. No hay nada que restaurar porque nunca hubo
 * nada dentro; si algún día vuelven a hacer falta, el dato está en el catálogo.
 */
final class Version20260820200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OperacionServicio: fuera teléfono y dirección congelados — el contacto se resuelve del catálogo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP prestador_telefono, DROP prestador_direccion');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio
            ADD prestador_telefono VARCHAR(50) DEFAULT NULL,
            ADD prestador_direccion VARCHAR(255) DEFAULT NULL');
    }
}
