<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los estados de evento ganan un icono propio.
 *
 * El maestro sólo tenía `color`, y un `#FFB300` no dice nada mirándolo: ni en el CRUD del
 * panel, ni en el calendario, donde el estado había que deducirlo del tono de la barra. El
 * icono lo dice de un vistazo.
 *
 * **Va como DATO y no como `match()` en PHP** a propósito: un estado nuevo que alguien dé de
 * alta mañana llega con su icono sin tocar código ni recompilar el front. El mismo valor lo
 * consumen el CRUD, `PmsEventosSpaCalendarProvider` y el calendario de `util`.
 *
 * La familia elegida es `circle-*` para los estados de una reserva VIVA —se leen como una
 * gradación del mismo hecho— y un glifo inequívoco para los terminales:
 *
 * | Estado | Icono | Por qué |
 * |---|---|---|
 * | `confirmada` | `fa-circle-check` | ✓ cerrado, no hay nada que hacer |
 * | `pendiente` | `fa-circle-minus` | − ni sí ni no todavía |
 * | `requerimiento` | `fa-circle-question` | ? el canal pide respuesta |
 * | `abierto` | `fa-circle-dot` | · una consulta, aún no es reserva |
 * | `cancelada` | `fa-circle-xmark` | ✗ terminal |
 * | `bloqueo` | `fa-ban` | la casita está cerrada, no es una venta |
 * | `extension` | `fa-circle-half-stroke` | media noche fantasma (§7.1.b) |
 *
 * El `UPDATE` va por `id` uno a uno y no con un `CASE`: son siete filas de un maestro y así
 * cada línea se lee sola. Sólo escribe donde `icono IS NULL`, así que es reejecutable y no
 * pisa lo que alguien haya cambiado a mano desde el panel.
 */
final class Version20260811120000 extends AbstractMigration
{
    /**
     * Icono por estado. La clave es el ID natural del maestro.
     *
     * Un estado que no esté aquí se queda sin icono, y es lo correcto: preferimos un hueco a
     * inventarle un glifo que signifique otra cosa.
     */
    private const array ICONOS = [
        'confirmada'    => 'fas fa-circle-check',
        'pendiente'     => 'fas fa-circle-minus',
        'requerimiento' => 'fas fa-circle-question',
        'abierto'       => 'fas fa-circle-dot',
        'cancelada'     => 'fas fa-circle-xmark',
        'bloqueo'       => 'fas fa-ban',
        'extension'     => 'fas fa-circle-half-stroke',
    ];

    public function getDescription(): string
    {
        return 'pms_evento_estado.icono + iconos por defecto de cada estado';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_estado ADD icono VARCHAR(50) DEFAULT NULL');

        foreach (self::ICONOS as $id => $icono) {
            // `icono IS NULL` hace la carga reejecutable y respeta cualquier ajuste manual.
            $this->addSql(
                'UPDATE pms_evento_estado SET icono = :icono WHERE id = :id AND icono IS NULL',
                ['icono' => $icono, 'id' => $id],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_estado DROP icono');
    }
}
