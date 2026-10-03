<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\EstanciasDelBorde;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué casitas confirma o pregunta un botón de hora: las que salen (o entran) el mismo día que la
 * última salida (o la primera entrada). Una familia en dos casitas sale de las dos a la vez; un
 * cambio de casita a mitad de estancia no cuenta la primera.
 */
final class EstanciasDelBordeTest extends TestCase
{
    #[Test]
    public function dos_casitas_que_salen_el_mismo_dia_van_las_dos(): void
    {
        $a = $this->estancia('2026-09-28 14:00', '2026-10-03 10:00');
        $b = $this->estancia('2026-09-28 08:00', '2026-10-03 10:00');

        self::assertSame([$a, $b], EstanciasDelBorde::de([$a, $b], true));
    }

    #[Test]
    public function con_cambio_de_casita_sale_solo_la_ultima_y_entra_solo_la_primera(): void
    {
        $primera = $this->estancia('2026-10-01 14:00', '2026-10-04 10:00');
        $segunda = $this->estancia('2026-10-04 14:00', '2026-10-07 10:00');

        self::assertSame([$segunda], EstanciasDelBorde::de([$primera, $segunda], true));
        self::assertSame([$primera], EstanciasDelBorde::de([$primera, $segunda], false));
    }

    #[Test]
    public function sin_estancias_no_hay_nada(): void
    {
        self::assertSame([], EstanciasDelBorde::de([], true));
    }

    private function estancia(string $inicio, string $fin): PmsEventoCalendario
    {
        return (new PmsEventoCalendario())->setInicio(new DateTimeImmutable($inicio))->setFin(new DateTimeImmutable($fin));
    }
}
