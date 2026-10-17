<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Skill\ControlDeVueltas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Las protecciones del bucle de herramientas que comparten los tres motores. Ver docs/Agent.md §5.5.
 */
#[CoversClass(ControlDeVueltas::class)]
final class ControlDeVueltasTest extends TestCase
{
    public function testLaMismaLlamadaNoSeRepiteYOtrosArgumentosSi(): void
    {
        $control = new ControlDeVueltas();

        self::assertFalse($control->yaServida('consultar_padron', ['subgrupo' => '6']));
        self::assertTrue($control->yaServida('consultar_padron', ['subgrupo' => '6']));
        self::assertFalse($control->yaServida('consultar_padron', ['subgrupo' => '7']));
    }

    public function testTresVueltasSeguidasFallandoCierran(): void
    {
        $control = new ControlDeVueltas();

        foreach ([1, 2] as $_) {
            $control->registrar(['error_de_nombre' => 'no existe']);
            self::assertNull($control->finDeVuelta());
        }

        $control->registrar(['error' => 'no']);
        self::assertNotNull($control->finDeVuelta(), 'La tercera vuelta de fallos cierra el turno.');
    }

    public function testUnaVueltaQueSaleBienReiniciaLaRacha(): void
    {
        $control = new ControlDeVueltas();

        $control->registrar(['error' => 'no']);
        $control->finDeVuelta();
        $control->registrar(['error' => 'no']);
        $control->finDeVuelta();

        // Una con algo bueno —aunque otra de la misma vuelta falle— reinicia.
        $control->registrar(['error' => 'no']);
        $control->registrarJson('{"total": 3}');
        self::assertNull($control->finDeVuelta());

        $control->registrar(['error' => 'no']);
        self::assertNull($control->finDeVuelta(), 'La racha volvió a empezar.');
    }

    public function testUnaVueltaDeSoloRepetidasCuentaComoFallo(): void
    {
        // En la vuelta no se ejecutó nada: es el bucle que se persigue.
        $control = new ControlDeVueltas();

        self::assertNull($control->finDeVuelta());
        self::assertNull($control->finDeVuelta());
        self::assertNotNull($control->finDeVuelta());
    }

    public function testUnJsonIlegibleCuentaComoFallo(): void
    {
        $control = new ControlDeVueltas();

        foreach ([1, 2, 3] as $vuelta) {
            $control->registrarJson('no es json');
            $motivo = $control->finDeVuelta();
        }

        self::assertNotNull($motivo);
    }

    public function testLaUltimaVueltaEsLaOctava(): void
    {
        self::assertFalse(ControlDeVueltas::esLaUltima(6));
        self::assertTrue(ControlDeVueltas::esLaUltima(ControlDeVueltas::MAX_VUELTAS - 1));
    }
}
