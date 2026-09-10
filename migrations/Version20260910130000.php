<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve a `read` los mensajes de HUÉSPEDES que el acuse de lectura pisó.
 *
 * 🔥 `MarkConversationReadController` marca el entrante como `read` y acto seguido lo despacha
 * para fabricar el recibo hacia la OTA. `MessageDispatcher` escribía el desenlace de ESE RECIBO
 * encima del mensaje del huésped: `failed` cuando Beds24 estaba vetado —o sea, en las reservas
 * directas— y `queued`→`sent` cuando el recibo sí salía.
 *
 * El código ya no lo hace (`MessageDispatcher::anotarDesenlace()` se abstiene con un entrante),
 * pero las filas ya escritas no se arreglan solas: en el chat siguen enseñando el icono rojo
 * sobre la pregunta de quien escribió, y cualquier consulta que filtre `received`/`read` sigue
 * sin verlas.
 *
 * ⚠️ **El discriminante es la marca de lectura, no el estado.** `metadata.beds24.read` sólo la
 * escribe ese controlador, y justo antes de despachar: su presencia en un entrante `failed`/`sent`
 * prueba que pasó por ahí. Son 22 de las 26 filas candidatas.
 *
 * ⚠️ **Las otras 4 se quedan como están, y es deliberado.** Son de `whatsapp_meta` y no llevan la
 * marca: tres de un hilo interno de marzo y una con un error propio de Meta. No vienen de este
 * camino, así que moverlas sería inventarles un desenlace.
 *
 * ⚠️ **También se limpia `dispatch_errors`**, que es lo que pinta el icono rojo en la burbuja: sin
 * quitarlo, el mensaje diría `read` y seguiría enseñándose como fallido.
 */
final class Version20260910130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devuelve a «read» los 22 mensajes de huéspedes que pisó el acuse de lectura.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message
            SET status = 'read',
                metadata = JSON_REMOVE(metadata, '$.dispatch_errors')
          WHERE direction = 'incoming'
            AND status IN ('failed', 'sent')
            AND JSON_EXTRACT(metadata, '$.beds24.read') IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Tras corregirlas son indistinguibles de las que siempre estuvieron en `read`, que son
        // miles. Revertir las movería a todas.
        $this->throwIrreversibleMigrationException(
            'Los entrantes corregidos ya no se distinguen de los sanos: revertir movería a los dos.'
        );
    }
}
