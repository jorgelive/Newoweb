<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ExchangeRateDto;
use App\Service\TipocambioManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lo que llega del proveedor del tipo de cambio y cómo se convierte en cotizaciones.
 *
 * ── Por qué justo esto ──────────────────────────────────────────────────────
 * La API contesta de **dos formas distintas** según se le pida un día o un mes: un objeto suelto
 * (`{date, buy_price, sell_price}`) o una lista de esos objetos. `callApi()` es quien iguala las dos, y
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
 *
 * ⚠️ **Estos tests ganaron su sueldo el 10/09/2026.** Al migrar de apis.net.pe a decolecta.com
 * cambió la URL y, sin avisar, **los nombres de los campos**: `{fecha, compra, venta}` pasó a
 * `{date, buy_price, sell_price}`. Cambiar sólo la URL habría dejado `parseResponse()`
 * descartando todas las filas en su `isset()` y devolviendo vacío — el mismo síntoma exacto que
 * el proveedor caído, con la API funcionando perfectamente. Salieron dos rojos aquí antes de que
 * eso llegara a producción.
 */
final class TipocambioManagerTest extends TestCase
{
    /**
     * @param array<int, MockResponse> $respuestas
     */
    private function manager(array $respuestas, ?LoggerInterface $logger = null): TipocambioManager
    {
        return new TipocambioManager(
            // Un stub y no un mock: los dos métodos que se prueban no tocan la base, así que no
            // hay ninguna expectativa que declarar y PHPUnit 13 avisa si se usa un mock sin ellas.
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient($respuestas),
            $logger ?? new NullLogger(),
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
                (string) json_encode(['date' => '2026-08-15', 'buy_price' => '3.520', 'sell_price' => '3.530']),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $filas = $this->llamar($manager, 'callApi', ['date' => '2026-08-15']);

        self::assertSame(
            [['date' => '2026-08-15', 'buy_price' => '3.520', 'sell_price' => '3.530']],
            $filas,
            'Un objeto suelto tiene que salir envuelto, o `parseResponse()` recorrería sus claves.',
        );
    }

    public function testLaRespuestaMensualSaleTalCualPeroComoLista(): void
    {
        $mes = [
            ['date' => '2026-08-14', 'buy_price' => '3.518', 'sell_price' => '3.528'],
            ['date' => '2026-08-15', 'buy_price' => '3.520', 'sell_price' => '3.530'],
        ];

        $manager = $this->manager([
            new MockResponse((string) json_encode($mes), ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        self::assertSame($mes, $this->llamar($manager, 'callApi', ['month' => '8', 'year' => '2026']));
    }

    /**
     * El `array_filter(..., 'is_array')` que se añadió al tipar el método.
     *
     * Antes las filas basura llegaban hasta `parseResponse()` y allí las descartaba el
     * `isset()` —sobre un escalar es falso—. Ahora se van una casa antes. Este test
     * fija que el resultado es EL MISMO, que es lo único que importaba del cambio.
     */
    public function testLasFilasQueNoSonFilasSeDescartanYLaListaQuedaSinHuecos(): void
    {
        $manager = $this->manager([
            new MockResponse(
                (string) json_encode([
                    ['date' => '2026-08-14', 'buy_price' => '3.518', 'sell_price' => '3.528'],
                    'esto no es una fila',
                    ['date' => '2026-08-15', 'buy_price' => '3.520', 'sell_price' => '3.530'],
                ]),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $filas = $this->llamar($manager, 'callApi', ['month' => '8', 'year' => '2026']);

        self::assertCount(2, $filas);
        // Claves 0 y 1: si se hubiera usado `array_filter` a secas quedaría un hueco en la 1 y la
        // lista dejaría de ser lista, que es justo lo que el tipo `list<...>` promete que no pasa.
        self::assertSame([0, 1], array_keys($filas));
    }

    public function testUnaRespuestaConErrorNoRompeYDevuelveVacio(): void
    {
        $manager = $this->manager([new MockResponse('', ['http_code' => 503])]);

        self::assertSame([], $this->llamar($manager, 'callApi', ['date' => '2026-08-15']));
    }

    /**
     * Que un 404 GRITE. Es la línea que faltaba y costó quince días de deriva silenciosa.
     *
     * `HttpClient` no lanza ante un 404 ni un 401: son respuestas válidas, así que el `catch` del
     * método no los ve y salían de ahí como un `[]` indistinguible de «hoy no hay cotización».
     * Con `findLastAvailableInDb()` sirviendo la última tasa buena, el proveedor se mudó el
     * 26/08/2026 y no se supo hasta el 10/09: 49 cargos, 20 pagos y 18 fichas sellados con una
     * tasa de dos semanas antes.
     *
     * Se comprueba el `error()`, no el valor de vuelta: seguir devolviendo `[]` es correcto —un
     * problema con la cotización no puede impedir anotar un cobro que ya se recibió—. Lo que no
     * era correcto es hacerlo callando.
     */
    public function testUn404DejaRastroEnElLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('HTTP 404'));

        $manager = $this->manager([new MockResponse('{"message":"Not found"}', ['http_code' => 404])], $logger);

        self::assertSame([], $this->llamar($manager, 'callApi', ['date' => '2026-09-10']));
    }

    public function testLasCotizacionesSeIndexanPorFechaYLasIncompletasSeSaltan(): void
    {
        $manager = $this->manager([]);

        /** @var array<string, ExchangeRateDto> $dtos */
        $dtos = $this->llamar($manager, 'parseResponse', [
            ['date' => '2026-08-14T00:00:00', 'buy_price' => '3.518', 'sell_price' => '3.528'],
            ['date' => '2026-08-15', 'buy_price' => '3.520'],  // sin sell_price: se salta
            ['date' => '2026-08-16', 'buy_price' => '3.522', 'sell_price' => '3.532', 'base_currency' => 'EUR'],
        ]);

        self::assertSame(['2026-08-14', '2026-08-16'], array_keys($dtos));

        // La fecha llega con hora y se recorta a `Y-m-d`: es la clave con la que `findBestMatch()`
        // busca después, y con la hora pegada no encontraría nunca.
        self::assertSame('2026-08-14', $dtos['2026-08-14']->date->format('Y-m-d'));
        self::assertSame('3.528', $dtos['2026-08-14']->sell);

        // La moneda se conserva tal cual viene (`base_currency`); es `persistMonthData()` quien
        // filtra por USD.
        self::assertSame('EUR', $dtos['2026-08-16']->currencyCode);
        self::assertSame('USD', $dtos['2026-08-14']->currencyCode, 'Sin `base_currency`, se asume el target.');
    }
}
