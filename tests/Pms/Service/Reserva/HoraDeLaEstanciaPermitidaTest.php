<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\HoraDeLaEstancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Desde cuándo puede entrar no es a qué hora dijo que llega (Humberto Pereda, 22/10/2026): avisó
 * que llegaba a las 20:00 y se le contestaba «entrada desde las 20:00».
 */
final class HoraDeLaEstanciaPermitidaTest extends TestCase
{
    #[Test]
    public function una_llegada_tarde_confirmada_no_mueve_el_check_in(): void
    {
        $evento = $this->estancia('2026-10-23 20:00', '2026-10-25 10:00')->setLlegadaConfirmada(true);

        self::assertSame('14:00', HoraDeLaEstancia::permitida($evento, false));
        self::assertSame('20:00', HoraDeLaEstancia::confirmada($evento, false));
    }

    #[Test]
    public function una_entrada_temprana_pactada_si_lo_adelanta(): void
    {
        $evento = $this->estancia('2026-10-23 11:00', '2026-10-25 10:00');

        self::assertSame('11:00', HoraDeLaEstancia::permitida($evento, false));
        self::assertNull(HoraDeLaEstancia::confirmada($evento, false), 'Sin confirmar no hay hora suya que decir.');
    }

    #[Test]
    public function la_salida_al_reves(): void
    {
        $temprana = $this->estancia('2026-10-23 14:00', '2026-10-25 08:00')->setSalidaConfirmada(true);
        self::assertSame('10:00', HoraDeLaEstancia::permitida($temprana, true));
        self::assertSame('08:00', HoraDeLaEstancia::confirmada($temprana, true));

        $tardia = $this->estancia('2026-10-23 14:00', '2026-10-25 13:00');
        self::assertSame('13:00', HoraDeLaEstancia::permitida($tardia, true));
    }

    private function estancia(string $inicio, string $fin): PmsEventoCalendario
    {
        return (new PmsEventoCalendario())->setInicio(new DateTimeImmutable($inicio))->setFin(new DateTimeImmutable($fin));
    }
}
