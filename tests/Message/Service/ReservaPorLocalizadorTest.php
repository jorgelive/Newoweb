<?php

declare(strict_types=1);

namespace App\Tests\Message\Service;

use App\Message\Service\Inbound\ReservaPorLocalizador;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * La credencial del primer mensaje, cuando Booking ya no manda el teléfono.
 *
 * Lo que se prueba aquí decide si un huésped que entra mañana cae en su hilo o en uno «manual»
 * sin reserva — y también que un localizador viejo o cancelado no sirva para colarse en uno.
 */
final class ReservaPorLocalizadorTest extends TestCase
{
    private function servicio(?PmsReserva $encontrada): ReservaPorLocalizador
    {
        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findOneBy')->willReturn($encontrada);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);

        return new ReservaPorLocalizador($em, new NullLogger());
    }

    /** Una reserva en curso, con una estancia que ocupa la unidad. */
    private function reserva(string $llegada, string $salida, bool $viva = true): PmsReserva
    {
        $reserva = (new PmsReserva())
            ->setFechaLlegada(new DateTimeImmutable($llegada))
            ->setFechaSalida(new DateTimeImmutable($salida));

        $estado = new PmsEventoEstado();
        $estado->setId($viva ? PmsEventoEstado::CODIGO_CONFIRMADA : 'cancelada');

        $evento = new PmsEventoCalendario();
        $evento->setEstado($estado);

        $reserva->addEventosCalendario($evento);

        return $reserva;
    }

    #[Test]
    public function reconoce_el_localizador_del_mensaje(): void
    {
        $servicio = $this->servicio($this->reserva('-1 day', '+2 days'));

        self::assertNotNull($servicio->enElTexto('Hola, soy Melanie, reserva RXY9QC'));
    }

    /**
     * ⚠️ Un mensaje sin nada que parezca un código no puede costar una consulta: por aquí pasa
     * CADA WhatsApp de un número nuevo.
     */
    #[Test]
    public function un_saludo_normal_no_casa_con_nada(): void
    {
        self::assertNull($this->servicio(null)->enElTexto('Hola buenas tardes'));
    }

    /**
     * 🔒 El filtro que impide que un localizador de hace dos años abra el hilo de nadie. La
     * ventana es de 60 días antes de la llegada a 15 después de la salida.
     */
    #[Test]
    public function un_localizador_fuera_de_fecha_no_vincula(): void
    {
        $servicio = $this->servicio($this->reserva('-2 years', '-2 years +3 days'));

        self::assertNull($servicio->enElTexto('Hola, soy alguien, reserva RXY9QC'));
    }

    /** Y una cancelada tampoco: no queda estancia que ocupe la casita. */
    #[Test]
    public function una_reserva_sin_estancia_viva_no_vincula(): void
    {
        $servicio = $this->servicio($this->reserva('-1 day', '+2 days', viva: false));

        self::assertNull($servicio->enElTexto('Hola, reserva RXY9QC'));
    }
}
