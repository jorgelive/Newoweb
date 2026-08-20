<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El hilo deja de identificarse por UNA columna y pasa a tener un grupo de identificadores.
 *
 * `MessageConversation::$guestPhone` era un `varchar`: un valor y un canal. No había dónde poner
 * un segundo teléfono ni el correo por el que la misma persona contesta — que es justo lo que
 * hace falta para traer las respuestas del buzón y meterlas en el hilo correcto.
 *
 * `(tipo, valor)` es **único**: un identificador pertenece a un hilo y sólo a uno. Eso convierte
 * la resolución en determinista y permite jubilar el `LIKE '%últimos 8 dígitos'`, que resolvía
 * un problema real —prefijos internacionales distintos— y podía casar con otra persona sin
 * dejar rastro.
 *
 * ## El arrastre
 *
 * Cada `guest_phone` no vacío se convierte en una identidad de tipo `telefono`, normalizado a
 * dígitos y `+`. **`guest_phone` se queda**: 30 sitios del código la leen y el panel la pinta.
 * Pasa a ser una copia denormalizada; la verdad está en la tabla nueva.
 *
 * ⚠️ **`INSERT IGNORE` y no `INSERT`, y el motivo importa.** Hay teléfonos repartidos en más de
 * un hilo —20 medidos en producción, uno con 247 mensajes en dos— y el índice único los
 * rechazaría, tumbando la migración entera. Con `IGNORE`, el primero se lleva la identidad y el
 * resto quedan sin ella: siguen existiendo y visibles, sólo que un mensaje nuevo irá al primero.
 * Fusionarlos toca historial real y va en un comando aparte con `--dry-run`, no aquí.
 *
 * El orden por `created_at` no es cosmético: gana el hilo **más antiguo**, que es el que tiene
 * el historial largo.
 */
final class Version20260820280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversaciones: tabla de identidades (teléfono, correo) con el arrastre de guest_phone.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE msg_identidad (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            conversacion_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            tipo VARCHAR(20) NOT NULL,
            valor VARCHAR(190) NOT NULL,
            origen VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_identidad_conversacion (conversacion_id),
            UNIQUE INDEX uq_identidad_tipo_valor (tipo, valor),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE msg_identidad
            ADD CONSTRAINT FK_580507D3ABD5A1D6 FOREIGN KEY (conversacion_id)
                REFERENCES msg_conversation (id) ON DELETE CASCADE');

        // UUID v4 con `UUID_TO_BIN`: la columna es BINARY(16) y aquí no hay PHP que genere v7.
        // Da igual el orden de estas filas —no se recorren por id— así que la ventaja del v7
        // no aplica.
        $this->addSql("INSERT IGNORE INTO msg_identidad (id, conversacion_id, tipo, valor, origen, created_at)
            SELECT UUID_TO_BIN(UUID()),
                   c.id,
                   'telefono',
                   REGEXP_REPLACE(c.guest_phone, '[^0-9+]', ''),
                   'migracion',
                   NOW()
              FROM msg_conversation c
             WHERE c.guest_phone IS NOT NULL
               AND REGEXP_REPLACE(c.guest_phone, '[^0-9+]', '') <> ''
             ORDER BY c.created_at ASC");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE msg_identidad');
    }
}
