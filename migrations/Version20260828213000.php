<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `dias_maximos` en los medios de cobro: el efectivo sólo cuando el huésped ya está aquí.
 *
 * ### Qué problema resuelve
 *
 * Se le ofrecía **efectivo** a alguien que todavía no ha llegado. Sale en el resumen de pago
 * junto a Yape o Western Union, y no significa nada: no se puede pagar en mano desde otro
 * sitio. El adelanto que se pide semanas antes es justo el caso donde más se veía.
 *
 * ### ⚠️ No es una regla de audiencia, es de PRESENCIA
 *
 * La tentación era resolverlo con `FinAudienciaCobro`, que ya separa Perú de fuera. **Está
 * mal**: un peruano en Puno tampoco puede pagar efectivo en Cusco. Lo que decide no es de
 * dónde es la persona sino **dónde está**, y eso lo aproxima el calendario — el día de la
 * llegada y los siguientes.
 *
 * Por eso la columna es el espejo de `dias_minimos` y no un flag nuevo: entre las dos forman
 * una VENTANA. Western Union necesita antelación (`min 2`), el efectivo necesita lo
 * contrario (`max 0`), y la mayoría de medios no necesita ninguna de las dos.
 *
 * `max 0` deja pasar el día de la llegada **y toda la estancia**, porque los días van con
 * signo y son negativos una vez dentro. Es justo lo que se quiere para el saldo que se paga
 * en el alojamiento.
 *
 * ### Por qué migración y no comando
 *
 * `FinMedioCobro` tiene un campo traducible (`nota`), pero aquí no se toca: se escribe un
 * `smallint` que **ningún listener recalcula** y que no dispara traducción. Entra por SQL,
 * como el `dias_minimos` de Western Union que ya se cargó así.
 */
final class Version20260828213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade dias_maximos a los medios de cobro y pone 0 en efectivo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro ADD dias_maximos SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE fin_medio_cobro
            SET dias_maximos = 0
            WHERE tipo = 'efectivo'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro DROP dias_maximos');
    }
}
