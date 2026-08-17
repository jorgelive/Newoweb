<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las capacidades del aparato nacen apagadas.
 *
 * `mide_consumo` y `conmutable` se crearon con `DEFAULT 1` en Version20260815005759, y para un
 * campo **derivado** eso está mal: un dispositivo dado de alta antes de sincronizar afirmaría que
 * mide sin que nadie lo haya comprobado, y entraría directo al muestreo y a las alertas.
 *
 * Las capacidades no las teclea nadie: las reporta el propio aparato en
 * `/v1.0/devices/{id}/specifications` — si expone `add_ele` mide, si expone `switch_1` conmuta.
 * Hasta que esa consulta ocurra, lo honesto es «no sé», y «no sé» tiene que comportarse como «no».
 *
 * ## Por qué aquí la asimetría se invierte
 *
 * En la guía vale lo contrario —lista vacía es «sin acotar», porque un ítem de más es inofensivo y
 * uno invisible no se descubre nunca—. Aquí no: un `true` equivocado hace que el muestreo pida
 * lecturas a un detector de gas y que el vigilante avise cada tres horas de un aparato que nunca
 * tuvo contómetro. Un `false` equivocado sólo significa que ese aparato no se muestrea hasta que
 * alguien sincronice.
 *
 * Fallar callado, no fallar a gritos.
 *
 * No toca datos: las tres tablas están vacías en producción y en desarrollo. Si algún día hubiera
 * filas, el `UPDATE` de abajo las devolvería a «sin comprobar», que es exactamente lo correcto —
 * la sincronización las vuelve a encender.
 */
final class Version20260815020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domótica: mide_consumo y conmutable pasan a DEFAULT 0 (son derivados, no tecleados).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo CHANGE mide_consumo mide_consumo TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE domotica_dispositivo CHANGE conmutable conmutable TINYINT(1) DEFAULT 0 NOT NULL');

        // Lo ya existente vuelve a «sin comprobar»: nadie ha mirado todavía la especificación de
        // esos aparatos, así que su `1` era una suposición, no un dato.
        $this->addSql('UPDATE domotica_dispositivo SET mide_consumo = 0, conmutable = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo CHANGE mide_consumo mide_consumo TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE domotica_dispositivo CHANGE conmutable conmutable TINYINT(1) DEFAULT 1 NOT NULL');
    }
}
