<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\HoraDeLaEstancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La regla que comparten la puerta del equipo (`aplicar_cambio_horario`) y la del huésped
 * (`confirmar_hora`): qué es fuera del horario, y que apuntar una hora la deja CONFIRMADA.
 */
final class HoraDeLaEstanciaTest extends TestCase
{
    #[Test]
    public function fuera_del_horario_es_asimetrico(): void
    {
        $horas = new HoraDeLaEstancia();
        $estancia = $this->estancia();

        self::assertTrue($horas->excede($estancia, '08:00', esSalida: false), 'entrar antes del check-in');
        self::assertFalse($horas->excede($estancia, '18:00', esSalida: false), 'entrar tarde no molesta');
        self::assertTrue($horas->excede($estancia, '12:00', esSalida: true), 'salir después del check-out');
        self::assertFalse($horas->excede($estancia, '09:00', esSalida: true));
        self::assertFalse($horas->excede($estancia, '10:00', esSalida: true), 'la hora justa no excede');
    }

    /** Las 10:00 son las de todos: apuntarlas igual las deja confirmadas, y sin tocar el objeto. */
    #[Test]
    public function la_hora_por_defecto_tambien_queda_confirmada(): void
    {
        $estancia = $this->estancia();
        $fin = $estancia->getFin();
        $ahora = new DateTimeImmutable('2026-10-01 17:00');

        (new HoraDeLaEstancia())->registrar($estancia, '10:00', esSalida: true, ahora: $ahora);

        self::assertSame($fin, $estancia->getFin(), 'sin cambio para Doctrine');
        self::assertSame($ahora, $estancia->getSalidaConfirmadaAt());
        self::assertNull($estancia->getLlegadaConfirmadaAt());
    }

    #[Test]
    public function apuntar_cambia_la_hora_y_nunca_el_dia(): void
    {
        $estancia = $this->estancia();

        (new HoraDeLaEstancia())->registrar($estancia, '18:30', esSalida: false);

        self::assertSame('2027-02-02 18:30', $estancia->getInicio()?->format('Y-m-d H:i'));
        self::assertNotNull($estancia->getLlegadaConfirmadaAt());
    }

    #[Test]
    public function solo_horas_de_24h(): void
    {
        self::assertSame('09:30', HoraDeLaEstancia::normalizar(' 09:30 '));
        self::assertNull(HoraDeLaEstancia::normalizar('9:30'));
        self::assertNull(HoraDeLaEstancia::normalizar('24:00'));
    }

    private function estancia(): PmsEventoCalendario
    {
        return (new PmsEventoCalendario())
            ->setInicio(new DateTimeImmutable('2027-02-02 14:00'))
            ->setFin(new DateTimeImmutable('2027-02-05 10:00'));
    }
}
