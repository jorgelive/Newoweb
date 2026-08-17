<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ExchangeRateDto;
use App\Service\TipocambioManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lo que llega de SUNAT y cómo se convierte en cotizaciones.
 *
 * ── Por qué justo esto ──────────────────────────────────────────────────────
 * La API contesta de **dos formas distintas** según se le pida un día o un mes: un objeto suelto
 * (`{fecha, compra, venta}`) o una lista de esos objetos. `callApi()` es quien iguala las dos, y
 * ese punto no tenía ninguna prueba pese a que de él cuelga el tipo de cambio de toda la
 * contabilidad: si se traga una forma mal, el sello del TC de cargos y cobros nace vacío y no se
 * nota hasta que alguien cuadra una cuenta a mano.
 *
 * Se prueban `callApi()` y `parseResponse()` por reflexión a propósito. Son privados y así deben
 * seguir —nadie de fuera tiene que llamarlos—, pero son las dos únicas piezas del servicio que se
 * pueden ejercitar sin base de datos: todo lo demás persiste o consulta. La alternativa era no
 * probar nada.
 *
 * No sale a la red: `MockHttpClient` responde lo que se le diga.
 */
final class TipocambioManagerTest extends TestCase
{
    /**
     * @param array<int, MockResponse> $respuestas
     */
    private function manager(array $respuestas): TipocambioManager
    {
        return new TipocambioManager(
            // Un stub y no un mock: los dos métodos que se prueban no tocan la base, así que no
            // hay ninguna expectativa que declarar y PHPUnit 13 avisa si se usa un mock sin ellas.
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient($respuestas),
            new NullLogger(),
            'token-de-prueba',
        );
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function llamar(TipocambioManager $manager, string $metodo, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($manager, $metodo);
        $ref->setAccessible(true);

        return $ref->invoke($manager, ...$args);
    }

    public function testLaRespuestaDeUnSoloDiaSeEnvuelveEnUnaLista(): void
    {
        $manager = $this->manager([
            new MockResponse(
                (string) json_encode(['fecha' => '2026-08-15', 'compra' => '3.520', 'venta' => '3.530']),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $filas = $this->llamar($manager, 'callApi', ['fecha' => '2026-08-15']);

        self::assertSame(
            [['fecha' => '2026-08-15', 'compra' => '3.520', 'venta' => '3.530']],
            $filas,
            'Un objeto suelto tiene que salir envuelto, o `parseResponse()` recorrería sus claves.',
        );
    }

    public function testLaRespuestaMensualSaleTalCualPeroComoLista(): void
    {
        $mes = [
            ['fecha' => '2026-08-14', 'compra' => '3.518', 'venta' => '3.528'],
            ['fecha' => '2026-08-15', 'compra' => '3.520', 'venta' => '3.530'],
        ];

        $manager = $this->manager([
            new MockResponse((string) json_encode($mes), ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        self::assertSame($mes, $this->llamar($manager, 'callApi', ['month' => '08', 'year' => '2026']));
    }

    /**
     * El `array_filter(..., 'is_array')` que se añadió al tipar el método.
     *
     * Antes las filas basura llegaban hasta `parseResponse()` y allí las descartaba el
     * `isset($item['fecha'])` —sobre un escalar es falso—. Ahora se van una casa antes. Este test
     * fija que el resultado es EL MISMO, que es lo único que importaba del cambio.
     */
    public function testLasFilasQueNoSonFilasSeDescartanYLaListaQuedaSinHuecos(): void
    {
        $manager = $this->manager([
            new MockResponse(
                (string) json_encode([
                    ['fecha' => '2026-08-14', 'compra' => '3.518', 'venta' => '3.528'],
                    'esto no es una fila',
                    ['fecha' => '2026-08-15', 'compra' => '3.520', 'venta' => '3.530'],
                ]),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $filas = $this->llamar($manager, 'callApi', ['month' => '08', 'year' => '2026']);

        self::assertCount(2, $filas);
        // Claves 0 y 1: si se hubiera usado `array_filter` a secas quedaría un hueco en la 1 y la
        // lista dejaría de ser lista, que es justo lo que el tipo `list<...>` promete que no pasa.
        self::assertSame([0, 1], array_keys($filas));
    }

    public function testUnaRespuestaConErrorNoRompeYDevuelveVacio(): void
    {
        $manager = $this->manager([new MockResponse('', ['http_code' => 503])]);

        self::assertSame([], $this->llamar($manager, 'callApi', ['fecha' => '2026-08-15']));
    }

    public function testLasCotizacionesSeIndexanPorFechaYLasIncompletasSeSaltan(): void
    {
        $manager = $this->manager([]);

        /** @var array<string, ExchangeRateDto> $dtos */
        $dtos = $this->llamar($manager, 'parseResponse', [
            ['fecha' => '2026-08-14T00:00:00', 'compra' => '3.518', 'venta' => '3.528'],
            ['fecha' => '2026-08-15', 'compra' => '3.520'],  // sin venta: se salta
            ['fecha' => '2026-08-16', 'compra' => '3.522', 'venta' => '3.532', 'moneda' => 'EUR'],
        ]);

        self::assertSame(['2026-08-14', '2026-08-16'], array_keys($dtos));

        // La fecha llega con hora y se recorta a `Y-m-d`: es la clave con la que `findBestMatch()`
        // busca después, y con la hora pegada no encontraría nunca.
        self::assertSame('2026-08-14', $dtos['2026-08-14']->date->format('Y-m-d'));
        self::assertSame('3.528', $dtos['2026-08-14']->sell);

        // La moneda se conserva tal cual viene; es `persistMonthData()` quien filtra por USD.
        self::assertSame('EUR', $dtos['2026-08-16']->currencyCode);
        self::assertSame('USD', $dtos['2026-08-14']->currencyCode, 'Sin `moneda`, se asume el target.');
    }
}
