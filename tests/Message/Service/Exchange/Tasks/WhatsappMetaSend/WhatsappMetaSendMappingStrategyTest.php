<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Mapping\MappingResult;
use App\Message\Service\Exchange\Tasks\WhatsappMetaSend\WhatsappMetaSendMappingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La estrategia lee la fila que ya normalizó `WhatsappMetaClient::send()`, no el cuerpo de Meta.
 * Hasta el 26/09/2026 buscaba ahí las claves del cuerpo, que no existen, y un rechazo síncrono de
 * Meta se daba por ENVIADO. Ver `docs/Mensajeria.md` §14.c.
 */
#[CoversClass(WhatsappMetaSendMappingStrategy::class)]
final class WhatsappMetaSendMappingStrategyTest extends TestCase
{
    public function testUnRechazoDeMetaEsUnFalloConSuCodigo(): void
    {
        $resultados = $this->estrategia()->parseResponse(
            [['status' => 'error', 'message' => '(#132005) Translated text too long', 'error_code' => 132005]],
            $this->mapeo(),
        );

        self::assertFalse($resultados['cola-1']->success);
        self::assertNull($resultados['cola-1']->remoteId);
        self::assertSame('[Meta 132005] (#132005) Translated text too long', $resultados['cola-1']->message);
    }

    /** Un fallo de red no trae código: el motivo va solo. */
    public function testUnFalloSinCodigoLlevaSoloElMotivo(): void
    {
        $resultados = $this->estrategia()->parseResponse(
            [['status' => 'error', 'message' => 'HTTP Exception: timeout']],
            $this->mapeo(),
        );

        self::assertFalse($resultados['cola-1']->success);
        self::assertSame('HTTP Exception: timeout', $resultados['cola-1']->message);
    }

    public function testUnEnvioAceptadoEsExitoConSuWamid(): void
    {
        $resultados = $this->estrategia()->parseResponse(
            [['status' => 'success', 'messageId' => 'wamid.OK', 'raw' => []]],
            $this->mapeo(),
        );

        self::assertTrue($resultados['cola-1']->success);
        self::assertSame('wamid.OK', $resultados['cola-1']->remoteId);
        self::assertNull($resultados['cola-1']->message);
    }

    /** El `wamid` no lo recoge la estrategia sino el handler, de `messageId` en `extraData`. */
    public function testLaFilaPasaEnteraAlHandler(): void
    {
        $fila = ['status' => 'success', 'messageId' => 'wamid.OK', 'raw' => []];

        $resultados = $this->estrategia()->parseResponse([$fila], $this->mapeo());

        self::assertSame($fila, $resultados['cola-1']->extraData);
    }

    /**
     * Con un ítem apartado delante (clave 0), las respuestas de B y C van a B y a C. Antes el
     * payload se numeraba aparte y la respuesta de C se le atribuía a B: un envío aceptado podía
     * contarse como rechazo y reenviarse.
     */
    public function testCadaRespuestaVaASuColaAunqueFalteUnItemDelante(): void
    {
        $mapeo = new MappingResult('POST', 'https://graph.facebook.com/v22.0/1/messages', [], new MetaConfig(), [1 => 'cola-B', 2 => 'cola-C']);

        $resultados = $this->estrategia()->parseResponse([
            1 => ['status' => 'success', 'messageId' => 'wamid.B', 'raw' => []],
            2 => ['status' => 'error', 'message' => '(#132005) Translated text too long', 'error_code' => 132005],
        ], $mapeo);

        self::assertTrue($resultados['cola-B']->success);
        self::assertSame('wamid.B', $resultados['cola-B']->remoteId);
        self::assertFalse($resultados['cola-C']->success);
    }

    private function estrategia(): WhatsappMetaSendMappingStrategy
    {
        // `parseResponse()` no usa ninguna dependencia: las del constructor son para `map()`.
        return (new \ReflectionClass(WhatsappMetaSendMappingStrategy::class))->newInstanceWithoutConstructor();
    }

    private function mapeo(): MappingResult
    {
        return new MappingResult('POST', 'https://graph.facebook.com/v22.0/1/messages', [], new MetaConfig(), [0 => 'cola-1']);
    }
}
