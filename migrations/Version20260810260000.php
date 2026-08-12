<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La Biblia admite servicios sin tarifa (filas de referencia).
 *
 * Un hotel que el pasajero reserva por su cuenta no se le compra a nadie, así que no
 * lleva tarifa —y por tanto tampoco moneda—, pero el transportista necesita saber dónde
 * recogerlo y el guía a qué hora deja de tener al grupo. Con estas tres columnas en NOT
 * NULL esos componentes no podían ni existir en el cuadro de tráfico.
 *
 * Ver OperacionServicio::isSoloReferencia() y docs/Operacion.md §3.3.
 */
final class Version20260810260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: tarifa y monedas pasan a nullable para admitir servicios de referencia (no incluidos / sin tarifa).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio CHANGE cotizacion_tarifa_id cotizacion_tarifa_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', CHANGE moneda_cotizada moneda_cotizada VARCHAR(3) DEFAULT NULL, CHANGE moneda_real moneda_real VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Volver a NOT NULL exige vaciar antes las filas de referencia: son justamente
        // las que tienen estas columnas en NULL y el ALTER fallaría con ellas presentes.
        $this->addSql('DELETE FROM operacion_servicio WHERE cotizacion_tarifa_id IS NULL OR moneda_cotizada IS NULL OR moneda_real IS NULL');
        $this->addSql('ALTER TABLE operacion_servicio CHANGE cotizacion_tarifa_id cotizacion_tarifa_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE moneda_cotizada moneda_cotizada VARCHAR(3) NOT NULL, CHANGE moneda_real moneda_real VARCHAR(3) NOT NULL');
    }
}
