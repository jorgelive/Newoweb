<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_guia_item.codigo`: la etiqueta con la que una ficha enlaza a otra.
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 * La guía no tiene enlaces internos: cuando algo se cuenta en dos sitios, se copia el texto —y
 * entonces envejece a distinta velocidad en cada copia—. El marcador `{{ ficha: calefactor }}` lo
 * arregla, y necesita una clave que aguante:
 *
 * | Candidato | Por qué no |
 * |---|---|
 * | `titulo` | traducido a siete idiomas, cambia |
 * | `nombre_interno` | se edita, y **no tiene índice único**: los 61 de hoy son distintos por disciplina |
 * | `id` | único y eterno, pero `{{ ficha: 019cfe10-… }}` no se escribe ni se revisa a mano |
 *
 * ── Único en toda la guía ───────────────────────────────────────────────────
 * Con índice, no sólo con validación: la validación protege al panel y el índice protege a todo lo
 * demás. Se decidió el código único GLOBAL —`puerta-casa-4`, no `puerta` repetido por casita—
 * porque es un caso particular del otro: si mañana hiciera falta que una ficha general enlazara a
 * «su» casita, relajarlo a «único por guía» no rompe ni un texto ya escrito. Al revés sí.
 *
 * Nace **nulable** porque las 61 filas existentes aún no lo tienen: lo rellena
 * `app:pms:guia:codigos` justo después. La entidad ya lo exige al guardar, así que ninguna ficha
 * que alguien toque se queda sin él.
 */
final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_guia_item.codigo, único, para enlazar fichas entre sí.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item ADD codigo VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_guia_item_codigo ON pms_guia_item (codigo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_guia_item_codigo ON pms_guia_item');
        $this->addSql('ALTER TABLE pms_guia_item DROP codigo');
    }
}
