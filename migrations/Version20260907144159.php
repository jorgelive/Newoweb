<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * En qué se cuenta un componente que dura, cómo se llama esa unidad y dónde se lee en el día.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * Un hotel del 18 al 22 son 4 noches; un seguro del 18 al 22 son 5 días. Hasta ahora el editor
 * sólo sabía restar (`calcularPernoctes`), así que para cobrar los 5 días de estancia en Punta
 * Cana hubo que escribir el seguro como «18 → 23»: **se torció la fecha para que cuadrara el
 * importe**, y el itinerario quedó diciendo que la cobertura llegaba a un día en que ya nadie
 * estaba allí.
 *
 * Los tres campos van por duplicado a propósito:
 *   travel_componente          lo declara quien crea el producto, UNA vez
 *   cotizacion_cotcomponente   congelado al añadirlo, como el resto del expediente
 *
 * NULL en todos = lo que diga el tipo (`ComponenteTipoEnum::unidadPorDefecto()` y
 * `ordenNarrativo()`), que acierta en 31 de los 33 componentes multi-día que hay hoy.
 *
 * ⚠️ Sin backfill de datos aquí: lo hace `app:travel:asignar-unidades-de-conteo`, que además
 * corrige las fechas torcidas y por eso no puede ser SQL — pasa por el ORM.
 */
final class Version20260907144159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unidad de conteo, sustantivo y momento del día: en el catálogo y en el snapshot de la cotización.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_componente ADD unidad_de_conteo VARCHAR(20) DEFAULT NULL, ADD sustantivo_unidad VARCHAR(30) DEFAULT NULL, ADD momento_del_dia VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD unidad_conteo_snapshot VARCHAR(20) DEFAULT NULL, ADD sustantivo_unidad_snapshot VARCHAR(30) DEFAULT NULL, ADD momento_dia_snapshot VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP unidad_conteo_snapshot, DROP sustantivo_unidad_snapshot, DROP momento_dia_snapshot');
        $this->addSql('ALTER TABLE travel_componente DROP unidad_de_conteo, DROP sustantivo_unidad, DROP momento_del_dia');
    }
}
