<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las reglas del hito de CREACIÓN pasan a tener al menos un minuto de desfase.
 *
 * ### El problema, que era una carrera
 *
 * Una regla `created_at` con `offset_minutes = 0` la programa `MessageRuleEngine` con
 * `runAt = now` en el **mismo `postFlush`** en que se guarda la reserva. O sea que la bienvenida
 * se pone en marcha mientras están entrando los datos de esa misma reserva, y compite con lo que
 * los arregla: la normalización del nombre y la revisión de si el canal mandó cruzados el nombre
 * y el apellido (asíncrona, porque llamar al modelo dentro del webhook lo alargaría segundos).
 *
 * Cuando la bienvenida ganaba, salía «Hola QUISPE CONTRERAS, bienvenido a Centro Cusco Inti», o
 * saludando por el apellido. Un minuto no lo nota nadie que acaba de reservar, y convierte la
 * carrera en un horario.
 *
 * Sólo se toca el hito de creación: los demás (`start`, `end`, `expected_arrival`, `cancelled`)
 * cuelgan de fechas que existían mucho antes y no compiten con nada.
 *
 * ### Por qué migración y no comando
 *
 * `msg_rule` es configuración: sin campos `#[AutoTranslate]` y sin listeners de coherencia que
 * tengan que verse. El motor relee las reglas en cada pasada —y `app:message:sync-rules` barre
 * cada 15 min—, así que un `UPDATE` directo entra solo. La regla equivalente para el futuro vive
 * en la entidad ({@see \App\Message\Entity\MessageRule::validarDesfaseDeCreacion()}), no aquí.
 *
 * Idempotente por el `WHERE`: en una segunda pasada no queda ninguna fila por debajo del mínimo.
 */
final class Version20260819100000 extends AbstractMigration
{
    /** Espejo de `MessageRule::OFFSET_MINIMO_EN_CREACION`. */
    private const int OFFSET_MINIMO = 1;

    public function getDescription(): string
    {
        return 'msg_rule: las reglas del hito «created_at» pasan a un desfase mínimo de 1 minuto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE msg_rule SET offset_minutes = :minimo, updated_at = NOW() '
            . 'WHERE milestone = :hito AND offset_minutes < :minimo',
            ['minimo' => self::OFFSET_MINIMO, 'hito' => 'created_at']
        );
    }

    /**
     * La vuelta atrás **no** devuelve el 0.
     *
     * Un `down()` que reponga el 0 reintroduce la carrera que esta migración existe para quitar,
     * y lo haría en silencio. Si de verdad hace falta volver, se cambia el desfase a mano en el
     * CRUD —donde la validación de la entidad lo va a impedir, que es justo el punto—.
     */
    public function down(Schema $schema): void
    {
        $this->write('Sin vuelta atrás: reponer el desfase 0 reintroduce la carrera con la bienvenida.');
    }
}
