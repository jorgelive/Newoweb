<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Propaga el nombre OPERATIVO del maestro al `nombreSnapshot` de las cotizaciones existentes.
 *
 * Hasta hoy `nombreSnapshot` se sembraba con el TÍTULO del servicio (comercial, poético). El
 * modelo cambió: `nombreSnapshot` es el nombre operativo —el que va al proveedor— y sale del
 * `nombreInterno` de la plantilla (si el servicio se armó desde una), o del servicio en su
 * defecto. Esta migración pone al día las cotizaciones ya guardadas con la misma prioridad que
 * el editor aplica a las nuevas.
 *
 * ── Por qué es seguro ───────────────────────────────────────────────────────
 * `nombreSnapshot` del servicio es INTERNO: no está en `pax_cotizacion:read`, el cliente nunca
 * lo vio. Y era de sólo lectura hasta hoy, así que todos los valores existentes son el título
 * auto-copiado, no algo que el operador escribiera. Se sobrescriben sin pérdida de trabajo.
 *
 * ── Prioridad, y el gotcha del UUID ─────────────────────────────────────────
 * Plantilla gana sobre servicio, igual que `aplicarPlantilla()`. El join va por
 * `UNHEX(REPLACE(id,'-',''))`: los `*MaestroId` guardan el uuid con guiones y la PK es binaria;
 * comparar con `HEX()` daría cero filas en silencio (el gotcha de siempre).
 *
 * Queda en un solo idioma (`es`): el nombre operativo no se traduce.
 */
final class Version20260817260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Propaga el nombre operativo (plantilla→servicio) al nombreSnapshot de cotservicios.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_cotservicio cs
            LEFT JOIN travel_itinerario it
                   ON it.id = UNHEX(REPLACE(cs.itinerario_maestro_id, '-', ''))
            LEFT JOIN travel_servicio sv
                   ON sv.id = UNHEX(REPLACE(cs.servicio_maestro_id, '-', ''))
            SET cs.nombre_snapshot = JSON_ARRAY(
                    JSON_OBJECT('language', 'es', 'content', COALESCE(it.nombre_interno, sv.nombre_interno))
                )
            WHERE COALESCE(it.nombre_interno, sv.nombre_interno) IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No hay vuelta atrás fiable: el título original ya no está en ninguna tabla —vivía en
        // el propio nombre_snapshot que se acaba de reemplazar—. El respaldo previo al despliegue
        // es la única red. Se deja explícito para que nadie espere un rollback automático.
        $this->throwIrreversibleMigrationException(
            'No se puede reconstruir el título anterior: restaurar desde el respaldo de cotizacion_cotservicio.'
        );
    }
}
