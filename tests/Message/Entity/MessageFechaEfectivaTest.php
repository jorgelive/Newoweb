<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\Message;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Cuándo OCURRIÓ un mensaje, que es por donde el chat lo ordena y lo fecha.
 *
 * No es cosmético: con la fecha equivocada el mensaje se pinta en mitad de otra conversación,
 * por encima de cosas que pasaron después. Y el huésped, que lo recibió a su hora, no entiende
 * de qué le hablamos cuando le decimos «te escribimos a las ocho».
 */
final class MessageFechaEfectivaTest extends TestCase
{
    private function mensaje(?string $creado, ?string $programado): Message
    {
        $mensaje = new Message();

        // `createdAt` lo pone el listener de Doctrine al persistir, así que en una prueba de
        // unidad hay que ponerlo a mano: no hay setter, y tenerlo es el punto de la prueba.
        $propiedad = new ReflectionProperty(Message::class, 'createdAt');
        $propiedad->setValue($mensaje, $creado === null ? null : new DateTimeImmutable($creado));

        if ($programado !== null) {
            $mensaje->setScheduledAt(new DateTimeImmutable($programado));
        }

        return $mensaje;
    }

    /** Lo corriente: sin programación, la fecha es la de creación. */
    #[Test]
    public function un_mensaje_inmediato_ocurre_cuando_se_creo(): void
    {
        $mensaje = $this->mensaje('2026-09-21 09:17:53', null);

        self::assertSame('2026-09-21 09:17:53', $mensaje->getEffectiveDateTime()?->format('Y-m-d H:i:s'));
    }

    /**
     * 🔥 El caso que lo motivó. El motor crea el mensaje con la hora a la que la regla DEBÍA
     * dispararse; si el cron llega tarde, nace con una hora ya pasada. Con el `??` de antes esa
     * hora vieja ganaba y el mensaje se pintaba 77 minutos por encima de donde salió.
     */
    #[Test]
    public function una_programacion_ya_pasada_no_manda_sobre_la_creacion(): void
    {
        $mensaje = $this->mensaje('2026-08-22 09:17:53', '2026-08-22 08:00:00');

        self::assertSame('2026-08-22 09:17:53', $mensaje->getEffectiveDateTime()?->format('Y-m-d H:i:s'));
    }

    /**
     * Y el caso contrario sigue igual: una guía creada un mes antes de enviarse se coloca el día
     * que salió, no el día que se preparó.
     */
    #[Test]
    public function una_programacion_futura_sigue_mandando(): void
    {
        $mensaje = $this->mensaje('2026-07-10 17:07:43', '2026-08-08 11:00:00');

        self::assertSame('2026-08-08 11:00:00', $mensaje->getEffectiveDateTime()?->format('Y-m-d H:i:s'));
    }
}
