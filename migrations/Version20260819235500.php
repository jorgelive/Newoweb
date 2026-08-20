<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repara los enlaces de Beds24 que se guardaron SIN host.
 *
 * Cuando el huésped adjunta un archivo por Booking.com, la API v2 no manda el archivo: manda un
 * ancla cuyo `href` es relativo a beds24.com (`api/booking.com/getattach.php?…`). Guardado tal
 * cual, el panel lo resuelve contra su propio dominio y el operador cae en un 404 de
 * `panel.openperu.pe/api/booking.com/…`. Son 92 mensajes: 92 adjuntos que nadie pudo abrir.
 *
 * De aquí en adelante lo arregla {@see \App\Message\Service\Exchange\Tasks\Beds24Receive\EnlaceDeBeds24}
 * al entrar; esto es sólo el arrastre de lo ya almacenado.
 *
 * ⚠️ **Va por SQL y no por el ORM a propósito.** `Message` tiene listeners de `preUpdate` y
 * `postUpdate` —traductor, auto-respondedor, Mercure y el ENCOLADOR DE ENVÍO—. Tocar 92 mensajes
 * históricos por el ORM podría despertar el encolador y re-enviárselos a los huéspedes. Aquí no
 * hay nada que ningún listener deba recalcular: se corrige una dirección dentro de un texto.
 *
 * Idempotente: tras la primera pasada ya no queda ningún `href="api/`, así que repetirla no
 * anida el dominio dentro de sí mismo.
 */
final class Version20260819235500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Absolutiza los enlaces relativos de Beds24 en los mensajes ya guardados (adjuntos de Booking.com).';
    }

    public function up(Schema $schema): void
    {
        foreach (['content_external', 'content_local'] as $columna) {
            // Las dos formas de comilla, que Beds24 no promete cuál usa.
            $this->addSql(sprintf(
                'UPDATE msg_message SET %1$s = REPLACE(%1$s, \'href="api/\', \'href="https://beds24.com/api/\') '
                . 'WHERE %1$s LIKE \'%%href="api/%%\'',
                $columna
            ));

            $this->addSql(sprintf(
                'UPDATE msg_message SET %1$s = REPLACE(%1$s, "href=\'api/", "href=\'https://beds24.com/api/") '
                . 'WHERE %1$s LIKE "%%href=\'api/%%"',
                $columna
            ));
        }
    }

    /**
     * Se puede deshacer, pero deja el enlace inservible otra vez. Está aquí por higiene, no
     * porque convenga correrlo.
     */
    public function down(Schema $schema): void
    {
        foreach (['content_external', 'content_local'] as $columna) {
            $this->addSql(sprintf(
                'UPDATE msg_message SET %1$s = REPLACE(%1$s, \'href="https://beds24.com/api/\', \'href="api/\') '
                . 'WHERE %1$s LIKE \'%%href="https://beds24.com/api/%%\'',
                $columna
            ));
        }
    }
}
