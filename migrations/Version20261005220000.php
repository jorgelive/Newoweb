<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 6b: fuera el booleano. `calculo` es desde hoy el único campo.
 *
 * `costo_por_grupo` y `es_grupal` llevaban desde la fase 5 siendo copias derivadas que no decidían
 * nada. Borrarlas **no cambia ningún número**: se comprobó recalculando las 15 cotizaciones de
 * producción contra el código anterior a todo el plan, con diferencia cero.
 *
 * ⚠️ El `down()` las devuelve rellenas desde `calculo`, que es lo único honesto: `grupal ⇒ 1`. Una
 * `operativa` vuelve como 0 —multiplica por cantidad— y pierde su carácter oculto, porque el
 * booleano nunca supo expresarlo. Volver atrás no es gratis, y por eso se dice aquí.
 */
final class Version20261005220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fuera costo_por_grupo y es_grupal: el cálculo es el único campo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa DROP costo_por_grupo');
        $this->addSql('ALTER TABLE cotizacion_cottarifa DROP es_grupal');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa ADD costo_por_grupo TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cottarifa ADD es_grupal TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE travel_tarifa SET costo_por_grupo = (calculo = 'grupal')");
        $this->addSql("UPDATE cotizacion_cottarifa SET es_grupal = (calculo_snapshot = 'grupal')");
    }
}
