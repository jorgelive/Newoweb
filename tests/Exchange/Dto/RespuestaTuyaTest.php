<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Exchange\Dto\Tuya\RespuestaTuya;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El sobre de Tuya. No hay respuestas guardadas contra las que comparar —Domótica las convierte al
 * vuelo—, así que los casos van aquí.
 */
#[CoversClass(RespuestaTuya::class)]
final class RespuestaTuyaTest extends TestCase
{
    public function testElExitoEsElBooleanoTrueYNadaMas(): void
    {
        self::assertTrue(RespuestaTuya::fromArray(['success' => true])->exito);
        self::assertFalse(RespuestaTuya::fromArray(['success' => 'true'])->exito);
        self::assertFalse(RespuestaTuya::fromArray([])->exito);
    }

    public function testSeLlegaAlResultadoPorSuRuta(): void
    {
        $r = RespuestaTuya::fromArray(['success' => true, 'result' => ['devices' => [['id' => 'a']]]]);

        self::assertSame([['id' => 'a']], $r->resultado('devices'));
        self::assertSame(['devices' => [['id' => 'a']]], $r->resultado());
        self::assertNull($r->resultado('properties'));
    }

    public function testElMensajeSoloSiEsTexto(): void
    {
        self::assertSame('sign invalid', RespuestaTuya::fromArray(['success' => false, 'msg' => 'sign invalid'])->mensaje);
        self::assertNull(RespuestaTuya::fromArray(['success' => false, 'msg' => 1010])->mensaje);
    }

    public function testElTokenVacioNoEsUnToken(): void
    {
        self::assertSame('tok', RespuestaTuya::fromArray(['success' => true, 'result' => ['access_token' => 'tok']])->token());
        self::assertNull(RespuestaTuya::fromArray(['success' => true, 'result' => ['access_token' => '']])->token());
        self::assertNull(RespuestaTuya::fromArray(['success' => true, 'result' => 'raro'])->token());
    }
}
