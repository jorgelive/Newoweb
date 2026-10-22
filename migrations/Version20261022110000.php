<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pago_efectivo_caja` nunca tuvo encendido el canal de Beds24 (encontrado el 22/10/2026).
 *
 * La migración que la creó (`Version20260913200000`) escribió un cuerpo PARA las OTA —con su
 * porqué: el chat de Booking no deja adjuntar la foto del dinero— pero lo envolvió sin
 * `is_active`, y sin la marca el canal cuenta como apagado (`MessageTemplate::isBeds24Active()`).
 * Resultado: a un huésped de Booking o Airbnb no le llegaba por su chat. Sus hermanas
 * (`llave_emergencia`, `llaves_interiores`) lo tienen encendido. Ningún mensaje la había usado.
 *
 * Por SQL: es una marca, no texto, y no la recalcula ningún listener.
 */
final class Version20261022110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_template pago_efectivo_caja: enciende Beds24 y el enlace de WhatsApp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template
            SET beds24_tmpl = JSON_SET(beds24_tmpl, '$.is_active', CAST('true' AS JSON)),
                whatsapp_link_tmpl = JSON_SET(whatsapp_link_tmpl, '$.is_active', CAST('true' AS JSON))
            WHERE code = 'pago_efectivo_caja'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template
            SET beds24_tmpl = JSON_REMOVE(beds24_tmpl, '$.is_active'),
                whatsapp_link_tmpl = JSON_REMOVE(whatsapp_link_tmpl, '$.is_active')
            WHERE code = 'pago_efectivo_caja'");
    }
}
