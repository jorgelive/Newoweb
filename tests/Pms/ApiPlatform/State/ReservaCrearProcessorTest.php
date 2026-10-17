<?php

declare(strict_types=1);

namespace App\Tests\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Post;
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\ApiPlatform\Dto\PmsReservaCrearInput;
use App\Pms\ApiPlatform\State\PmsReservaCrearProcessor;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La reserva nace en el establecimiento de SU casita, y una fecha sola lleva las horas de él.
 *
 * Antes tomaba «el primer establecimiento» (`findOneBy([])`), lo mismo mientras haya uno solo, y
 * una fecha `Y-m-d` nacía a las 00:00 en vez de a la hora de entrada.
 */
final class ReservaCrearProcessorTest extends TestCase
{
    #[Test]
    public function usa_el_establecimiento_y_las_horas_de_la_casita(): void
    {
        $establecimiento = (new PmsEstablecimiento())
            ->setHoraCheckIn(new DateTimeImmutable('15:00'))
            ->setHoraCheckOut(new DateTimeImmutable('11:00'));
        $casita = (new PmsUnidad())->setEstablecimiento($establecimiento);

        $input = new PmsReservaCrearInput();
        $input->pmsUnidad = $casita;
        $input->inicio = '2026-11-10';
        $input->fin = '2026-11-12';

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturnCallback(static fn (string $clase) => match ($clase) {
            PmsChannel::class => new PmsChannel(PmsChannel::CODIGO_DIRECTO),
            MaestroIdioma::class => new MaestroIdioma('es', 'Español'),
            PmsEventoEstado::class => new PmsEventoEstado(PmsEventoEstado::CODIGO_PENDIENTE),
            default => new PmsEventoEstadoPago(),
        });

        $reserva = (new PmsReservaCrearProcessor($em, new PmsEventoCalendarioFactory($em)))->process($input, new Post());

        self::assertSame($establecimiento, $reserva->getEstablecimiento());
        $estancia = $reserva->getEventosCalendario()->first();
        self::assertNotFalse($estancia);
        self::assertSame('2026-11-10 15:00', $estancia->getInicio()?->format('Y-m-d H:i'));
        self::assertSame('2026-11-12 11:00', $estancia->getFin()?->format('Y-m-d H:i'));
    }
}
