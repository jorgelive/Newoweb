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

    /**
     * Botones de verdad dentro de la ventana (01/10/2026): los de respuesta de la plantilla, con el
     * mismo id que su payload, mientras quepan en un mensaje interactivo de Meta.
     */
    public function testLosBotonesDeRespuestaSalenComoBotonesDeVerdad(): void
    {
        $botones = [
            $this->boton('quick_reply', 'CMD_SALGO_10', ['es' => 'Salgo a las 10:00']),
            $this->boton('quick_reply', 'CMD_SALGO_ANTES', ['es' => 'Saldré antes']),
            $this->boton('url', 'guide_path', ['es' => 'Instrucciones de salida']),
        ];

        self::assertSame([
            ['type' => 'reply', 'reply' => ['id' => 'CMD_SALGO_10', 'title' => 'Salgo a las 10:00']],
            ['type' => 'reply', 'reply' => ['id' => 'CMD_SALGO_ANTES', 'title' => 'Saldré antes']],
        ], $this->privado('botonesReales', $botones, 'es'));
    }

    /** Si no caben, `null`: vuelve la botonera numerada. Nunca se recorta un texto. */
    public function testSiNoCabenVuelveLaBotoneraNumerada(): void
    {
        $largo = [$this->boton('quick_reply', 'CMD_X', ['es' => 'Necesito un poco más de tiempo'])];
        $cuatro = array_map(fn (int $i) => $this->boton('quick_reply', 'CMD_' . $i, ['es' => 'Opción ' . $i]), [1, 2, 3, 4]);
        $sinTraduccion = [$this->boton('quick_reply', 'CMD_X', ['en' => 'Leave at 10'])];
        $soloEnlaces = [$this->boton('url', 'guide_path', ['es' => 'Ver mi guía'])];

        self::assertNull($this->privado('botonesReales', $largo, 'es'), 'más de 20 caracteres');
        self::assertNull($this->privado('botonesReales', $cuatro, 'es'), 'más de 3');
        self::assertNull($this->privado('botonesReales', $sinTraduccion, 'es'), 'sin texto en su idioma');
        self::assertNull($this->privado('botonesReales', $soloEnlaces, 'es'), 'sólo enlaces: no hay botones que pulsar');
    }

    /** El enlace va en el texto, y sólo si el texto no lo trae ya. */
    public function testElEnlaceVaEnElTextoSinRepetirse(): void
    {
        $botones = [$this->boton('url', 'guide_path', ['es' => 'Ver mi guía'])];
        $variables = ['guide_url' => 'https://pax.openperu.pe/g/ABC'];

        self::assertSame(
            "¿A qué hora sales?\n\n🔗 *Ver mi guía*:\nhttps://pax.openperu.pe/g/ABC",
            $this->privado('conEnlacesQueFalten', '¿A qué hora sales?', $botones, $variables, 'es')
        );
        self::assertSame(
            'Tu guía: https://pax.openperu.pe/g/ABC',
            $this->privado('conEnlacesQueFalten', 'Tu guía: https://pax.openperu.pe/g/ABC', $botones, $variables, 'es')
        );
    }

    /**
     * @param array<string, string> $textos idioma => texto
     *
     * @return array<string, mixed>
     */
    private function boton(string $tipo, string $clave, array $textos): array
    {
        $traducciones = [];
        foreach ($textos as $idioma => $texto) {
            $traducciones[] = ['language' => $idioma, 'content' => $texto];
        }

        return ['type' => $tipo, 'resolver_key' => $clave, 'button_text' => $traducciones];
    }

    private function privado(string $metodo, mixed ...$argumentos): mixed
    {
        return (new \ReflectionMethod(WhatsappMetaSendMappingStrategy::class, $metodo))->invoke($this->estrategia(), ...$argumentos);
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
