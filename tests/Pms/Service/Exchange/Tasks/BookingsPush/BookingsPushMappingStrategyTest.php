<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\MappingResult;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushMappingStrategy;
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
}
