<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El importe de la Orden de Servicio deja de ser obligatorio en la cabecera.
 *
 * `total_os` y `moneda_os` eran NOT NULL, y de ahí colgaba la regla de que una orden no podía
 * mezclar monedas: «deja un total que no suma». El problema no era mezclar — era que el
 * importe estaba en el sitio equivocado. Un proveedor cobra unos servicios en soles y otros en
 * dólares, y partir eso en dos órdenes parte una gestión que en la vida real es una sola.
 *
 * El dinero vive ya en cada ítem: `costo_cotizado` + `moneda_cotizada` (lo que dijo el
 * cotizador) y `costo_real_operativo` + `moneda_real` (lo negociado). La cabecera se queda
 * como apunte manual de conciliación, y por eso pasa a admitir nulos: un `0.00` obligatorio se
 * lee como un importe, y era mentira.
 *
 * ⚠️ NO se borran las columnas ni se tocan los datos. Las 3 órdenes que hay conservan su total
 * tal cual; lo único que cambia es que las nuevas pueden nacer sin él.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_orden_servicio: total_os y moneda_os pasan a ser opcionales.';
    }

    public function up(Schema $schema): void
    {
        // La FK de moneda_os hay que soltarla para poder cambiar la columna, y volver a ponerla.
        $fk = $this->connection->fetchOne(<<<'SQL'
            SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'operacion_orden_servicio'
              AND COLUMN_NAME = 'moneda_os'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        SQL);

        if ($fk) {
            $this->addSql(sprintf(
                'ALTER TABLE operacion_orden_servicio DROP FOREIGN KEY %s',
                $fk
            ));
        }

        $this->addSql('ALTER TABLE operacion_orden_servicio CHANGE total_os total_os NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio CHANGE moneda_os moneda_os VARCHAR(3) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE operacion_orden_servicio
            ADD CONSTRAINT FK_ORDEN_SERVICIO_MONEDA_OS
            FOREIGN KEY (moneda_os) REFERENCES maestro_moneda (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Volver atrás exige que no haya nulos: se rellenan con lo que ya suman sus ítems.
        $this->addSql(<<<'SQL'
            UPDATE operacion_orden_servicio o
            SET total_os = COALESCE(total_os, (
                SELECT COALESCE(SUM(s.costo_cotizado), 0)
                FROM operacion_servicio s WHERE s.orden_servicio_id = o.id
            ))
            WHERE total_os IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE operacion_orden_servicio o
            SET moneda_os = COALESCE(moneda_os, (
                SELECT s.moneda_cotizada FROM operacion_servicio s
                WHERE s.orden_servicio_id = o.id AND s.moneda_cotizada IS NOT NULL LIMIT 1
            ), 'PEN')
            WHERE moneda_os IS NULL
        SQL);

        $this->addSql('ALTER TABLE operacion_orden_servicio DROP FOREIGN KEY FK_ORDEN_SERVICIO_MONEDA_OS');
        $this->addSql('ALTER TABLE operacion_orden_servicio CHANGE total_os total_os NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio CHANGE moneda_os moneda_os VARCHAR(3) NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE operacion_orden_servicio
            ADD CONSTRAINT FK_ORDEN_SERVICIO_MONEDA_OS
            FOREIGN KEY (moneda_os) REFERENCES maestro_moneda (id)
        SQL);
    }
}
