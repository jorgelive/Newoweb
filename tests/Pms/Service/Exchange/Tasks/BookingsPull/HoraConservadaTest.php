<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El pull no borra la hora que puso el operador: Beds24 sólo sabe de días.
 *
 * Por qué existe: la entrada a las 08:00 de Lizbeth (KXET9H) volvía a las 14:00 en cuanto su
 * booking pasaba por el pull, y lo mismo le habría pasado a cualquier salida tardía a las 17:00.
 */
final class HoraConservadaTest extends TestCase
{
    #[Test]
    public function mismo_dia_se_queda_la_hora_pactada_y_el_mismo_objeto(): void
    {
        $pactada = new DateTime('2026-09-28 08:00');

        $resultado = BookingPullPersister::horaConservada($pactada, new DateTimeImmutable('2026-09-28 14:00'));

        self::assertSame($pactada, $resultado, 'el mismo objeto: Doctrine no ve cambio');
    }

    #[Test]
    public function otro_dia_manda_el_canal_con_su_hora_por_defecto(): void
    {
        $resultado = BookingPullPersister::horaConservada(new DateTime('2026-09-28 08:00'), new DateTimeImmutable('2026-09-30 14:00'));

        self::assertSame('2026-09-30 14:00', $resultado?->format('Y-m-d H:i'));
    }

    #[Test]
    public function sin_hora_previa_vale_la_del_canal(): void
    {
        self::assertSame('2026-09-28 14:00', BookingPullPersister::horaConservada(null, new DateTimeImmutable('2026-09-28 14:00'))?->format('Y-m-d H:i'));
        self::assertNull(BookingPullPersister::horaConservada(new DateTime('2026-09-28 08:00'), null));
    }
}
