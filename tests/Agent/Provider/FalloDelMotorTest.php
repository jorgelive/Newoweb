<?php

declare(strict_types=1);

namespace App\Tests\Agent\Provider;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Agent\Conversation\FalloDelMotor;
use App\Agent\Conversation\MotivoDefinitivo;
use App\Agent\Provider\Anthropic\AnthropicEngine;
use App\Agent\Provider\DeepSeek\DeepSeekClient;
use App\Agent\Provider\Google\GoogleAIClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Qué fallo es DEFINITIVO, con lo que de verdad manda cada proveedor (23/10/2026).
 *
 * Los dos de «sin saldo» son respuestas REALES que pegó Jorge: Gemini y DeepSeek. El resto sale de la
 * documentación de cada uno (tabla en docs/Agent.md, «Qué manda cada motor cuando falla»). Hasta ese
 * día lo decidía una expresión regular sobre el texto, que no veía el 402 de DeepSeek y sí habría
 * sacado a Gemini por un 429 de ritmo que remite a «billing details».
 */
final class FalloDelMotorTest extends TestCase
{
    /** Real, 23/10/2026. Ojo al `status`: el mismo que el de un 429. */
    private const string GEMINI_SIN_CREDITOS = <<<'JSON'
        {"error":{"code":402,"message":"Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing. Learn more at https://ai.google.dev/gemini-api/docs/billing#prepay.","status":"RESOURCE_EXHAUSTED"}}
        JSON;

    /** Real, 23/10/2026. */
    private const string DEEPSEEK_SIN_SALDO = <<<'JSON'
        {"error":{"message":"Insufficient Balance","type":"insufficient_balance_error","param":null,"code":"insufficient_balance"}}
        JSON;

    private static function deGoogle(int $codigo, string $cuerpo): FalloDelMotor
    {
        $cliente = new GoogleAIClient(new MockHttpClient([new MockResponse($cuerpo, ['http_code' => $codigo])]), 'clave', 'gemini-3.8-flash', esperaEntreIntentosMs: 0);

        try {
            $cliente->generarContenido('gemini-3.8-flash', []);
        } catch (FalloDelMotor $e) {
            return $e;
        }

        self::fail('Tenía que fallar.');
    }

    private static function deDeepSeek(int $codigo, string $cuerpo): FalloDelMotor
    {
        $cliente = new DeepSeekClient(new MockHttpClient([new MockResponse($cuerpo, ['http_code' => $codigo])]), 'clave', 'deepseek-chat');

        try {
            $cliente->completar([]);
        } catch (FalloDelMotor $e) {
            return $e;
        }

        self::fail('Tenía que fallar.');
    }

    private static function deAnthropic(int $codigo, string $tipo, string $mensaje): FalloDelMotor
    {
        $cuerpo = (string) json_encode(['type' => 'error', 'error' => ['type' => $tipo, 'message' => $mensaje], 'request_id' => 'req_011CU402x']);

        return AnthropicEngine::falloDe(APIStatusException::from(new Request('POST', 'https://api.anthropic.com/v1/messages'), new Response($codigo, [], $cuerpo)));
    }

    #[Test]
    public function gemini_sin_creditos_es_definitivo_por_el_codigo(): void
    {
        $fallo = self::deGoogle(402, self::GEMINI_SIN_CREDITOS);

        self::assertSame(MotivoDefinitivo::CREDITOS, $fallo->definitivo);
        self::assertSame(402, $fallo->estadoHttp);
        self::assertStringStartsWith('Google AI 402: Your prepayment credits are depleted.', $fallo->getMessage());
    }

    #[Test]
    public function gemini_429_que_habla_de_billing_no_saca_a_nadie(): void
    {
        $fallo = self::deGoogle(429, '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.","status":"RESOURCE_EXHAUSTED"}}');

        self::assertFalse($fallo->esDefinitivo(), 'Un límite de ritmo se repone solo.');
    }

    #[Test]
    public function deepseek_sin_saldo_es_definitivo_y_lleva_su_codigo(): void
    {
        $fallo = self::deDeepSeek(402, self::DEEPSEEK_SIN_SALDO);

        self::assertSame(MotivoDefinitivo::CREDITOS, $fallo->definitivo);
        self::assertSame('DeepSeek 402: Insufficient Balance', $fallo->getMessage());
    }

    #[Test]
    public function deepseek_clave_mala_es_la_clave(): void
    {
        self::assertSame(MotivoDefinitivo::CLAVE, self::deDeepSeek(401, '{"error":{"message":"Authentication Fails, Your api key: ****abcd is invalid"}}')->definitivo);
    }

    #[Test]
    public function anthropic_sin_credito_llega_como_400_y_es_definitivo(): void
    {
        $fallo = self::deAnthropic(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.');

        self::assertSame(MotivoDefinitivo::CREDITOS, $fallo->definitivo);
    }

    #[Test]
    public function anthropic_por_codigo_y_un_400_de_formato_no_saca_a_nadie(): void
    {
        self::assertSame(MotivoDefinitivo::CLAVE, self::deAnthropic(401, 'authentication_error', 'invalid x-api-key')->definitivo);
        self::assertSame(MotivoDefinitivo::CREDITOS, self::deAnthropic(402, 'billing_error', 'billing issue')->definitivo);
        self::assertNull(self::deAnthropic(429, 'rate_limit_error', 'Number of request tokens has exceeded your per-minute rate limit')->definitivo);
        // Un 400 de formato es un fallo NUESTRO: no se arregla recargando nada.
        self::assertNull(self::deAnthropic(400, 'invalid_request_error', 'messages.0: unexpected role')->definitivo);
    }

    #[Test]
    public function un_fallo_envuelto_se_sigue_reconociendo(): void
    {
        $envuelto = new \RuntimeException('Turno fallido', 0, FalloDelMotor::porCodigoHttp('DeepSeek 402: Insufficient Balance', 402));

        self::assertTrue(FalloDelMotor::en($envuelto)?->esDefinitivo());
        self::assertFalse(FalloDelMotor::de(new \RuntimeException('timeout'))->esDefinitivo());
    }
}
