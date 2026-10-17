<?php

declare(strict_types=1);

namespace App\Tests\Agent\Alexa;

use App\Agent\Alexa\RespuestaAlexa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La forma que Alexa exige a la respuesta. Un fallo aquí no da error en nuestro lado: Amazon
 * rechaza la respuesta y el altavoz dice que la skill no responde.
 *
 * Existe porque el renombrado `version` → `propuesta` de cotizaciones (02/09/2026) cambió la
 * clave `version` y el canal de voz estuvo caído seis semanas sin que nada avisara.
 */
#[CoversClass(RespuestaAlexa::class)]
final class RespuestaAlexaTest extends TestCase
{
    public function testLlevaLaVersionDelFormato(): void
    {
        $json = RespuestaAlexa::despedida('Hasta luego')->aArray();

        self::assertSame('1.0', $json['version'] ?? null);
        self::assertArrayNotHasKey('propuesta', $json);
    }

    public function testSinAtributosLaSesionVaComoObjetoNoComoLista(): void
    {
        $json = RespuestaAlexa::despedida('Hasta luego')->aArray();

        self::assertSame('{}', json_encode($json['sessionAttributes'] ?? null));
    }

    public function testSiSigueEscuchandoNoCierraLaSesionYGuardaElHistorial(): void
    {
        $json = RespuestaAlexa::sigueEscuchando('¿Algo más?', [['rol' => 'usuario', 'texto' => 'hola']], '¿Sigues ahí?')->aArray();

        self::assertFalse($json['response']['shouldEndSession'] ?? null);
        self::assertSame('¿Algo más?', $json['response']['outputSpeech']['text'] ?? null);
        self::assertSame([['rol' => 'usuario', 'texto' => 'hola']], $json['sessionAttributes']['historial'] ?? null);
    }
}
