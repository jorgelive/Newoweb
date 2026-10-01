<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\MappingResult;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushMappingStrategy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El reparto de la respuesta de un push de reservas entre los ítems del lote, por posición.
 */
#[CoversClass(BookingsPushMappingStrategy::class)]
final class BookingsPushMappingStrategyTest extends TestCase
{
    public function testCadaPiezaVaASuItemConSuIdYSuMotivo(): void
    {
        $resultados = (new BookingsPushMappingStrategy())->parseResponse(
            [
                ['success' => true, 'new' => ['id' => 93628254]],
                ['success' => false, 'message' => 'general', 'errors' => [['message' => 'access denied']]],
                ['success' => true, 'bookId' => '777'],
            ],
            new MappingResult('POST', 'https://api.beds24.com/v2/bookings', [], new Beds24Config(), [0 => 'a', 1 => 'b', 2 => 'c']),
        );

        self::assertTrue($resultados['a']->success);
        self::assertSame('93628254', $resultados['a']->remoteId);
        self::assertSame(['success' => true, 'new' => ['id' => 93628254]], $resultados['a']->extraData);

        // El error detallado manda sobre el general.
        self::assertFalse($resultados['b']->success);
        self::assertSame('access denied', $resultados['b']->message);

        self::assertSame('777', $resultados['c']->remoteId);
    }

    /** Una pieza que no es un objeto no trae `success`: fallida, como con el `?? false` de antes. */
    public function testUnaPiezaQueNoEsUnObjetoEsUnFallo(): void
    {
        $resultados = (new BookingsPushMappingStrategy())->parseResponse(
            ['basura'],
            new MappingResult('POST', 'https://api.beds24.com/v2/bookings', [], new Beds24Config(), [0 => 'a']),
        );

        self::assertFalse($resultados['a']->success);
        self::assertSame('Error desconocido', $resultados['a']->message);
    }
    /**
     * Una fila sin link es una reserva de Beds24 que sobra: se le manda el estado y nada más.
     *
     * Por qué existe: Beds24 se niega a borrar reservas activas —«cannot delete active
     * bookings»—, así que retirar un duplicado (§9.7) pide cancelarlo primero. Sin este camino
     * la estrategia reventaba con «Estructura de Link incompleta/corrupta», que es la respuesta
     * correcta para una estancia nuestra y la equivocada para una que no lo es.
     */
    public function testUnaFilaSinLinkSoloMandaLaCancelacion(): void
    {
        $cola = new PmsBookingsPushQueue();
        $cola->setEndpoint((new ExchangeEndpoint())->setEndpoint('/bookings')->setMetodo('POST'));
        $cola->setBeds24BookIdOriginal('93628252');
        $cola->initializeId();

        $mapeo = (new BookingsPushMappingStrategy())->map(new HomogeneousBatch(
            (new Beds24Config())->setBaseUrl('https://api.beds24.com/v2'),
            (new ExchangeEndpoint())->setEndpoint('/bookings')->setMetodo('POST'),
            [$cola],
        ));

        self::assertSame([['id' => 93628252, 'status' => 'cancelled']], $mapeo->payload);
    }

    /**
     * La `black` de una entrada temprana: la víspera, nuestra y sin nada del canal — aunque la
     * estancia sea de Booking, cuyas fechas no tocamos nunca.
     */
    public function testLaNocheExtraDeUnaOtaSaleConSusFechasYSinNadaDelCanal(): void
    {
        [$link] = $this->linkExtra(ota: true);

        $payload = $this->mapear($link);

        self::assertSame([
            'roomId'    => 633675,
            'arrival'   => '2027-02-01',
            'departure' => '2027-02-02',
            'status'    => 'black',
            'numAdult'  => 0,
            'numChild'  => 0,
            'firstName' => 'Entrada temprana · Anna Müller',
            'comment'   => 'Noche extra de UV5XPW (PMS)',
            'custom1'   => 'PMS:' . $link->getId(),
            'custom2'   => 'EXTRA',
            'id'        => 93900001,
        ], $payload);
    }

    /** Desmarcada la casilla, la misma `black` se cancela — nunca se borra. */
    public function testDesmarcadaSaleCancelada(): void
    {
        [$link, $estancia] = $this->linkExtra(ota: false);
        $estancia->setEntradaTemprana(false);

        self::assertSame(['id' => 93900001, 'status' => 'cancelled'], $this->mapear($link));
    }

    /** Si la estancia se fue a otra casita y este link se quedó, su `black` deja de bloquear. */
    public function testEnOtraCasitaSaleCancelada(): void
    {
        [$link, $estancia] = $this->linkExtra(ota: false);
        $estancia->setPmsUnidad(new PmsUnidad());

        self::assertSame(['id' => 93900001, 'status' => 'cancelled'], $this->mapear($link));
    }

    /** @return array{PmsEventoBeds24Link, PmsEventoCalendario} */
    private function linkExtra(bool $ota): array
    {
        $casita = new PmsUnidad();
        $mapa = (new PmsUnidadBeds24Map())->setBeds24RoomId(633675)->setActivo(true);
        $casita->addBeds24Map($mapa);

        $reserva = (new PmsReserva())->setNombreCliente('Anna')->setApellidoCliente('Müller');
        $reserva->setLocalizador('UV5XPW');

        $estancia = (new PmsEventoCalendario())
            ->setPmsUnidad($casita)
            ->setReserva($reserva)
            ->setInicio(new DateTimeImmutable('2027-02-02 09:00'))
            ->setFin(new DateTimeImmutable('2027-02-05 10:00'))
            ->setEntradaTemprana(true)
            ->setIsOta($ota)
            ->setEstado(new PmsEventoEstado(PmsEventoEstado::CODIGO_CONFIRMADA));

        $link = (new PmsEventoBeds24Link())->setRol(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA)
            ->setUnidadBeds24Map($mapa)
            ->setBeds24BookId('93900001');
        $estancia->addBeds24Link($link);

        return [$link, $estancia];
    }

    /** @return array<string, mixed> */
    private function mapear(PmsEventoBeds24Link $link): array
    {
        $cola = new PmsBookingsPushQueue();
        $cola->setEndpoint((new ExchangeEndpoint())->setEndpoint('/bookings')->setMetodo('POST'));
        $cola->setLink($link);
        $cola->initializeId();

        $mapeo = (new BookingsPushMappingStrategy())->map(new HomogeneousBatch(
            (new Beds24Config())->setBaseUrl('https://api.beds24.com/v2'),
            (new ExchangeEndpoint())->setEndpoint('/bookings')->setMetodo('POST'),
            [$cola],
        ));

        self::assertCount(1, $mapeo->payload, 'el ítem no debe saltarse');

        return $mapeo->payload[0];
    }
}
