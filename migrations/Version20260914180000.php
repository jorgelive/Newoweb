<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los mensajes que nunca tuvieron canal dejan de figurar como fallidos.
 *
 * ── Lo que había ───────────────────────────────────────────────────────────
 * `failed` mezclaba dos cosas que no piden lo mismo: «se intentó y se rompió» y «ningún canal
 * aplicaba a esta reserva». Medido antes de tocar nada, de **395** mensajes en `failed`:
 *
 * | | cuántos |
 * |---|---|
 * | ningún canal viable (todos los encoladores declinaron) | 249 |
 * | reserva **directa**, que Beds24 rechaza por diseño | 106 |
 * | ventana de 24 h cerrada | 13 |
 * | ya salió por Beds24 y falló el otro canal | 5 |
 * | errores de verdad | 22 |
 *
 * 🔥 **El daño no era la etiqueta, era el ruido.** Con 395 filas en rojo un fallo real no se
 * distingue, y `AvisoEnvioFallidoListener` sólo avisa de los mensajes del equipo: nadie iba a
 * leerlas nunca. Es la familia de fallo que persigue este proyecto — el que no se ve.
 *
 * ⚠️ **No se borra nada ni se reintenta nada**: sólo se reetiqueta lo que ya estaba decidido. El
 * mensaje sigue vivo y `preUpdate` vuelve a pedir colas, así que si el canal aparece después
 * —se añade el teléfono, se vincula la reserva a Beds24— sale sin más.
 *
 * Los dos criterios son los que dejó escritos el propio despachador en `metadata`, así que un
 * `failed` de verdad no casa con ninguno y se queda como está.
 */
final class Version20260914180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reclasifica a «sin_canal» los mensajes que nunca tuvieron por dónde salir.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message
                          SET status = 'sin_canal'
                        WHERE status = 'failed'
                          AND direction = 'outgoing'
                          AND (metadata LIKE '%No se pudo generar ninguna cola%'
                               OR metadata LIKE '%reservas directas%'
                               OR metadata LIKE '%Ningún canal disponible para este mensaje%')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message SET status = 'failed' WHERE status = 'sin_canal'");
    }
}
