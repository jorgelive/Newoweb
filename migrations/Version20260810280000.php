<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prestador: quién presta el servicio, frente al proveedor (a quién se le compra).
 *
 * Opcional y blando en los tres niveles: todo nace en NULL y la cascada
 * componente → día → proveedor de la tarifa hace que las cotizaciones existentes se
 * comporten exactamente igual que antes. Ver docs/Cotizaciones.md y docs/Operacion.md.
 *
 * Las dos columnas JSON se añaden en tres pasos —nullable, backfill a '[]', NOT NULL—
 * porque MySQL no tiene default implícito para JSON: un ADD COLUMN JSON NOT NULL sobre
 * una tabla con filas revienta en modo estricto.
 */
final class Version20260810280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prestador opcional en componente y día de la cotización, y su snapshot en La Biblia.';
    }

    public function up(Schema $schema): void
    {
        // ── Componente: el hecho completo, con cara pública y cara operativa ──
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            ADD prestador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_nombre_snapshot VARCHAR(150) DEFAULT NULL,
            ADD prestador_titulo_snapshot JSON DEFAULT NULL,
            ADD prestador_url_snapshot VARCHAR(255) DEFAULT NULL,
            ADD prestador_imagenes_snapshot JSON DEFAULT NULL,
            ADD prestador_telefono_snapshot VARCHAR(50) DEFAULT NULL,
            ADD prestador_direccion_snapshot VARCHAR(255) DEFAULT NULL');

        $this->addSql("UPDATE cotizacion_cotcomponente SET prestador_titulo_snapshot = '[]' WHERE prestador_titulo_snapshot IS NULL");
        $this->addSql("UPDATE cotizacion_cotcomponente SET prestador_imagenes_snapshot = '[]' WHERE prestador_imagenes_snapshot IS NULL");

        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            MODIFY prestador_titulo_snapshot JSON NOT NULL,
            MODIFY prestador_imagenes_snapshot JSON NOT NULL');

        // ── Día: sólo la intención (default heredable + filtro de tarifas) ──
        $this->addSql('ALTER TABLE cotizacion_cotservicio
            ADD prestador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_nombre_snapshot VARCHAR(150) DEFAULT NULL');

        // ── La Biblia: el prestador ya resuelto, con los datos del recojo ──
        $this->addSql('ALTER TABLE operacion_servicio
            ADD prestador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_nombre VARCHAR(150) DEFAULT NULL,
            ADD prestador_telefono VARCHAR(50) DEFAULT NULL,
            ADD prestador_direccion VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            DROP prestador_maestro_id,
            DROP prestador_nombre_snapshot,
            DROP prestador_titulo_snapshot,
            DROP prestador_url_snapshot,
            DROP prestador_imagenes_snapshot,
            DROP prestador_telefono_snapshot,
            DROP prestador_direccion_snapshot');

        $this->addSql('ALTER TABLE cotizacion_cotservicio
            DROP prestador_maestro_id,
            DROP prestador_nombre_snapshot');

        $this->addSql('ALTER TABLE operacion_servicio
            DROP prestador_maestro_id,
            DROP prestador_nombre,
            DROP prestador_telefono,
            DROP prestador_direccion');
    }
}
