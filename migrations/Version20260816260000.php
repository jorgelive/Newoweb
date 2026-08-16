<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dos papeles, no tres: el «proveedor» del componente era el prestador con otro nombre.
 *
 * ── Qué se corrige ──────────────────────────────────────────────────────────
 * `Proveedor` es la ENTIDAD maestra: el contacto-empresa, el otro lado de `Cliente`. Lo
 * que el componente guarda no es esa entidad otra vez, es el PAPEL que juega. Y a nivel de
 * cotización sólo hacen falta dos:
 *
 *   · PRESTADOR — quién presta el servicio. Lo que ve el cliente, y lo que La Biblia
 *     necesita para el recojo.
 *   · COMPRADOR — a quién se le encarga ejecutar la compra. Sólo Operación.
 *
 * Había un tercero, `proveedor*`, que respondía «a quién le compro». Medido: **su único
 * lector era la serialización pública** —cero consumidores en Operación, cero en finanzas—
 * y lo que enseñaba al cliente era, en la práctica, quién presta el servicio. Dos caras
 * públicas para un solo hecho, cada una con su bandera.
 *
 * ── El traslado ─────────────────────────────────────────────────────────────
 * El bloque rico del proveedor (título, url, imágenes, servicio contratado, bandera) **es**
 * el prestador: se mueve tal cual a sus columnas, que estaban vacías en el 100% de las
 * filas, así que no hay colisión que resolver. El prestador conserva lo suyo —teléfono y
 * dirección congelados— y gana un correo.
 *
 * ── El correo, y el prestador a mano ────────────────────────────────────────
 * `prestador_email_snapshot` es lo que hace viable la excepción: una empresa que no está
 * en el catálogo no se puede filtrar ni resolver en vivo, pero con su correo se le manda
 * la orden esa vez y se sigue. Sin él, «a mano» sería un callejón sin salida.
 *
 * ── Y fuera el prestador del día ────────────────────────────────────────────
 * `cotizacion_cotservicio.prestador_*` era un default heredable. Con la cascada reducida a
 * un solo nivel no tiene a quién alimentar, y estaba en 0 filas.
 */
final class Version20260816260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotcomponente: el proveedor se funde en el prestador; fuera el prestador del día.';
    }

    public function up(Schema $schema): void
    {
        $c = $this->connection;

        $c->executeStatement("ALTER TABLE cotizacion_cotcomponente
            ADD prestador_email_snapshot VARCHAR(100) DEFAULT NULL");

        // El prestador estaba vacío en todas las filas: el traslado es directo.
        $c->executeStatement("UPDATE cotizacion_cotcomponente
            SET prestador_maestro_id       = proveedor_maestro_id,
                prestador_nombre_snapshot  = proveedor_nombre_snapshot,
                prestador_titulo_snapshot  = proveedor_titulo_snapshot,
                prestador_url_snapshot     = proveedor_url_snapshot,
                prestador_imagenes_snapshot = proveedor_imagenes_snapshot,
                prestador_visible          = proveedor_visible
            WHERE proveedor_maestro_id IS NOT NULL
               OR TRIM(COALESCE(proveedor_nombre_snapshot, '')) <> ''");

        // El servicio contratado cambia de prefijo, no de contenido.
        $c->executeStatement("ALTER TABLE cotizacion_cotcomponente
            CHANGE proveedor_servicio_maestro_id        prestador_servicio_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE proveedor_servicio_titulo_snapshot   prestador_servicio_titulo_snapshot JSON NOT NULL,
            CHANGE proveedor_servicio_url_snapshot      prestador_servicio_url_snapshot VARCHAR(255) DEFAULT NULL,
            CHANGE proveedor_servicio_imagenes_snapshot prestador_servicio_imagenes_snapshot JSON NOT NULL");

        $c->executeStatement("ALTER TABLE cotizacion_cotcomponente
            DROP proveedor_maestro_id,
            DROP proveedor_nombre_snapshot,
            DROP proveedor_visible,
            DROP proveedor_titulo_snapshot,
            DROP proveedor_url_snapshot,
            DROP proveedor_imagenes_snapshot");

        $c->executeStatement("ALTER TABLE cotizacion_cotservicio
            DROP prestador_maestro_id,
            DROP prestador_nombre_snapshot");

        // Una bandera sobre un campo vacío es peor que inútil: si mañana alguien escribe el
        // prestador a mano, saldría publicado sin que nadie lo haya decidido. La siembra
        // anterior puso `prestador_visible = 1` en todos los `no_incluido`, cuando el
        // prestador no existía en ninguna fila.
        $c->executeStatement("UPDATE cotizacion_cotcomponente
            SET prestador_visible = 0
            WHERE prestador_maestro_id IS NULL
              AND TRIM(COALESCE(prestador_nombre_snapshot, '')) = ''");

        $movidos = (int) $c->fetchOne(
            'SELECT COUNT(*) FROM cotizacion_cotcomponente WHERE prestador_maestro_id IS NOT NULL'
        );
        $visibles = (int) $c->fetchOne(
            'SELECT COUNT(*) FROM cotizacion_cotcomponente WHERE prestador_visible = 1'
        );
        $this->write(sprintf('  Prestadores tras la fusión: %d · nombrables: %d', $movidos, $visibles));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'La fusión no se deshace: el proveedor y el prestador eran el mismo hecho y '
            . 'volver a partirlos exigiría inventar cuál de los dos era cuál.'
        );
    }
}
