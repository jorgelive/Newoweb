<?php

declare(strict_types=1);

namespace App\Tests\Agent\Alexa;

use App\Agent\Alexa\DiagnosticoAlexa;
use App\Agent\Alexa\PeticionAlexa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lo que tiene que sobrevivir de una petición de voz, y lo que no puede acabar en el log.
 *
 * Ver docs/AgentVoz.md §5.2.
 */
#[CoversClass(DiagnosticoAlexa::class)]
final class DiagnosticoAlexaTest extends TestCase
{
    public function testElSobreSaneadoPierdeLosTokensYTachaLosCodigosDictados(): void
    {
        $limpio = DiagnosticoAlexa::sanear([
            'context' => [
                'System' => [
                    'apiAccessToken' => 'Atza|secreto',
                    'person' => ['personId' => 'amzn1.ask.person.AB', 'accessToken' => 'Atza|otro'],
                ],
            ],
            'request' => ['intent' => ['slots' => ['consulta' => ['value' => 'el código es 481596']]]],
        ]);

        self::assertSame('(omitido)', $limpio['context']['System']['apiAccessToken']);
        self::assertSame('(omitido)', $limpio['context']['System']['person']['accessToken']);
        self::assertSame('amzn1.ask.person.AB', $limpio['context']['System']['person']['personId']);
        self::assertSame('el código es ····', $limpio['request']['intent']['slots']['consulta']['value']);
    }

    public function testRegistraElNombreDelPerfilDeVozQueAmazonDevuelve(): void
    {
        $log = $this->registrar(new MockHttpClient(new MockResponse('"Jorge"')));

        self::assertStringContainsString('voz_nombre=Jorge', $log);
        self::assertStringContainsString('voz_nombre_http=200', $log);
        self::assertStringContainsString('sesión=…essionABCDEF', $log);
        self::assertStringContainsString('idioma=es-US', $log);
    }

    /**
     * Sin el permiso «Given Name» Amazon contesta 403, y eso es una pista de configuración, no un
     * fallo: la línea sale igual, con el código a la vista.
     */
    public function testSinPermisoLaLineaSaleConElCodigoDeAmazon(): void
    {
        $log = $this->registrar(new MockHttpClient(new MockResponse('', ['http_code' => 403])));

        self::assertStringContainsString('voz_nombre=—', $log);
        self::assertStringContainsString('voz_nombre_http=403', $log);
        self::assertStringContainsString('persona=amzn1.ask.person.AB', $log);
    }

    /** Un fallo del diagnóstico no puede dejar sin línea —ni sin respuesta— a la petición. */
    public function testUnErrorDeRedNoRompeElRegistro(): void
    {
        $log = $this->registrar(new MockHttpClient(static function (): never {
            throw new \RuntimeException('se cayó la red');
        }));

        self::assertStringContainsString('voz_nombre_http=error: se cayó la red', $log);
    }

    private function registrar(MockHttpClient $http): string
    {
        $logger = new class extends AbstractLogger {
            public string $texto = '';

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->texto .= (string) $message . "\n";
            }
        };

        (new DiagnosticoAlexa($http, $logger))->registrar($this->sobre(), PeticionAlexa::desde($this->sobre()));

        return $logger->texto;
    }

    /**
     * @return array<string, mixed>
     */
    private function sobre(): array
    {
        return [
            'session' => ['sessionId' => 'amzn1.echo-api.session.SessionABCDEF', 'new' => true, 'attributes' => []],
            'context' => [
                'System' => [
                    'application' => ['applicationId' => 'amzn1.ask.skill.X'],
                    'user' => ['userId' => 'amzn1.ask.account.CU'],
                    'device' => ['deviceId' => 'amzn1.ask.device.DV'],
                    'person' => ['personId' => 'amzn1.ask.person.AB'],
                    'apiEndpoint' => 'https://api.amazonalexa.com',
                    'apiAccessToken' => 'Atza|secreto',
                ],
            ],
            'request' => [
                'type' => 'IntentRequest',
                'locale' => 'es-US',
                'timestamp' => '2026-09-17T18:00:00Z',
                'intent' => ['name' => 'ConsultarIntent', 'slots' => ['consulta' => ['value' => 'quiénes salen mañana']]],
            ],
        ];
    }
}
