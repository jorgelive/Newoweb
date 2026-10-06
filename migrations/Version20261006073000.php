<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los cuatro segmentos que vivían en un «día relativo» que no existe.
 *
 * El Constructor de Storytelling agrupa los segmentos por `dia`, y cuatro tenían **−4** y **0**:
 * salían bajo un «DÍA RELATIVO −4» que no corresponde a ningún día del viaje.
 *
 * ```
 * Santa Rosa (clon)      Alojamiento      dia = -4   fecha = 2027-10-05 = inicio del servicio
 * La Salle confirmado    Alojamiento      dia = -4   fecha = 2026-09-18 = inicio del servicio
 * La Salle operativa     Alojamiento      dia = -4   fecha = 2026-09-18 = inicio del servicio
 * La Salle operativa     Seguro de Viaje  dia =  0   fecha = 2026-09-18 = inicio del servicio
 * ```
 *
 * Son anteriores al clon —el original ya los traía— y **0 plantillas del catálogo** tienen un día
 * menor que 1, así que es una anomalía de cuando se armaron a mano, no algo sistemático.
 *
 * ## No tocaba al cliente, y por eso había que mirarlo antes de arreglarlo
 *
 * ⚠️ **Nada deriva `fecha_absoluta` de `dia`** —se comprobó: los únicos que escriben esa fecha son
 * `Cotizacion::desplazarA()` y la normalización del editor—, así que el itinerario del huésped,
 * que va por fecha, siempre salió bien. Lo que rompía era el editor:
 *
 * - el segmento aparecía solo, en un día que no existe;
 * - no se podía arrastrar junto a los demás (`if (fromSeg.dia !== toSeg.dia) return`);
 * - y los componentes inyectados en él heredaban ese día en el pivote.
 *
 * O sea: molestaba al operar, no al vender. Por eso se corrige el `dia` y **no se toca ni una
 * fecha**.
 *
 * ## Por qué 1, y por qué la condición es la fecha
 *
 * Los cuatro son el **único segmento de su servicio**, con `orden = 1`, y su `fecha_absoluta` es
 * **exactamente** la de inicio de su servicio. Eso es el día 1 por definición.
 *
 * Y de ahí sale el `WHERE`: en vez de «todo lo que tenga `dia < 1`», se exige además que la fecha
 * diga que es el primer día. Medido antes de escribirla: 4 de 4 lo cumplen y **0** quedan fuera.
 * Si algún día aparece un `dia < 1` con otra fecha, será otro problema y esta migración no lo
 * tocará — que es lo que se quiere de una reparación acotada.
 */
final class Version20261006073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Los 4 segmentos con día relativo < 1 pasan al día 1 (su fecha ya decía que lo eran)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_segmento g
            JOIN cotizacion_cotservicio s ON s.id = g.cotservicio_id
            SET g.dia = 1
            WHERE g.dia < 1
              AND g.fecha_absoluta IS NOT NULL
              AND s.fecha_inicio_absoluta IS NOT NULL
              AND DATE(g.fecha_absoluta) = DATE(s.fecha_inicio_absoluta)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // No se revierte: «−4» y «0» no se distinguen del día 1 legítimo una vez normalizados, y
        // devolverlos sería reintroducir el desorden en filas que quizá nunca lo tuvieron. Es la
        // misma razón que en la migración de Quelccaya.
        $this->throwIrreversibleMigrationException('El día relativo corregido no se revierte: un 1 normalizado no se distingue de un 1 legítimo.');
    }
}
