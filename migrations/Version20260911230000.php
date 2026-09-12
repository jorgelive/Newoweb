<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera `pms_establecimiento.telefono_yape`: era el mismo dato que el catálogo de cobro.
 *
 * ── Por qué sobra ───────────────────────────────────────────────────────────
 * El número del Yape ya vive en `fin_medio_cobro` —con su titular, su moneda y su nota—, que es
 * de donde salen los medios de pago del huésped y los del mensaje. Esta columna era una segunda
 * copia que nadie sincroniza: el día que el Yape cambie de titular, el agente seguiría dando el
 * viejo sin que nada fallara.
 *
 * ── Y por qué NO era sólo una duplicidad ────────────────────────────────────
 * `ConsultarCodigosSkill` lo devolvía como **contacto** —«por los dos contesta una persona»—, y
 * ese número es el móvil personal de Susan. Decisión de Jorge (11/09/2026): los números
 * personales dejan de darse como contacto. Para una urgencia queda `telefono_atencion`, que es
 * exactamente para eso; para pagar por Yape está el catálogo de cobro, que además dice a nombre
 * de quién va.
 *
 * ── El dato no se pierde ────────────────────────────────────────────────────
 * `fin_medio_cobro` ya tiene el mismo número en sus filas de Yape y Plin, así que no hay nada que
 * copiar antes de borrar. El `down()` devuelve la columna vacía: si alguna vez hiciera falta, se
 * rellena desde el catálogo, que es la fuente buena.
 */
final class Version20260911230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita pms_establecimiento.telefono_yape: el número del Yape vive en fin_medio_cobro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento DROP telefono_yape');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento ADD telefono_yape VARCHAR(30) DEFAULT NULL');
    }
}
