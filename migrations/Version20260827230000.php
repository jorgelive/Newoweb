<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los teléfonos de ATENCIÓN y de YAPE pasan a ser campos del establecimiento.
 *
 * ### Qué problema resuelve
 *
 * Vivían escritos dentro del texto de un ítem de guía («Horario solicitudes»), y por tanto sólo
 * llegaban al agente si el índice de temas seleccionaba ESE ítem. El 27/08/2026 una huésped
 * estuvo una hora en la puerta sin poder entrar: escribió «acabamos de llegar», que no casa con
 * los términos de ese ítem (`horario de atención`, `a qué hora atienden`, `contacto`), así que
 * el modelo no vio ningún teléfono — y, sin una salida que ofrecer, se inventó que alguien
 * acudiría a recibirla.
 *
 * Como campo, salen por el camino **determinista**: los devuelve `ConsultarCodigosSkill` junto
 * al motivo, igual que ya hace con el código de la caja, sin depender de que el modelo acierte
 * a buscarlos.
 *
 * ### ⚠️ No confundir con `telefono_principal`
 *
 * Ése es el **comercial** —está en la web y en las OTA, y se sirve al catálogo público
 * (`pax_catalogo:read`)—, y hoy vale `921166466`, que no es ninguno de estos dos. Son tres
 * números para tres cosas distintas.
 *
 * ### Y por qué dos campos y no uno
 *
 * Hoy contestan los dos, pero son dos hechos distintos: «llámame» y «págame aquí». El día que
 * el Yape cambie de titular tienen que poder moverse por separado.
 *
 * El backfill copia los números que ya estaban en el ítem de guía. Sólo rellena si está vacío:
 * si alguien los cambia en el panel antes de que esto corra, su valor manda.
 */
final class Version20260827230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Teléfonos de atención y Yape como campos del establecimiento (salían de un ítem de guía).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_establecimiento
            ADD telefono_atencion VARCHAR(30) DEFAULT NULL,
            ADD telefono_yape VARCHAR(30) DEFAULT NULL
        SQL);

        // Los que ya decía el ítem «Horario solicitudes (general)»: por los dos se contesta, y
        // el segundo es además el del Yape.
        $this->addSql(<<<'SQL'
            UPDATE pms_establecimiento
            SET telefono_atencion = '+51961281953',
                telefono_yape     = '+51958191965'
            WHERE telefono_atencion IS NULL AND telefono_yape IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento DROP telefono_atencion, DROP telefono_yape');
    }
}
