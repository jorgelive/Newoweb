<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Domótica: la frescura de un dato no es una sola fecha.
 *
 * Medido contra la nube el 08/09/2026: el mismo enchufe reportaba `switch_1` hacía un minuto y
 * `cur_power` hacía NUEVE DÍAS. La potencia se emite por excepción —sólo cuando cambia—, así que
 * su antigüedad no tiene nada que ver con la del resto del aparato y no cabe en `estado_tomado_en`.
 *
 * Y hacía falta además saber si la nube tiene contacto AHORA, porque `/status` sirve tan campante
 * los últimos valores conocidos de un aparato desconectado hace tres días, sin decirlo. El
 * `update_time` del propio endpoint de dispositivos NO sirve para eso: marcaba las 16:03 mientras
 * el aparato reportaba a las 18:14, porque describe cuándo se tocó el registro, no cuándo habló.
 * De ahí sólo es fiable `online`, que es lo que guarda `en_linea`.
 *
 * Sin estas dos columnas, el monitor enseñaría un calefactor «gastando 2.000 W» que en realidad
 * lleva tres días desenchufado — un número creíble, atribuido al presente y falso, que es la clase
 * de fallo que este módulo existe para no cometer. Ver `docs/Domotica.md` §13.2.
 *
 * Escrita a mano: el `diff` automático arrastraba tablas fantasma de otro módulo y una columna de
 * una migración ajena todavía sin aplicar.
 */
final class Version20260909000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domótica: potencia_tomada_en y en_linea — la potencia envejece a otro ritmo que el resto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo ADD potencia_tomada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE domotica_dispositivo ADD en_linea TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo DROP potencia_tomada_en');
        $this->addSql('ALTER TABLE domotica_dispositivo DROP en_linea');
    }
}
