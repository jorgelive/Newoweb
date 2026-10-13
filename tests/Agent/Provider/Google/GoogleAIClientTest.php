<?php

declare(strict_types=1);

namespace App\Tests\Agent\Provider\Google;

use App\Agent\Provider\Google\FalloPasajeroDeGoogle;
use App\Agent\Provider\Google\GoogleAIClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Un tropiezo de Google no es una caída del agente.
 *
 * El 13/10/2026 una sola respuesta en HTML —un 503— bastó para que el huésped recibiera el acuse
 * de cortesía y el equipo un «El agente NO está contestando». Ver `GoogleAIClient::INTENTOS`.
 */
final class GoogleAIClientTest extends TestCase
{
    private function cliente(MockHttpClient $http): GoogleAIClient
    {
        return new GoogleAIClient($http, 'clave', 'gemini-3.7-flash', esperaEntreIntentosMs: 0);
    }

    private static function ok(): MockResponse
    {
        return new MockResponse('{"candidates":[{"content":{"parts":[{"text":"hola"}]}}]}', ['http_code' => 200]);
    }

    #[Test]
    public function un_503_en_html_se_absorbe_con_un_reintento(): void
    {
        $http = new MockHttpClient([
            new MockResponse('<html><body>503 Service Unavailable</body></html>', ['http_code' => 503]),
            self::ok(),
        ]);
        $cliente = $this->cliente($http);

        self::assertArrayHasKey('candidates', $cliente->generarContenido('gemini-3.7-flash', []));
        self::assertSame(2, $http->getRequestsCount());
    }

    #[Test]
    public function si_vuelve_a_fallar_es_una_caida_y_lo_dice_con_su_codigo(): void
    {
        $cliente = $this->cliente(new MockHttpClient([
            new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]),
            new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]),
        ]));

        $this->expectException(FalloPasajeroDeGoogle::class);
        // El código real, no «Syntax error for "https://…"».
        $this->expectExceptionMessageMatches('/^Google AI 502: respuesta que no es JSON/');

        $cliente->generarContenido('gemini-3.7-flash', []);
    }

    #[Test]
    public function un_4xx_no_se_reintenta(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"error":{"message":"Your prepayment credits are depleted"}}', ['http_code' => 402]),
            self::ok(),
        ]);
        $cliente = $this->cliente($http);

        try {
            $cliente->generarContenido('gemini-3.7-flash', []);
            self::fail('Un 402 tenía que lanzar.');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(FalloPasajeroDeGoogle::class, $e);
            self::assertStringContainsString('402', $e->getMessage());
        }

        self::assertSame(1, $http->getRequestsCount(), 'Los créditos agotados no vuelven pidiéndolo otra vez.');
    }
}
