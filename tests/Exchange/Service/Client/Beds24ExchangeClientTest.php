<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Service\Client;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Auth\Beds24AuthService;
use App\Exchange\Service\Client\Beds24ExchangeClient;
use App\Exchange\Service\Mapping\MappingResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La paginación del cliente de Beds24: lo único de la respuesta que no queda guardado en ninguna
 * cola —se guarda ya fusionada—, así que la prueba contra datos reales no la ve.
 */
#[CoversClass(Beds24ExchangeClient::class)]
final class Beds24ExchangeClientTest extends TestCase
{
    public function testUnGetSigueLaPaginaSiguienteYFusionaData(): void
    {
        $pedidas = [];
        $paginas = [
            ['success' => true, 'data' => [['id' => 1]], 'pages' => ['nextPageExists' => true, 'nextPageLink' => 'https://api.beds24.com/v2/bookings?arrivalFrom=2026-09-01&page=2']],
            ['success' => true, 'data' => [['id' => 2]], 'pages' => ['nextPageExists' => false, 'nextPageLink' => null]],
        ];
        $http = new MockHttpClient(static function (string $metodo, string $url) use (&$pedidas, &$paginas): MockResponse {
            $pedidas[] = $url;

            return new MockResponse((string) json_encode(array_shift($paginas)));
        });

        $resultado = $this->cliente($http)->send($this->mapeo('GET'));

        self::assertCount(2, $pedidas);
        self::assertSame('https://api.beds24.com/v2/bookings?arrivalFrom=2026-09-01&page=2', $pedidas[1]);
        self::assertSame([['id' => 1], ['id' => 2]], $resultado->decodedData['data'] ?? null);
    }

    /** La paginación es sólo de lectura: un POST no sigue enlaces aunque la respuesta los traiga. */
    public function testUnPostNoPagina(): void
    {
        $llamadas = 0;
        $http = new MockHttpClient(static function () use (&$llamadas): MockResponse {
            ++$llamadas;

            return new MockResponse('[{"success":true,"new":{"id":5}}]');
        });

        $resultado = $this->cliente($http)->send($this->mapeo('POST'));

        self::assertSame(1, $llamadas);
        self::assertSame([['success' => true, 'new' => ['id' => 5]]], $resultado->decodedData);
    }

    /** Un 504 con HTML no es JSON: se sigue con la respuesta vacía, sin página siguiente. */
    public function testUnCuerpoQueNoEsJsonDaUnaRespuestaVacia(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>504</html>', ['http_code' => 504]));

        $resultado = $this->cliente($http)->send($this->mapeo('GET'));

        self::assertSame([], $resultado->decodedData);
        self::assertSame(504, $resultado->statusCode);
    }

    private function cliente(MockHttpClient $http): Beds24ExchangeClient
    {
        // Con un token vigente en la configuración, el servicio de autenticación no llama a nadie.
        return new Beds24ExchangeClient($http, new Beds24AuthService($http, $this->createStub(EntityManagerInterface::class)), new NullLogger());
    }

    private function mapeo(string $metodo): MappingResult
    {
        $config = (new Beds24Config())
            ->setAuthToken('token-de-prueba')
            ->setAuthTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        return new MappingResult($metodo, 'https://api.beds24.com/v2/bookings?arrivalFrom=2026-09-01', [], $config, []);
    }
}
