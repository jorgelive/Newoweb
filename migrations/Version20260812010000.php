<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La limpieza también se puede cobrar a porcentaje.
 *
 * Algunas casitas van a pasar de un importe fijo a un porcentaje del alojamiento, y no todas a
 * la vez. Con dos columnas conviven las dos formas sin migrar el parque de golpe: si hay
 * `porcentaje_limpieza`, manda; si no, el `precio_limpieza` de siempre.
 *
 * ⚠️ **La base es el ALOJAMIENTO SOLO**, sin el suplemento por persona — al contrario que
 * `porcentaje_servicio`, que sí lo incluye. La diferencia es deliberada: se eligió que la
 * limpieza se pueda explicar como «el 15% de la tarifa» y no como un número que sube con cada
 * acompañante. Las dos reglas viven cada una en su método (`PmsUnidad::costoLimpieza()` y
 * `PmsUnidad::servicioSobre()`) y no se derivan una de otra.
 *
 * Nace en `0.00` en todas: nadie ha decidido todavía qué casitas pasan a porcentaje, y con los
 * dos campos en cero la limpieza no se cobra **ni aparece en la cotización** —ni como línea de
 * cero, que sólo invita a preguntar por algo que no existe—. Como hoy todas tienen los 15.00
 * fijos sembrados, esto no cambia ningún precio: sólo abre la puerta.
 */
final class Version20260812010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_unidad.porcentaje_limpieza: la limpieza como % del alojamiento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE pms_unidad ADD porcentaje_limpieza NUMERIC(5, 2) DEFAULT '0.00' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad DROP porcentaje_limpieza');
    }
}
