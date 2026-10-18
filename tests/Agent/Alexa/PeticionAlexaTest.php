<?php

declare(strict_types=1);

namespace App\Tests\Agent\Alexa;

use App\Agent\Alexa\PeticionAlexa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El sobre de Alexa leído con `Lee`: lo mismo que los `(string)` de antes con lo que Amazon manda de
 * verdad, y «no llegó» en vez de «Array» con lo que no.
 *
 * Ver docs/AgentVoz.md §2.
 */
#[CoversClass(PeticionAlexa::class)]
final class PeticionAlexaTest extends TestCase
{
    public function testUnaPeticionDeIntentCompleta(): void
    {
        $p = PeticionAlexa::fromArray([
            'version' => '1.0',
            'session' => [
                'new' => false,
                'sessionId' => 'amzn1.echo-api.session.S1',
                'application' => ['applicationId' => 'amzn1.ask.skill.VIEJO'],
                'attributes' => ['historial' => [
                    ['rol' => 'usuario', 'texto' => ' quiénes salen hoy '],
                    ['rol' => 'asistente', 'texto' => 'Sale la casita 3.'],
                    ['rol' => 'sistema', 'texto' => 'no vale'],
                    ['rol' => 'usuario', 'texto' => ['no', 'es', 'texto']],
                    'basura',
                ]],
                'user' => ['userId' => 'amzn1.ask.account.SESION'],
            ],
            'context' => ['System' => [
                'application' => ['applicationId' => 'amzn1.ask.skill.X'],
                'user' => ['userId' => 'amzn1.ask.account.CU'],
                'device' => ['deviceId' => 'amzn1.ask.device.DV'],
                'person' => ['personId' => 'amzn1.ask.person.AB'],
                'apiEndpoint' => 'https://api.amazonalexa.com',
                'apiAccessToken' => 'Atza|secreto',
            ]],
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.R',
                'locale' => 'es-US',
                'timestamp' => '2026-09-17T18:00:00Z',
                'intent' => ['name' => 'ConsultarIntent', 'confirmationStatus' => 'NONE', 'slots' => [
                    'consulta' => ['name' => 'consulta', 'value' => ' quiénes salen mañana '],
                ]],
            ],
        ]);

        self::assertTrue($p->esIntent());
        self::assertSame('ConsultarIntent', $p->intent);
        self::assertSame('quiénes salen mañana', $p->consulta);
        self::assertSame('amzn1.ask.skill.X', $p->applicationId, 'context manda sobre session');
        self::assertSame('amzn1.ask.account.CU', $p->usuarioAlexa);
        self::assertSame(['amzn1.ask.person.AB', 'amzn1.ask.device.DV', 'amzn1.ask.account.CU'], $p->identidades());
        self::assertSame('2026-09-17T18:00:00Z', $p->timestamp);
        self::assertSame('es-US', $p->idioma);
        self::assertSame('amzn1.echo-api.session.S1', $p->sesion);
        self::assertFalse($p->sesionNueva);
        self::assertSame('https://api.amazonalexa.com', $p->apiEndpoint);
        self::assertSame('Atza|secreto', $p->apiToken);
        self::assertSame([
            ['rol' => 'usuario', 'texto' => 'quiénes salen hoy'],
            ['rol' => 'asistente', 'texto' => 'Sale la casita 3.'],
        ], $p->historial(10));
    }

    /** Un lanzamiento sin `context` (las peticiones viejas) cae a `session`. */
    public function testSinContextSeLeeDeLaSesion(): void
    {
        $p = PeticionAlexa::fromArray([
            'session' => ['new' => true, 'application' => ['applicationId' => 'amzn1.ask.skill.X'], 'user' => ['userId' => 'amzn1.ask.account.CU']],
            'request' => ['type' => 'LaunchRequest'],
        ]);

        self::assertTrue($p->esLanzamiento());
        self::assertTrue($p->sesionNueva);
        self::assertNull($p->intent);
        self::assertSame('amzn1.ask.skill.X', $p->applicationId);
        self::assertSame('amzn1.ask.account.CU', $p->usuarioAlexa);
        self::assertNull($p->apiToken);
        self::assertNull($p->timestamp);
    }

    /** Lo que no es texto no llega: antes, un array era «Array» y un aviso. */
    public function testLoQueNoEsTextoNoLlega(): void
    {
        $p = PeticionAlexa::fromArray([
            'request' => ['type' => ['IntentRequest'], 'timestamp' => ['x'], 'intent' => ['name' => ['n']]],
            'context' => ['System' => ['apiEndpoint' => '', 'apiAccessToken' => 12]],
        ]);

        self::assertSame('', $p->tipo);
        self::assertNull($p->timestamp);
        self::assertNull($p->intent);
        self::assertNull($p->apiEndpoint, 'vacío es no tenerlo');
        self::assertNull($p->apiToken);
    }

    /**
     * El «sí»/«no» que contesta una repregunta. Antes un `AMAZON.NoIntent` llegaba sin consulta y
     * se contestaba «no te he entendido»; y como portadora de `ConsultaIntent`, «no, la casita 5»
     * habría llegado como «la casita 5».
     */
    public function testUnSiOUnNoLleganComoTextoConLaPalabraDelante(): void
    {
        $intent = static fn (string $nombre, ?string $valor = null): PeticionAlexa => PeticionAlexa::fromArray([
            'request' => ['type' => 'IntentRequest', 'intent' => ['name' => $nombre, 'slots' => $valor === null ? [] : [
                'consulta' => ['name' => 'consulta', 'value' => $valor],
            ]]],
        ]);

        self::assertSame('sí', $intent('AMAZON.YesIntent')->consulta);
        self::assertSame('no', $intent('AMAZON.NoIntent')->consulta);
        self::assertSame('no, la casita 5', $intent('NegacionIntent', 'la casita 5')->consulta);
        self::assertSame('sí, para cuatro', $intent('AfirmacionIntent', 'para cuatro')->consulta);
        self::assertSame('dos personas', $intent('ConsultaIntent', 'dos personas')->consulta, 'el resto, tal cual');

        $cantidad = PeticionAlexa::fromArray(['request' => ['type' => 'IntentRequest', 'intent' => ['name' => 'CantidadIntent', 'slots' => [
            'cantidad' => ['name' => 'cantidad', 'value' => '4'],
            'unidad' => ['name' => 'unidad', 'value' => 'personas'],
        ]]]]);
        self::assertSame('4 personas', $cantidad->consulta, 'los dos slots, no sólo el número');
    }
}
