<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Agent\Service\RespuestaHablada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El modelo cierra la conversación por voz con una marca que nadie debe oír. Ver docs/AgentVoz.md §4.
 */
#[CoversClass(RespuestaHablada::class)]
final class RespuestaHabladaTest extends TestCase
{
    #[Test]
    public function sin_marca_sigue_escuchando_y_el_texto_queda_igual(): void
    {
        $r = RespuestaHablada::delModelo('¿Para dos personas?');

        self::assertFalse($r->cierra);
        self::assertSame('¿Para dos personas?', $r->texto);
    }

    #[Test]
    public function con_marca_cierra_y_la_marca_no_se_dice(): void
    {
        $r = RespuestaHablada::delModelo('Perfecto, hasta luego. [[FIN]]');

        self::assertTrue($r->cierra);
        self::assertSame('Perfecto, hasta luego.', $r->texto);
    }

    /** Si una variante se colara, Alexa leería «corchete fin corchete» en voz alta. */
    #[Test]
    public function las_variantes_que_escribe_un_modelo_tambien_se_quitan(): void
    {
        self::assertSame('Hasta luego.', RespuestaHablada::delModelo('Hasta luego. [FIN]')->texto);
        self::assertSame('Hasta luego.', RespuestaHablada::delModelo('Hasta luego. [[ fin ]]')->texto);
        self::assertTrue(RespuestaHablada::delModelo('Hasta luego. [fin]')->cierra);
    }

    #[Test]
    public function una_marca_sola_se_despide_igual(): void
    {
        $r = RespuestaHablada::delModelo('[[FIN]]');

        self::assertTrue($r->cierra);
        self::assertSame('Hasta luego.', $r->texto, 'el altavoz tiene que decir algo');
    }
}
