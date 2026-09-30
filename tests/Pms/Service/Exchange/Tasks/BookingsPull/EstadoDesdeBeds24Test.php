<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `black` en Beds24 son DOS estados nuestros, y el pull tiene que saber cuál.
 *
 * Por qué existe: `bloqueo` (un cierre a mano) y `extension` (la noche de una entrada temprana o
 * salida tardía) comparten código. El pull hacía `findOneBy(['codigoBeds24' => 'black'])` y se
 * quedaba con el primero que devolviera la base: la noche de Lizbeth en la casita 1 (UV5XPW) nació
 * `extension` el 28/09/2026 a las 09:07 y a las 23:35 ya era `bloqueo`. No daba error: el mismo
 * tipo de noche acababa con dos estados según hubiera pasado o no la sincronización.
 *
 * Desde entonces el push escribe nuestro estado en `custom3` y el pull lo lee de vuelta.
 */
final class EstadoDesdeBeds24Test extends TestCase
{
    /** @return list<PmsEventoEstado> En el orden en que los devolvía la base: bloqueo primero. */
    private static function black(): array
    {
        return [new PmsEventoEstado(PmsEventoEstado::CODIGO_BLOQUEO), new PmsEventoEstado(PmsEventoEstado::CODIGO_EXTENSION)];
    }

    #[Test]
    public function custom3_decide_aunque_el_evento_diga_otra_cosa(): void
    {
        $elegido = BookingPullPersister::elegirEstado(self::black(), 'ESTADO:extension', new PmsEventoEstado(PmsEventoEstado::CODIGO_BLOQUEO));

        self::assertSame(PmsEventoEstado::CODIGO_EXTENSION, $elegido?->getId());
    }

    /** Lo empujado antes de que existiera `custom3` no lo lleva: manda lo que ya era. */
    #[Test]
    public function sin_custom3_se_conserva_el_estado_que_ya_tenia(): void
    {
        $elegido = BookingPullPersister::elegirEstado(self::black(), null, new PmsEventoEstado(PmsEventoEstado::CODIGO_EXTENSION));

        self::assertSame(PmsEventoEstado::CODIGO_EXTENSION, $elegido?->getId());
    }

    /** Un «black» que no conocemos y no dice nada es un cierre hecho a mano en Beds24. */
    #[Test]
    public function sin_custom3_y_sin_estado_previo_es_un_bloqueo(): void
    {
        $alReves = array_reverse(self::black());

        self::assertSame(PmsEventoEstado::CODIGO_BLOQUEO, BookingPullPersister::elegirEstado($alReves, null, null)?->getId(), 'no depende del orden de la base');
        self::assertSame(PmsEventoEstado::CODIGO_BLOQUEO, BookingPullPersister::elegirEstado($alReves, 'PRINCIPAL', null)?->getId(), 'un custom3 que no es nuestro no decide');
    }

    /** Un `custom3` con un estado de OTRO código no puede colarse: «black» no se vuelve confirmada. */
    #[Test]
    public function custom3_con_un_estado_que_no_es_candidato_se_ignora(): void
    {
        $elegido = BookingPullPersister::elegirEstado(self::black(), 'ESTADO:confirmada', new PmsEventoEstado(PmsEventoEstado::CODIGO_EXTENSION));

        self::assertSame(PmsEventoEstado::CODIGO_EXTENSION, $elegido?->getId());
    }

    /** Con un solo candidato no hay nada que decidir: es como antes. */
    #[Test]
    public function un_codigo_con_un_solo_estado_no_mira_custom3(): void
    {
        $confirmada = new PmsEventoEstado(PmsEventoEstado::CODIGO_CONFIRMADA);

        self::assertSame($confirmada, BookingPullPersister::elegirEstado([$confirmada], 'ESTADO:extension', null));
        self::assertNull(BookingPullPersister::elegirEstado([], 'ESTADO:extension', null));
    }
}
