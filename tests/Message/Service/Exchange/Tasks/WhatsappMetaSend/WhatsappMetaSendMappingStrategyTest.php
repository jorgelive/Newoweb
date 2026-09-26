<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Mapping\MappingResult;
use App\Message\Service\Exchange\Tasks\WhatsappMetaSend\WhatsappMetaSendMappingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🔥 **Fija un fallo conocido, no un comportamiento deseado.** La estrategia busca `error` y
 * `messages` en la fila que ya normalizó `WhatsappMetaClient::send()`, donde no existen: un rechazo
 * síncrono de Meta se da por ENVIADO. Al tipar la respuesta se conservó tal cual —arreglarlo cambia
 * reintentos y estados, y es una decisión aparte—, y este test existe para que ese cambio, cuando
 * se haga, se haga a propósito y no de rebote. Ver `docs/Mensajeria.md` §14.c.
 */
#[CoversClass(WhatsappMetaSendMappingStrategy::class)]
final class WhatsappMetaSendMappingStrategyTest extends TestCase
{
    public function testHoyUnRechazoDeMetaSeDaPorEnviado(): void
    {
        $resultados = $this->estrategia()->parseResponse(
            [['status' => 'error', 'message' => '(#132005) Translated text too long', 'error_code' => 132005]],
            $this->mapeo(),
        );

        self::assertTrue($resultados['cola-1']->success);
        self::assertNull($resultados['cola-1']->remoteId);
    }

    /** El `wamid` no lo recoge la estrategia sino el handler, de `messageId` en `extraData`. */
    public function testLaFilaPasaEnteraAlHandler(): void
    {
        $fila = ['status' => 'success', 'messageId' => 'wamid.OK', 'raw' => []];

        $resultados = $this->estrategia()->parseResponse([$fila], $this->mapeo());

        self::assertSame($fila, $resultados['cola-1']->extraData);
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
