<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quita el «(M) » del título de seis estancias que resultaron ser REALES.
 *
 * El prefijo «(M) » lo escribimos nosotros en el `firstName` de un espejo, el booking que tapa el
 * listing gemelo. Cuando un espejo perdía su link y volvía por el barrido, el pull lo adoptaba como
 * reserva y el prefijo se quedaba en el `titulo_cache` del evento que estrenaba (§6.3.d).
 *
 * De las 17 reservas así, seis **no son fantasmas**: cruzadas por casita y fechas no hay ninguna
 * otra estancia esas noches, guardan teléfonos reales que no aparecen en ninguna otra reserva, y a
 * dos de ellas —`W9PGR3` y `VANJKN`— les llegaron la guía y el check-out por WhatsApp, uno leído.
 * Son huéspedes que se alojaron y cuya reserva original ya no está: lo que queda es el único
 * registro de su estancia, así que **no se borran, se les quita el prefijo**.
 *
 * ⚠️ El «(M) » está SÓLO en `titulo_cache`. `nombre_cliente`, `apellido_cliente` y el nombre del
 * hilo ya estaban limpios, así que esto no toca datos del huésped: corrige una etiqueta.
 *
 * ── Por qué migración y no comando ──────────────────────────────────────────
 * `titulo_cache` no lo recalcula nadie: `PmsEventoCalendarioCacheNormalizerListener` sólo lo
 * rellena en `prePersist` **y sólo si viene vacío**. No hay listener de coherencia que deba correr,
 * así que entra por SQL según la tabla de `CLAUDE.md`. Por el ORM, además, tocar el evento
 * despertaría a `Beds24BookingsPushQueueListener` y mandaría el nombre nuevo a Beds24 sobre
 * reservas pasadas, por nada.
 *
 * Idempotente: el `LIKE` deja de casar en cuanto el prefijo se ha quitado.
 */
final class Version20260911100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita el prefijo «(M) » del título de seis estancias reales (8 eventos).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pms_evento_calendario e
              JOIN pms_reserva r ON r.id = e.reserva_id
               SET e.titulo_cache = TRIM(SUBSTRING(e.titulo_cache, 5))
             WHERE e.titulo_cache LIKE '(M) %'
               AND r.localizador IN ('46Q86C', 'W9PGR3', 'D4VFFZ', 'YUKB4J', 'VANJKN', 'QBDNFK')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Devolver el «(M) » sería volver a marcar como espejo una estancia que no lo es.'
        );
    }
}
