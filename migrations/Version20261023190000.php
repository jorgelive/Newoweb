<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Códigos de la conexión Cusco ↔ Puno que se distingan al buscarlos (Jorge, 23/10/2026).
 *
 * `TRANS-CUZ-PUNO` no decía que es un bus y se confundía con un traslado urbano; la ciudad iba unas
 * veces como `PUNO` y otras como `PUN`. Los buses siguen el patrón que ya tenía el catálogo para el
 * medio de transporte —`TRANS_DIRECT_…`, `TRANS_BIM_…`, `TRANS_BYCAR_…`— y la recepción lleva
 * `RECEP_TRANS`, con origen y destino como los demás traslados:
 *
 *   TRANS-CUZ-PUNO      →  TRANS_BUS-CUZ-PUN              «Bus nocturno Cusco → Puno»
 *   TRANS-PUNO-CUZ      →  TRANS_BUS-PUN-CUZ              «Bus nocturno Puno → Cusco» (también sale a las 22:00)
 *   REC-PUNO-TERM_PUN   →  RECEP_TRANS-TERM_PUN-HTL_PUN
 *
 * Por SQL porque `slug` y `nombre_interno` no se traducen ni los escucha nadie. Las cotizaciones
 * enlazan el segmento por id, no por código: no cambian.
 */
final class Version20261023190000 extends AbstractMigration
{
    /** @var list<array{string, string, string, string}> [código antes, código ahora, nombre antes, nombre ahora] */
    private const array CAMBIOS = [
        ['TRANS-CUZ-PUNO', 'TRANS_BUS-CUZ-PUN', 'Bus nocturno Cusco – Puno', 'Bus nocturno Cusco → Puno'],
        ['TRANS-PUNO-CUZ', 'TRANS_BUS-PUN-CUZ', 'Bus Puno – Cusco', 'Bus nocturno Puno → Cusco'],
        ['REC-PUNO-TERM_PUN', 'RECEP_TRANS-TERM_PUN-HTL_PUN', 'Recepción en el Terminal Terrestre de Puno y traslado al hotel', 'Recepción en el Terminal Terrestre de Puno y traslado al hotel'],
    ];

    public function getDescription(): string
    {
        return 'Conexión Cusco ↔ Puno: códigos TRANS_BUS y RECEP_TRANS, nombres de los buses simétricos';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CAMBIOS as [$antes, $ahora, $nombreAntes, $nombreAhora]) {
            $this->addSql('UPDATE travel_segmento SET slug = ?, nombre_interno = ? WHERE slug = ?', [$ahora, $nombreAhora, $antes]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CAMBIOS as [$antes, $ahora, $nombreAntes, $nombreAhora]) {
            $this->addSql('UPDATE travel_segmento SET slug = ?, nombre_interno = ? WHERE slug = ?', [$antes, $nombreAntes, $ahora]);
        }
    }
}
