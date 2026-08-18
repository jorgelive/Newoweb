<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingsPullMappingStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La query del pull de reservas: que los estados viajen como parámetro REPETIDO.
 *
 * Por qué existe este test: el `status` se pasaba dentro del `payload`, y el cliente lo entrega
 * a Symfony como `'query' => $payload`. `http_build_query` serializa un array indexado como
 * `status[0]=confirmed`, forma que Beds24 NO reconoce como parámetro repetido. Al no ver ningún
 * `status` válido, aplicaba su filtro por defecto —que EXCLUYE las canceladas—, así que una
 * cancelación cuyo webhook se perdiera no la recuperaba nunca este cron.
 *
 * El fallo no se veía en el código: el `status` con `cancelled` estaba escrito y era correcto.
 * Se perdía al serializar. De ahí que lo que se comprueba aquí sea la URL FINAL, no el payload.
 */
final class BookingsPullMappingStrategyTest extends TestCase
{
    private function batch(?\DateTimeImmutable $hasta = null): HomogeneousBatch
    {
        $config = (new Beds24Config())->setBaseUrl('https://beds24.com/api/v2');

        $endpoint = (new ExchangeEndpoint())
            ->setEndpoint('/bookings')
            ->setMetodo('GET');

        $job = new PmsBookingsPullQueue();
        $job->setArrivalFrom(new \DateTimeImmutable('2026-03-10'));
        if ($hasta !== null) {
            $job->setArrivalTo($hasta);
        }

        return new HomogeneousBatch($config, $endpoint, [$job]);
    }

    /** `status` repetido, nunca `status[0]=`: es la forma que Beds24 sí interpreta. */
    #[Test]
    public function los_estados_viajan_como_parametro_repetido(): void
    {
        $url = (new BookingsPullMappingStrategy())->map($this->batch())->fullUrl;

        self::assertStringNotContainsString('status[0]', $url, 'la forma que Beds24 ignora');
        self::assertStringNotContainsString('status%5B0%5D', $url, 'idem, ya escapada');

        foreach (['confirmed', 'new', 'request', 'cancelled', 'black', 'inquiry'] as $estado) {
            self::assertStringContainsString('status=' . $estado, $url, "falta el estado $estado");
        }
    }

    /**
     * `cancelled` es el motivo de ser de este pull: es la red que recoge lo que el webhook
     * perdió, y una cancelación no avisada es justo el caso que hay que recuperar.
     */
    #[Test]
    public function las_canceladas_se_piden_siempre(): void
    {
        $url = (new BookingsPullMappingStrategy())->map($this->batch())->fullUrl;

        self::assertStringContainsString('status=cancelled', $url);
    }

    /** La ventana de llegada y los `include` siguen yendo, y el payload queda vacío. */
    #[Test]
    public function la_ventana_va_en_la_url_y_el_payload_queda_vacio(): void
    {
        $resultado = (new BookingsPullMappingStrategy())->map($this->batch(new \DateTimeImmutable('2026-03-24')));

        self::assertStringContainsString('arrivalFrom=2026-03-10', $resultado->fullUrl);
        self::assertStringContainsString('arrivalTo=2026-03-24', $resultado->fullUrl);
        self::assertStringContainsString('includeInfoItems=true', $resultado->fullUrl);
        self::assertStringContainsString('includeGuests=true', $resultado->fullUrl);

        // Vacío a propósito: si el payload volviera a llevar la query, Symfony la re-serializaría
        // y reaparecería el `status[0]=` que este arreglo vino a quitar.
        self::assertSame([], $resultado->payload, 'la query entera va en la URL');
    }

    /**
     * Aquí se piden RESERVAS, no cargos: los `invoiceItems` tienen su propia vía
     * (`GET /bookings/invoices`, Camino D). Pedirlos aquí sería engordar la respuesta con datos
     * que este handler ni mira.
     */
    #[Test]
    public function los_cargos_no_se_piden_en_el_pull_de_reservas(): void
    {
        $url = (new BookingsPullMappingStrategy())->map($this->batch())->fullUrl;

        self::assertStringNotContainsString('includeInvoice', $url);
    }

    /** Sin `arrivalTo` no se inventa el parámetro: el job puede no tener tope. */
    #[Test]
    public function sin_arrival_to_no_se_manda_el_parametro(): void
    {
        $url = (new BookingsPullMappingStrategy())->map($this->batch())->fullUrl;

        self::assertStringNotContainsString('arrivalTo=', $url);
    }
}
