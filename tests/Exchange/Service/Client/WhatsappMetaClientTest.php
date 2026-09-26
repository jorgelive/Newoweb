<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Service\Client;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Exchange\Service\Mapping\MappingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La fila normalizada que el cliente de Meta devuelve por cada mensaje del lote, y el motivo de un
 * rechazo de plantilla. La fila se comparó contra los 6 201 cuerpos guardados en producción
 * (`tools/pruebas/probar-dto-canales.php`); aquí quedan fijadas sus dos formas.
 */
#[CoversClass(WhatsappMetaClient::class)]
final class WhatsappMetaClientTest extends TestCase
{
    public function testCadaCuerpoDeMetaSeNormalizaEnSuFila(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"messaging_product":"whatsapp","messages":[{"id":"wamid.OK"}]}'),
            new MockResponse('{"error":{"message":"(#132000) Number of parameters does not match","code":132000}}', ['http_code' => 400]),
        ]);

        $resultado = (new WhatsappMetaClient($http))->send(new MappingResult(
            'POST',
            'https://graph.facebook.com/v22.0/123/messages',
            [['to' => 'a'], ['to' => 'b']],
            $this->config(),
            [],
        ));

        self::assertSame('success', $resultado->decodedData[0]['status'] ?? null);
        self::assertSame('wamid.OK', $resultado->decodedData[0]['messageId'] ?? null);
        self::assertSame(
            ['status' => 'error', 'message' => '(#132000) Number of parameters does not match', 'error_code' => 132000],
            $resultado->decodedData[1],
        );
        // La auditoría guarda los cuerpos crudos de Meta, no las filas.
        self::assertStringContainsString('"code":132000', $resultado->rawBody);
    }

    /**
     * Las filas llevan la clave del LOTE que trae el payload, no una numeración nueva: la
     * estrategia las cruza con su correlación por esa clave, y un ítem apartado deja un hueco.
     */
    public function testLasFilasConservanLaClaveDelLote(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"messages":[{"id":"wamid.B"}]}'),
            new MockResponse('{"messages":[{"id":"wamid.C"}]}'),
        ]);

        $resultado = (new WhatsappMetaClient($http))->send(new MappingResult(
            'POST',
            'https://graph.facebook.com/v22.0/123/messages',
            [1 => ['to' => 'b'], 2 => ['to' => 'c']],
            $this->config(),
            [],
        ));

        self::assertSame([1, 2], array_keys($resultado->decodedData));
        self::assertSame('wamid.B', $resultado->decodedData[1]['messageId'] ?? null);
    }

    /** Una respuesta que no es el JSON de Meta no dice si salió: no es un envío, y no se reintenta. */
    public function testUnaRespuestaIlegibleEsSinConfirmacion(): void
    {
        $http = new MockHttpClient([new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502])]);

        $resultado = (new WhatsappMetaClient($http))->send(new MappingResult(
            'POST',
            'https://graph.facebook.com/v22.0/123/messages',
            [['to' => 'a']],
            $this->config(),
            [],
        ));

        self::assertSame('error', $resultado->decodedData[0]['status'] ?? null);
        self::assertStringStartsWith(WhatsappMetaClient::SIN_CONFIRMACION, (string) ($resultado->decodedData[0]['message'] ?? ''));
    }

    public function testElRechazoDeUnaPlantillaDiceElMotivoLegibleYLosDetalles(): void
    {
        $http = new MockHttpClient(new MockResponse(
            '{"error":{"message":"Invalid parameter","error_user_msg":"No se puede cambiar el estado","error_data":{"details":"hsm"}}}',
            ['http_code' => 400],
        ));
        $endpoint = (new ExchangeEndpoint())->setEndpoint('{wabaId}/message_templates')->setMetodo('POST');

        $this->expectExceptionMessage('Invalid parameter | No se puede cambiar el estado | Detalles: hsm');

        (new WhatsappMetaClient($http))->pushTemplateDefinition($this->config(), $endpoint, ['name' => 'x']);
    }

    private function config(): MetaConfig
    {
        return (new MetaConfig())->setApiKey('clave')->setWabaId('waba')->setBaseUrl('https://graph.facebook.com/v22.0');
    }
}
