<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El bus compartido de la Ruta del Sol decía «Transporte» dos veces (23/10/2026).
 *
 * «Pool Ruta del Sol» ya traía el ítem genérico «Transporte», y `app:travel:crear-ruta-del-sol` le
 * añadió «Transporte en Bus con A/C», que dice lo mismo con más detalle. El cliente leía los dos en
 * «Incluye». Se quita el genérico (Jorge). Las propuestas ya hechas guardan su foto y no cambian.
 *
 * Ningún listener lee los ítems al guardarse, así que va por SQL.
 */
final class Version20261024010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ruta del Sol: quita el ítem «Transporte», repetido con «Transporte en Bus con A/C»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE i FROM travel_componente_item i'
            . ' JOIN travel_componente c ON c.id = i.componente_id'
            . ' JOIN travel_item_diccionario d ON d.id = i.diccionario_id'
            . ' WHERE c.nombre_interno = ? AND d.nombre_interno = ?',
            ['Pool Ruta del Sol', 'Transporte'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('El ítem borrado no se reconstruye: se vuelve a añadir desde el panel si hiciera falta.');
    }
}
