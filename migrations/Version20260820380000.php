<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera el segundo teléfono de la reserva y su flag.
 *
 * ── Por qué se van ──────────────────────────────────────────────────────────
 * Un número es de la **persona**, no de cada reserva. Repetido por reserva se contradice a la
 * primera: el huésped corrige una vez y las demás se quedan con el viejo. Ahora los números
 * viven en `msg_identidad`, donde se pueden añadir, retirar, marcar cuál es el bueno y vetar
 * sólo uno — cuatro cosas que estas dos columnas nunca supieron hacer.
 *
 * ── Y el uso decía lo mismo ─────────────────────────────────────────────────
 * Medido antes de tocar nada: **2 reservas de 298 tenían `telefono2`, y una de las dos llevaba
 * el MISMO número repetido en los dos campos**. `telefono2_es_principal` estaba en 0 en todas.
 * O sea: un helper, un flag, un reset en el pull y un espejo en TypeScript para una fila útil.
 *
 * ⚠️ `telefono` **se queda**, como SEMILLA: es lo que trajo el canal al dar de alta y lo que
 * crea la identidad la primera vez. No se proyecta encima el número corregido a propósito —eso
 * borraría lo que el huésped dio al reservar, que es justo el dato que después permite
 * distinguir «se equivocó» de «tiene dos números»—.
 *
 * ⚠️ `MaestroContacto` tiene el MISMO trío y **no se toca aquí**: es otra entidad, con sus
 * consumidores, y su camino se decide aparte.
 */
final class Version20260820380000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira pms_reserva.telefono2 y telefono2_es_principal: los números viven en las identidades.';
    }

    public function up(Schema $schema): void
    {
        // Rescate antes de soltar: el único `telefono2` que no es un duplicado se conserva como
        // identidad de la persona, que es donde le toca vivir. Sin retirar ni marcar principal
        // —eso lo decide alguien mirando— y con `origen` que dice de dónde salió.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO msg_identidad (id, conversacion_id, tipo, valor, origen, bloqueado, principal, created_at, updated_at)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), e.conversacion_id, 'telefono',
                   REGEXP_REPLACE(r.telefono2, '[^0-9]', ''), 'telefono2_migrado', 0, 0, NOW(), NOW()
              FROM pms_reserva r
              JOIN pms_conversacion_enlace e ON e.reserva_id = r.id AND e.es_titular = 1
             WHERE r.telefono2 IS NOT NULL
               AND r.telefono2 <> ''
               AND REGEXP_REPLACE(r.telefono2, '[^0-9]', '') <> REGEXP_REPLACE(COALESCE(r.telefono, ''), '[^0-9]', '')
        SQL);

        $this->addSql('ALTER TABLE pms_reserva DROP telefono2, DROP telefono2_es_principal');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_reserva
            ADD telefono2 VARCHAR(30) DEFAULT NULL,
            ADD telefono2_es_principal TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
