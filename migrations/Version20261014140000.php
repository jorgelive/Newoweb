<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_cotizacion.presentacion_precio` sustituye a `precio_oculto` y `totales_ocultos`.
 * Ver `PresentacionPrecioEnum` y docs/Cotizaciones.md, «El precio desde».
 *
 * Cada fila pasa a su opción EQUIVALENTE, así que nada cambia a la vista:
 *
 *   precio_oculto = 1                       → sin_precio
 *   precio_oculto = 0, totales_ocultos = 1  → por_persona_base   (la nota de base, como hasta hoy)
 *   precio_oculto = 0, totales_ocultos = 0  → total_grupo
 *
 * Comprobado en producción el 14/10/2026 antes de escribirla: ninguna fila con precio oculto;
 * catálogo 1 + 7, expedientes 12 + 3. La vuelta atrás reconstruye los dos booleanos (las dos
 * opciones «por persona» vuelven a ser totales ocultos: es lo que eran).
 */
final class Version20261014140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cotizacion.presentacion_precio sustituye a precio_oculto y totales_ocultos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_cotizacion ADD presentacion_precio VARCHAR(20) DEFAULT 'total_grupo' NOT NULL");
        $this->addSql("UPDATE cotizacion_cotizacion SET presentacion_precio = CASE
            WHEN precio_oculto = 1 THEN 'sin_precio'
            WHEN totales_ocultos = 1 THEN 'por_persona_base'
            ELSE 'total_grupo' END");
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP precio_oculto, DROP totales_ocultos');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD precio_oculto TINYINT(1) DEFAULT 0 NOT NULL, ADD totales_ocultos TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE cotizacion_cotizacion SET
            precio_oculto = (presentacion_precio = 'sin_precio'),
            totales_ocultos = (presentacion_precio <> 'total_grupo')");
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP presentacion_precio');
    }
}
