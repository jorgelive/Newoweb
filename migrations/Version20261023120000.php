<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El bus Cusco → Puno pasa al servicio «Transporte en Cusco» (23/10/2026).
 *
 * Los dos sentidos del bus estaban en el pool de «Transporte en Puno» (`TRF_PUN`), y al armar el
 * día de la salida desde Cusco no aparecía donde se busca: en el transporte de la ciudad de la que
 * se sale. Jorge: «Puno–Cusco debería estar en transporte en Puno y el otro en transporte en
 * Cusco». Es la misma regla que los traslados de cada ciudad (`docs/TravelCargaDeCatalogo.md`,
 * «Dos componentes por ciudad»): el servicio es el de la ciudad de SALIDA.
 *
 * El componente «Transporte Puno ↔ Cusco (ida o vuelta)» es uno por ruta, no por sentido, así que
 * se queda en los dos pools: sin él en `TRF_CUZ`, el segmento se arrastraría sin nada que cobrar
 * (el hueco de los dos pools, §3 del mismo doc).
 *
 * Por SQL porque los pools son tablas de unión sin listeners ni traducción.
 */
final class Version20261023120000 extends AbstractMigration
{
    private const string SEGMENTO = 'TRANS-CUZ-PUNO';
    private const string COMPONENTE = 'Transporte Puno ↔ Cusco (ida o vuelta)';

    public function getDescription(): string
    {
        return 'Bus Cusco → Puno: del pool de Transporte en Puno al de Transporte en Cusco';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO travel_segmento_servicio_pool (travel_segmento_id, travel_servicio_id)
             SELECT sg.id, s.id FROM travel_segmento sg, travel_servicio s WHERE sg.slug = ? AND s.codigo = ?',
            [self::SEGMENTO, 'TRF_CUZ']
        );
        $this->addSql(
            'DELETE p FROM travel_segmento_servicio_pool p
             JOIN travel_segmento sg ON sg.id = p.travel_segmento_id
             JOIN travel_servicio s ON s.id = p.travel_servicio_id
             WHERE sg.slug = ? AND s.codigo = ?',
            [self::SEGMENTO, 'TRF_PUN']
        );
        $this->addSql(
            'INSERT IGNORE INTO travel_servicio_componentes_pool (travel_servicio_id, travel_componente_id)
             SELECT s.id, c.id FROM travel_servicio s, travel_componente c WHERE s.codigo = ? AND c.nombre_interno = ?',
            ['TRF_CUZ', self::COMPONENTE]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO travel_segmento_servicio_pool (travel_segmento_id, travel_servicio_id)
             SELECT sg.id, s.id FROM travel_segmento sg, travel_servicio s WHERE sg.slug = ? AND s.codigo = ?',
            [self::SEGMENTO, 'TRF_PUN']
        );
        $this->addSql(
            'DELETE p FROM travel_segmento_servicio_pool p
             JOIN travel_segmento sg ON sg.id = p.travel_segmento_id
             JOIN travel_servicio s ON s.id = p.travel_servicio_id
             WHERE sg.slug = ? AND s.codigo = ?',
            [self::SEGMENTO, 'TRF_CUZ']
        );
        $this->addSql(
            'DELETE p FROM travel_servicio_componentes_pool p
             JOIN travel_servicio s ON s.id = p.travel_servicio_id
             JOIN travel_componente c ON c.id = p.travel_componente_id
             WHERE s.codigo = ? AND c.nombre_interno = ?',
            ['TRF_CUZ', self::COMPONENTE]
        );
    }
}
