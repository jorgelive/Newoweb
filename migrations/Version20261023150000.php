<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El sedán de la recepción en Puno, a S/25 como el de Cusco (23/10/2026).
 *
 * El Auto de «Transporte Term Puno - Punto de Inicio» estaba en USD 5 (unos S/19), por debajo del
 * urbano de Puno (S/25). Jorge: «la tarifa sedán es 25 soles», e «igualalo». Se cambia monto y
 * moneda; el título no, así que no hace falta pasar por el traductor. Ningún listener escucha
 * `TravelTarifa`, y ninguna cotización conserva el precio del catálogo: copia el suyo al cotizar.
 */
final class Version20261023150000 extends AbstractMigration
{
    private const string COMPONENTE = 'Transporte Term Puno - Punto de Inicio';

    public function getDescription(): string
    {
        return 'Auto de la recepción en el terminal de Puno: USD 5 → S/25';
    }

    public function up(Schema $schema): void
    {
        $this->cambiar('25.00', 'PEN', '5.00', 'USD');
    }

    public function down(Schema $schema): void
    {
        $this->cambiar('5.00', 'USD', '25.00', 'PEN');
    }

    private function cambiar(string $monto, string $moneda, string $montoAntes, string $monedaAntes): void
    {
        $this->addSql(
            'UPDATE travel_tarifa t JOIN travel_componente c ON c.id = t.componente_id
                SET t.monto = ?, t.moneda_id = ?
              WHERE c.nombre_interno = ? AND t.nombre_interno = ? AND t.monto = ? AND t.moneda_id = ?',
            [$monto, $moneda, self::COMPONENTE, 'Auto', $montoAntes, $monedaAntes]
        );
    }
}
