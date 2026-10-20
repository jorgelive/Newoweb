<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los cargos MANUALES pierden el candado (`fijado_por_operador`).
 *
 * El candado sólo frena a la sincronización de Beds24, y un cargo manual no tiene `beds24_item_id`:
 * nadie lo sobrescribe. Se lo ponía el formulario al cambiarle la estancia, y el panel lo enseñaba
 * como «Fijado» sin que protegiera nada. Desde el 20/10/2026 `PmsCargoFinanciero::setFijadoPorOperador()`
 * no lo deja poner; esto limpia los que ya lo tenían (2 en producción).
 *
 * Por SQL: el candado no entra en ningún total, así que los listeners de coherencia no tienen
 * nada que recalcular.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_cargo_financiero: sin candado en los cargos manuales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE pms_cargo_financiero SET fijado_por_operador = 0 WHERE fijado_por_operador = 1 AND beds24_item_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        // No se puede saber cuáles lo tenían, y no protegía nada.
    }
}
