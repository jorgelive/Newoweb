<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «No tenía teléfono» y «no tenía correo» dejan de figurar como fallidos.
 *
 * Es la segunda vuelta de `Version20260914180000`, con los dos motivos que aquélla no vio porque
 * entonces los encoladores LANZABAN: `WhatsappMetaSendEnqueuer` sin teléfono y
 * `EmailSendEnqueuer` sin dirección. Desde el 26/09/2026 devuelven `null` —«este canal no
 * aplica»— y el mensaje queda en `sin_canal`, que el motor revive cuando el dato llega.
 *
 * Medido antes de tocar: **3** filas, las tres «sin teléfono». Dos son de Melanie (guía de
 * llegada del 12/11 y check-out del 14/11): su hilo se fusionó el 26/09 con el de su número y
 * seguían muertas. La tercera es pasada y se queda como historia: el motor sólo revive lo que
 * todavía no ha ocurrido.
 *
 * Por SQL como la de hace doce días: es una etiqueta, nada escucha a este cambio de estado. Lo
 * que despierta a los dos de Melanie es la pasada siguiente del motor sobre su hilo.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reclasifica a «sin_canal» los fallidos por falta de teléfono o de correo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message
                          SET status = 'sin_canal'
                        WHERE status = 'failed'
                          AND direction = 'outgoing'
                          AND (metadata LIKE '%No se pudo resolver el número de teléfono%'
                               OR metadata LIKE '%No hay un correo al que escribir%')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message
                          SET status = 'failed'
                        WHERE status = 'sin_canal'
                          AND (metadata LIKE '%No se pudo resolver el número de teléfono%'
                               OR metadata LIKE '%No hay un correo al que escribir%')");
    }
}
