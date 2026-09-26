<?php

declare(strict_types=1);

namespace App\Tests\Finanzas;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Service\Culqi\CulqiClient;
use ErrorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Un cobro manual SUELTO —sin módulo, `origenTipo` nulo— se cobra sin avisos.
 *
 * 🔥 Con `$enlace->getOrigenTipo()->value` cada cobro suelto dejaba en producción un «Attempt to
 * read property "value" on null» (26/08, 31/08 y 06/09/2026). Es un warning, no un error, así que
 * el cobro salía igual y nadie lo veía: el metadato viajaba como `null` de casualidad. Lo cazó la
 * subida a PHPStan nivel 8. El test convierte los warnings en excepción, que es como se vería si
 * alguien endureciera el manejo de errores.
 */
#[CoversClass(CulqiClient::class)]
final class CobroSueltoSinOrigenTest extends TestCase
{
    public function testUnCobroSinModuloMandaElOrigenNuloSinAvisos(): void
    {
        $enviado = null;
        $http = new MockHttpClient(static function (string $metodo, string $url, array $opciones) use (&$enviado): MockResponse {
            $enviado = json_decode((string) ($opciones['body'] ?? ''), true);

            return new MockResponse((string) json_encode(['object' => 'charge', 'id' => 'chr_test']), ['http_code' => 201]);
        });

        $culqi = new CulqiClient($http, new NullLogger(), 'https://api.culqi.test', 'pk_live_x', 'sk_live_x', 'prod');

        $enlace = (new FinEnlacePago())
            ->setOrigenTipo(null)
            ->setConcepto('Depósito de garantía')
            ->setMontoNeto('100.00')
            ->setMontoTotal('105.50');

        set_error_handler(static function (int $nivel, string $mensaje): never {
            throw new ErrorException($mensaje, 0, $nivel);
        });

        try {
            $culqi->cobrarConToken($enlace, 'tkn_test');
        } finally {
            restore_error_handler();
        }

        self::assertIsArray($enviado);
        self::assertArrayHasKey('metadata', $enviado);
        self::assertNull($enviado['metadata']['origenTipo']);
    }
}
