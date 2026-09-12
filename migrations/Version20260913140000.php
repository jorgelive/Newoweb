<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 🔑 Las tres cajas fuertes pasan a llamarse por lo que GUARDAN.
 *
 * ── El desorden que había ──────────────────────────────────────────────────
 * Hay tres cajas y cada una tenía un nombre de otra familia:
 *
 * | caja | se llamaba | ahora |
 * |---|---|---|
 * | la de abajo, en el pasaje: las llaves | `codigoCajaPrincipal` / `{{ keybox_main }}` | `codigoCajaLlaves` / `{{ codigo_caja_llaves }}` |
 * | la de arriba, en el pasaje: la recaudación | `codigoCajaSecundaria` / `{{ keybox_sec }}` | `codigoCajaDinero` / `{{ codigo_caja_dinero }}` |
 * | la de dentro del departamento | `codigoCaja` / `{{ safe_code }}` | `codigoCajaCasita` / `{{ codigo_caja_casita }}` |
 *
 * «Principal» y «secundaria» no dicen cuál abre qué, y `keybox_sec` era peor: llamaba *keybox* —
 * caja de llaves — a la que guarda el dinero. Con tres cajas y un mensaje que manda a alguien a
 * marcar un código, un nombre que no distingue es el patrón exacto del `{{ door_code }}` que acabó
 * anunciando «el código de la puerta es #5».
 *
 * ── La correspondencia no se dedujo ────────────────────────────────────────
 * Está escrita en `ConsultarCodigosSkill`: *«`codigoCajaSecundaria` es la caja de la recaudación,
 * no la de las llaves»*. Y el ítem «Llaves (general)» manda al huésped a la caja **de abajo** con
 * el código principal, todos los días, así que si estuviera cambiado nadie entraría.
 *
 * ── Barato porque casi nada lo usaba ───────────────────────────────────────
 * `{{ keybox_sec }}` y `{{ safe_code }}` **no aparecían en ningún contenido**, y ninguna casita
 * tiene código de caja interior. Lo único que se sustituye de verdad es `{{ keybox_main }}` en el
 * ítem «Llaves (general)», en sus siete idiomas.
 *
 * ⚠️ Si el renombrado apuntara a una clave inexistente —como pasó el 11/09 con `numero_llave`—, lo
 * dice `app:pms:verificar-marcadores-guia` en su siguiente ejecución.
 */
final class Version20260913140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renombra las tres cajas fuertes por lo que guardan: llaves, dinero y casita.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento
            CHANGE codigo_caja_principal codigo_caja_llaves VARCHAR(50) DEFAULT NULL,
            CHANGE codigo_caja_secundaria codigo_caja_dinero VARCHAR(50) DEFAULT NULL');

        $this->addSql('ALTER TABLE pms_unidad
            CHANGE codigo_caja codigo_caja_casita VARCHAR(50) DEFAULT NULL');

        foreach ([
            '{{ keybox_main }}' => '{{ codigo_caja_llaves }}',
            '{{ keybox_sec }}' => '{{ codigo_caja_dinero }}',
            '{{ safe_code }}' => '{{ codigo_caja_casita }}',
        ] as $viejo => $nuevo) {
            $this->addSql(
                'UPDATE pms_guia_item SET descripcion = REPLACE(descripcion, :viejo, :nuevo) WHERE descripcion LIKE :patron',
                ['viejo' => $viejo, 'nuevo' => $nuevo, 'patron' => '%' . $viejo . '%']
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento
            CHANGE codigo_caja_llaves codigo_caja_principal VARCHAR(50) DEFAULT NULL,
            CHANGE codigo_caja_dinero codigo_caja_secundaria VARCHAR(50) DEFAULT NULL');

        $this->addSql('ALTER TABLE pms_unidad
            CHANGE codigo_caja_casita codigo_caja VARCHAR(50) DEFAULT NULL');

        foreach ([
            '{{ codigo_caja_llaves }}' => '{{ keybox_main }}',
            '{{ codigo_caja_dinero }}' => '{{ keybox_sec }}',
            '{{ codigo_caja_casita }}' => '{{ safe_code }}',
        ] as $nuevo => $viejo) {
            $this->addSql(
                'UPDATE pms_guia_item SET descripcion = REPLACE(descripcion, :nuevo, :viejo) WHERE descripcion LIKE :patron',
                ['nuevo' => $nuevo, 'viejo' => $viejo, 'patron' => '%' . $nuevo . '%']
            );
        }
    }
}
