<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los cargos financieros ganan el flag de sobrescritura de traducciones.
 *
 * Lo trae `AutoTranslateControlTrait`, que le faltaba a PmsCargoFinanciero: sin él
 * `AutoTranslationService::processEntity()` salía antes de mirar el `#[AutoTranslate]` de
 * `descripcion_cliente` y ese campo NUNCA se traducía. La columna es la parte física del
 * trait (el otro flag, `ejecutarTraduccion`, es virtual y no se mapea).
 *
 * DEFAULT 0 = «modo seguro»: se rellenan los idiomas vacíos y se respetan los que ya tienen
 * texto. Por eso las filas existentes no necesitan backfill.
 *
 * Ver docs/PmsBeds24ReservasSync.md §12.5.6.
 */
final class Version20260812150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_cargo_financiero: columna sobreescribir_traduccion (AutoTranslateControlTrait), que desbloquea la autotraducción de descripcion_cliente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP sobreescribir_traduccion');
    }
}
