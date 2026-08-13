<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La reserva y el expediente apuntan a su CONTACTO. Nullable, y todavía no manda.
 *
 * Segundo paso de la cirugía (docs/Mensajeria.md §20). Las columnas de cliente de cada módulo
 * —`pms_reserva.nombre_cliente`, `telefono`, `email_cliente`; `cotizacion_file.nombre_grupo`,
 * `telefono`, `email`— **siguen siendo la verdad**: esto sólo abre el camino para que dejen de
 * estar tecleadas tres veces.
 *
 * Hoy la misma persona está escrita en tres sitios con nombres de columna distintos, y
 * corregirle el teléfono en la reserva no lo corrige en su expediente de tours ni en su
 * conversación. Nadie se entera hasta que un mensaje sale al número viejo.
 *
 * ── Nullable y `ON DELETE SET NULL`, las dos cosas a propósito ──────────────
 * Nullable porque la columna se puebla a posteriori con un comando, y porque una reserva de OTA
 * puede no traer teléfono con el que reconocer a nadie: un `null` significa «todavía no se ha
 * cruzado», no «no tiene cliente».
 *
 * `SET NULL` y no `CASCADE` porque un contacto es un dato de referencia: borrarlo jamás puede
 * llevarse por delante una reserva. Lo peor que puede pasar es que la reserva se quede sin
 * apuntador, y sus propias columnas de cliente siguen ahí.
 *
 * No cambia ningún comportamiento: al terminar, las dos columnas están a `NULL` en todas las
 * filas y nadie las lee.
 */
final class Version20260812300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_reserva y cotizacion_file apuntan a maestro_contacto (nullable, sin poblar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_reserva ADD contacto_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E06B505CA9 FOREIGN KEY (contacto_id) REFERENCES maestro_contacto (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_717583E06B505CA9 ON pms_reserva (contacto_id)');

        $this->addSql("ALTER TABLE cotizacion_file ADD contacto_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE cotizacion_file ADD CONSTRAINT FK_4115D7D16B505CA9 FOREIGN KEY (contacto_id) REFERENCES maestro_contacto (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4115D7D16B505CA9 ON cotizacion_file (contacto_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E06B505CA9');
        $this->addSql('DROP INDEX IDX_717583E06B505CA9 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP contacto_id');

        $this->addSql('ALTER TABLE cotizacion_file DROP FOREIGN KEY FK_4115D7D16B505CA9');
        $this->addSql('DROP INDEX IDX_4115D7D16B505CA9 ON cotizacion_file');
        $this->addSql('ALTER TABLE cotizacion_file DROP contacto_id');
    }
}
