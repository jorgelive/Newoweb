<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Agent;

use App\Agent\Access\RestriccionCanal;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Agent\EnlaceDeCasita;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué enlace de una casita se da según por dónde escribe quien pregunta.
 *
 * El caso que no puede fallar es el de la consulta de OTA sin anuncio de su plataforma: ahí un
 * enlace a nuestra web es sacar la venta de la plataforma, y lo único correcto es ninguno.
 */
final class EnlaceDeCasitaTest extends TestCase
{
    private const string AIRBNB = 'https://www.airbnb.com/rooms/123';
    private const string PUBLICA = 'https://pax.test/centro-cusco-inti/centro-cusco-inti-casita-1';

    #[Test]
    public function desde_airbnb_con_anuncio_da_el_anuncio(): void
    {
        self::assertSame(self::AIRBNB, $this->enlace('airbnb', RestriccionCanal::OtaPreReserva, self::AIRBNB));
    }

    #[Test]
    public function consulta_de_airbnb_sin_anuncio_no_da_nada(): void
    {
        self::assertNull($this->enlace('airbnb', RestriccionCanal::OtaPreReserva, null));
    }

    #[Test]
    public function consulta_de_booking_no_da_nada_aunque_haya_anuncio_de_airbnb(): void
    {
        // Mandarle el anuncio de Airbnb a quien consulta por Booking sería llevarle a otra
        // plataforma, que es lo mismo que llevarle a nuestra web.
        self::assertNull($this->enlace('booking', RestriccionCanal::OtaPreReserva, self::AIRBNB));
    }

    #[Test]
    public function el_prospecto_recibe_la_pagina_publica(): void
    {
        self::assertSame(self::PUBLICA, $this->enlace(null, RestriccionCanal::Ninguna, self::AIRBNB));
    }

    #[Test]
    public function una_reserva_de_booking_confirmada_recibe_la_pagina_publica(): void
    {
        self::assertSame(self::PUBLICA, $this->enlace('booking', RestriccionCanal::Ninguna, null));
    }

    private function enlace(?string $plataforma, RestriccionCanal $restriccion, ?string $anuncio): ?string
    {
        $establecimiento = (new PmsEstablecimiento())->setSlug('centro-cusco-inti');
        $unidad = (new PmsUnidad())
            ->setEstablecimiento($establecimiento)
            ->setSlug('centro-cusco-inti-casita-1')
            ->setUrlAnuncioAirbnb($anuncio);

        return (new EnlaceDeCasita('https://pax.test/'))->para($unidad, $plataforma, $restriccion);
    }
}
