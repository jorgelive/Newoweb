<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El Two Day MAPI bimodal sale de Cusco de madrugada y toma el tren de las 07:45 (23/10/2026).
 *
 * `app:travel:two-day-mapi-bimodal-completo` le añadió el traslado y el bus del día 1 con horas
 * corridas al tren de las 16:36 que tenía la plantilla. Jorge: «usa mejor el de las 7:45–9:15».
 * Con ese tren el día 1 es el del Full Day bimodal, así que las tres horas se copian de él tal
 * cual. Sólo horas de los pivotes de esta plantilla: nada traducible ni promovido.
 */
final class Version20261023233000 extends AbstractMigration
{
    private const string PLANTILLA = '2D MAPI: CUZ OLLA MAPI OLLA CUZ (BM)';

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> segmento → [hora, fin, hora antes, fin antes] */
    private const array HORAS = [
        'TRANS_BIM_IDA-MAPI-HTL_WAN_OLL' => ['04:20', '04:40', '13:10', '13:30'],
        'TRANS_BIM_IDA-MAPI-CUZ_OLL' => ['05:10', '07:10', '14:00', '16:00'],
        'TREN_IDA-MAPI-OLL' => ['07:45', '09:15', '16:36', '18:09'],
    ];

    public function getDescription(): string
    {
        return 'Two Day MAPI bimodal: día 1 con el tren de las 07:45, como el Full Day';
    }

    public function up(Schema $schema): void
    {
        foreach (self::HORAS as $segmento => [$hora, $fin]) {
            $this->fijar($segmento, $hora, $fin);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::HORAS as $segmento => [, , $hora, $fin]) {
            $this->fijar($segmento, $hora, $fin);
        }
    }

    private function fijar(string $segmento, string $hora, string $fin): void
    {
        $this->addSql(
            'UPDATE travel_segmento_componente sc'
            . ' JOIN travel_itinerario i ON i.id = sc.itinerario_contexto_id'
            . ' JOIN travel_segmento s ON s.id = sc.segmento_id'
            . ' SET sc.hora = ?, sc.hora_fin = ?'
            . ' WHERE i.slug = ? AND s.slug = ?',
            [$hora, $fin, self::PLANTILLA, $segmento],
        );
    }
}
