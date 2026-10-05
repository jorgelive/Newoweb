<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los segmentos que el primer clon se dejó un año atrás.
 *
 * `Cotizacion::desplazarA()` mueve la cotización entera a una fecha nueva por DELTA, y recorría
 * **servicios y componentes**. Los segmentos tienen su propia `fecha_absoluta` y no estaban en el
 * recorrido, así que en el primer clon real —«Promoción 2027 Colegio Santa Rosa», 05/10/2026—
 * pasó esto:
 *
 * ```
 * servicios     2027-10-04 → 2027-10-10   ✅ movidos
 * componentes   2027-10-04 → 2027-10-10   ✅ movidos
 * segmentos     2026-09-17 → 2027-10-10   ❌ 40 de 44 se quedaron en el original
 * ```
 *
 * ⚠️ **No falló nada.** Cada tabla era coherente consigo misma y la cotización se publicó. Lo que
 * se veía era la cabecera del itinerario del cliente anunciando **«389 días, 386 noches»** para un
 * viaje de siete, porque los días son del CALENDARIO (`resumenDeDuracion()` toma el `numeroDia`
 * del último bloque) y el primero estaba un año antes que el último.
 *
 * El recorrido ya está arreglado en la entidad; esto repara lo que quedó escrito.
 *
 * ## Por qué se suma el delta y NO se recalcula desde el servicio
 *
 * La tentación es imponer el invariante `fecha_absoluta = servicio + (dia - 1)`, que se cumple en
 * el 97% del catálogo. **Se midió, y no es absoluto**: hay seis segmentos legítimamente desviados
 * entre −3 y +5 días en cuatro cotizaciones distintas, ajustes a mano del operador. Recalcular los
 * aplastaría.
 *
 * Sumar el delta que el clon se dejó preserva lo que el original tuviera, incluidos sus ajustes:
 * de hecho Santa Rosa tiene un segmento a −377 en vez de −382, y al sumarle 382 vuelve al **+5**
 * que su original ya tenía. Si se recalculara, ese +5 se perdería.
 *
 * ## Por qué la banda de −300 a −400 y no «todo lo desviado»
 *
 * Separa el fallo del ruido sin tocar nada más. Medido antes de escribirla:
 *
 * ```
 * −382 días × 39 segmentos  ← el clon           Santa Rosa
 * −377 días ×  1 segmento   ← el clon           Santa Rosa
 *   −3 … +5 ×  6 segmentos  ← ajustes a mano    otras cuatro cotizaciones
 * ```
 *
 * Fuera de esa banda no hay nada, así que la migración es un no-op en cualquier otra base y
 * repetirla no mueve nada dos veces: al corregirse, el desvío deja de estar en la banda.
 */
final class Version20261006030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repara los segmentos que el clon de Santa Rosa dejó un año atrás (+382 días)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_segmento g
            JOIN cotizacion_cotservicio s ON s.id = g.cotservicio_id
            SET g.fecha_absoluta = DATE_ADD(g.fecha_absoluta, INTERVAL 382 DAY)
            WHERE g.fecha_absoluta IS NOT NULL
              AND s.fecha_inicio_absoluta IS NOT NULL
              AND DATEDIFF(DATE(g.fecha_absoluta), DATE(DATE_ADD(s.fecha_inicio_absoluta, INTERVAL g.dia - 1 DAY)))
                  BETWEEN -400 AND -300
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Simétrica: deshacer es restar los mismos 382 días a lo que quedó en la banda opuesta.
        // La banda de vuelta es la de arriba corrida 382, o sea 0 ± 100 — demasiado ancha, cazaría
        // los ajustes legítimos. Así que se acota a lo que este `up()` pudo producir: segmentos de
        // 2027 cuyo servicio arranca en octubre de 2027, que es el único caso que tocó.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_segmento g
            JOIN cotizacion_cotservicio s ON s.id = g.cotservicio_id
            SET g.fecha_absoluta = DATE_SUB(g.fecha_absoluta, INTERVAL 382 DAY)
            WHERE g.fecha_absoluta IS NOT NULL
              AND s.fecha_inicio_absoluta IS NOT NULL
              AND DATE(s.fecha_inicio_absoluta) BETWEEN '2027-10-01' AND '2027-10-31'
              AND DATEDIFF(DATE(g.fecha_absoluta), DATE(DATE_ADD(s.fecha_inicio_absoluta, INTERVAL g.dia - 1 DAY)))
                  BETWEEN -10 AND 10
            SQL);
    }
}
