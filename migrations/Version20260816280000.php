<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El prestador pasa a campos planos: enlace + nombre histórico, y nada más.
 *
 * ── Por qué, y no es por el tamaño ──────────────────────────────────────────
 * Se midió: las columnas que se retiran pesan 5,8 KB sobre un payload de 815 KB — el 0,7%.
 * No se hace por eso. Se hace porque **filtrar era ambiguo**: la misma empresa estaba en
 * trece columnas del componente y, si alguien la renombraba en el catálogo, ninguna
 * coincidía con las otras. Con `uuid + nombre` hay una sola cosa contra la que filtrar.
 *
 * De paso desaparece la doble dirección de resolución —maestro para el contacto, snapshot
 * para la presentación— que era lo más confuso de toda esta zona. Ahora hay una sola: el
 * maestro manda; si no está, el nombre histórico.
 *
 * ── Qué se pierde, dicho claro ──────────────────────────────────────────────
 * **Los overrides.** Ya no se puede enseñar en una propuesta un título distinto al del
 * catálogo: se edita el maestro y cambia en todas. Es la contrapartida de que todos los
 * prestadores se den de alta, que es lo que hace innecesario el texto suelto.
 *
 * ── Qué se conserva, y por qué el nombre no sobra ───────────────────────────
 * El soft-link no tiene integridad referencial a propósito. Si borran la empresa del
 * catálogo, el nombre guardado es **el último dato que sobrevive** — no es una copia por
 * si acaso, es la degradación. Lo mismo con el servicio, que hasta ahora ni siquiera
 * guardaba su nombre: se añade aquí.
 */
final class Version20260816280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotcomponente: prestador en campos planos (enlace + nombre histórico).';
    }

    public function up(Schema $schema): void
    {
        $c = $this->connection;

        $c->executeStatement('ALTER TABLE cotizacion_cotcomponente
            ADD prestador_servicio_nombre_snapshot VARCHAR(150) DEFAULT NULL');

        // El nombre del servicio no existía: se rescata del título en español que había
        // congelado, que es lo más cercano a un nombre histórico que hay hoy.
        $c->executeStatement("UPDATE cotizacion_cotcomponente
            SET prestador_servicio_nombre_snapshot =
                JSON_UNQUOTE(JSON_EXTRACT(prestador_servicio_titulo_snapshot, '$[0].content'))
            WHERE JSON_LENGTH(prestador_servicio_titulo_snapshot) > 0");

        $c->executeStatement('ALTER TABLE cotizacion_cotcomponente
            DROP prestador_email_snapshot,
            DROP prestador_telefono_snapshot,
            DROP prestador_direccion_snapshot,
            DROP prestador_titulo_snapshot,
            DROP prestador_url_snapshot,
            DROP prestador_imagenes_snapshot,
            DROP prestador_servicio_titulo_snapshot,
            DROP prestador_servicio_url_snapshot,
            DROP prestador_servicio_imagenes_snapshot');

        $rescatados = (int) $c->fetchOne(
            'SELECT COUNT(*) FROM cotizacion_cotcomponente
             WHERE prestador_servicio_nombre_snapshot IS NOT NULL'
        );
        $this->write(sprintf('  Nombres de servicio rescatados del título: %d', $rescatados));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Las columnas vuelven vacías: su contenido vive en travel_proveedor y se resuelve '
            . 'en vivo. Restaurarlas sin datos sería peor que no restaurarlas.'
        );
    }
}
