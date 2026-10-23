<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las plantillas MAPI con traslado directo ganan hora de FIN (23/10/2026).
 *
 * Sus pivotes tenían sólo la de inicio, así que cada tramo nacía con duración cero
 * (`docs/TravelCargaDeCatalogo.md` §5, «Y dale también hora de FIN»). Jorge: «es el mismo tren».
 * Tren, Consettur y visita son los mismos que en sus hermanas bimodales, y de ellas se copia el
 * fin. El traslado privado Cusco ↔ Ollantaytambo dura dos horas, las mismas que su pivote global
 * de retorno (18:20–20:20). El contacto no lleva fin, igual que en las bimodales.
 *
 * Sólo rellena lo que está vacío: un fin ya ajustado a mano no se pisa.
 */
final class Version20261024000000 extends AbstractMigration
{
    private const string DOS_DIAS = '2D MAPI: CUZ OLLA MAPI OLLA CUZ (DIR)';
    private const string FULL_DAY = '1D MAPI: CUZ OLLA MAPI OLLA CUZ (DIR)';

    private const string TREN = 'Tren Ollantaytambo ↔ Machu Picchu (ida o vuelta)';
    private const string PRIVADO = 'Transporte Cusco ↔ Ollanta (ida o vuelta)';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> plantilla, segmento, componente, fin */
    private const array FINES = [
        [self::DOS_DIAS, 'TRANS_DIRECT_SAL-MAPI-CUZ_OLL', self::PRIVADO, '07:30'],
        [self::DOS_DIAS, 'TREN_IDA-MAPI-OLL', self::TREN, '09:15'],
        [self::DOS_DIAS, 'SUBBAJ_BUS-MAPI-SUB', 'Consettur Subida', '07:30'],
        [self::DOS_DIAS, 'VIS-MAPI-C2', 'Ingreso a Machu Picchu', '08:30'],
        [self::DOS_DIAS, 'VIS-MAPI-C2', 'Guiado Machu Picchu', '10:30'],
        [self::DOS_DIAS, 'SUBBAJ_BUS-MAPI-BAJ', 'Consettur Bajada', '11:30'],
        [self::DOS_DIAS, 'TREN_RTN-MAPI-OLL', self::TREN, '16:08'],
        [self::DOS_DIAS, 'TRANS_DIRECT_RET-MAPI-OLL_CUZ', self::PRIVADO, '18:30'],

        [self::FULL_DAY, 'TRANS_DIRECT_SAL-MAPI-CUZ_OLL', self::PRIVADO, '07:30'],
        [self::FULL_DAY, 'TREN_IDA-MAPI-OLL', self::TREN, '09:15'],
        [self::FULL_DAY, 'SUBBAJ_BUS-MAPI-SUB', 'Consettur Subida', '10:30'],
        [self::FULL_DAY, 'VIS-MAPI-C2', 'Ingreso a Machu Picchu', '11:30'],
        [self::FULL_DAY, 'VIS-MAPI-C2', 'Guiado Machu Picchu', '13:30'],
        [self::FULL_DAY, 'SUBBAJ_BUS-MAPI-BAJ', 'Consettur Bajada', '14:30'],
        [self::FULL_DAY, 'TREN_RTN-MAPI-OLL', self::TREN, '18:10'],
        [self::FULL_DAY, 'TRANS_DIRECT_RET-MAPI-OLL_CUZ', self::PRIVADO, '20:20'],
    ];

    public function getDescription(): string
    {
        return 'Plantillas MAPI con traslado directo: hora de fin copiada de sus hermanas bimodales';
    }

    public function up(Schema $schema): void
    {
        foreach (self::FINES as [$plantilla, $segmento, $componente, $fin]) {
            $this->addSql(
                'UPDATE travel_segmento_componente sc'
                . ' JOIN travel_itinerario i ON i.id = sc.itinerario_contexto_id'
                . ' JOIN travel_segmento s ON s.id = sc.segmento_id'
                . ' JOIN travel_componente c ON c.id = sc.componente_id'
                . ' SET sc.hora_fin = ?'
                . ' WHERE i.slug = ? AND s.slug = ? AND c.nombre_interno = ? AND sc.hora_fin IS NULL',
                [$fin, $plantilla, $segmento, $componente],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::FINES as [$plantilla, $segmento, $componente, $fin]) {
            $this->addSql(
                'UPDATE travel_segmento_componente sc'
                . ' JOIN travel_itinerario i ON i.id = sc.itinerario_contexto_id'
                . ' JOIN travel_segmento s ON s.id = sc.segmento_id'
                . ' JOIN travel_componente c ON c.id = sc.componente_id'
                . ' SET sc.hora_fin = NULL'
                . ' WHERE i.slug = ? AND s.slug = ? AND c.nombre_interno = ? AND sc.hora_fin = ?',
                [$plantilla, $segmento, $componente, $fin],
            );
        }
    }
}
