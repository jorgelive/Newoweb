<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `dias_minimos` en los medios de cobro: Western Union deja de ofrecerse cuando no da tiempo.
 *
 * ### Qué problema resuelve
 *
 * El catálogo sabía **a quién** ofrecer cada medio (`audiencia`: todos / peru / internacional)
 * pero no **cuándo**. Y Western Union tarda: a un huésped internacional que llega mañana —o que
 * ya está en la puerta— se le ofrecía igual, y es el peor tipo de respuesta, la que parece útil
 * y se descubre inútil cuando ya no queda tiempo.
 *
 * Con el campo puesto, el menú se corrige **en los tres consumidores a la vez** —el agente, la
 * app del huésped y las cotizaciones— porque los tres piden la lista a
 * `FinMedioCobroRepository::ofrecibles()`.
 *
 * ### El número
 *
 * Western Union: **2**. O sea que se ofrece mientras falten 2 días o más, y desaparece el día
 * antes y el mismo día de la llegada. El resto de medios se quedan en `null` = siempre:
 * el enlace de tarjeta, el efectivo, Yape y las transferencias sirven hasta el último minuto.
 *
 * `null` también significa «no se sabe cuándo llega» al consultar (una cotización sin fechas):
 * ahí no se esconde nada, igual que hace la audiencia cuando no sabe de dónde paga el cliente.
 */
final class Version20260828100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade dias_minimos a los medios de cobro y pone 2 en Western Union.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro ADD dias_minimos SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE fin_medio_cobro
            SET dias_minimos = 2
            WHERE tipo = 'western_union'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro DROP dias_minimos');
    }
}
