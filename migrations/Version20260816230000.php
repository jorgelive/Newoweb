<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Cotizacion\Service\InclusionComponenteIdBackfill;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enlaza las propuestas ya guardadas con sus componentes, sin que nadie tenga que acordarse.
 *
 * ── Por qué es una migración y no «un paso más del despliegue» ──────────────
 * El proveedor que ve el cliente se lee del componente VIVO, y el puente es `componenteId`
 * en cada línea de inclusión. Las propuestas anteriores al campo no lo traen.
 *
 * Esto podría haber sido un comando post-despliegue —de hecho lo es, y se puede repetir—,
 * pero **un paso que hay que recordar es un paso que algún día no se hace**. Y el síntoma
 * no sería un error que alguien vea: sería que ciertas propuestas dejan de mostrar el
 * proveedor, en silencio. Así que va aquí, y se ejecuta solo.
 *
 * ── No es esquema, es datos ─────────────────────────────────────────────────
 * Rompe la regla habitual de que una migración toca el esquema, y se hace a conciencia:
 * las dos columnas `json` que reescribe no las recalcula ninguna entidad al guardarse y no
 * pasan por `AutoTranslate` ni por los listeners de coherencia financiera, que es el motivo
 * por el que CLAUDE.md manda ciertos datos por comando. Aquí ese motivo no aplica.
 *
 * ── Idempotente y sin adivinar ──────────────────────────────────────────────
 * Las líneas que ya tienen el id no se tocan, así que volver a pasarla no hace nada. Y lo
 * que no puede emparejar **sin ambigüedad** lo deja sin tocar y lo reporta, en vez de
 * enlazarlo al azar y enseñar el proveedor de otro componente. Detalle del emparejamiento
 * y del gotcha de los UUID en {@see InclusionComponenteIdBackfill}.
 */
final class Version20260816230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enlaza las líneas de inclusión ya guardadas con su componente (componenteId).';
    }

    public function up(Schema $schema): void
    {
        $r = (new InclusionComponenteIdBackfill($this->connection))->ejecutar();

        $this->write(sprintf(
            '  %d cotización(es) · %d línea(s) enlazadas · %d sin pareja',
            $r['cotizaciones'],
            $r['lineas'],
            $r['huerfanas'],
        ));

        if ($r['ambiguas'] > 0) {
            $this->write(sprintf(
                '  ⚠️  %d clave(s) ambigua(s) (mismo servicio, nombre y fecha): sus líneas se'
                . ' dejaron SIN enlazar a propósito. Revisar y re-guardar esas propuestas.',
                $r['ambiguas']
            ));
        }

        if ($r['huerfanas'] > 0) {
            $this->write(sprintf(
                '  ⚠️  %d línea(s) sin pareja: su proveedor no se mostrará hasta re-guardar la'
                . ' propuesta en el editor.',
                $r['huerfanas']
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'El enlace no se quita: es un id correcto y quitarlo sólo rompería la vista del cliente.'
        );
    }
}
